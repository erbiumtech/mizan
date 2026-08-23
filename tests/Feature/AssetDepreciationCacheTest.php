<?php

namespace Tests\Feature;

use App\Modules\Accounting\Console\Commands\RebuildAssetDepreciationCommand;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Services\DepreciationService;
use App\Support\Reporting\ReportRenderers;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The cached depreciation figure, and putting it back in step with the ledger.
 *
 * `fixed_assets.accumulated_depreciation` is a cache `DepreciationService` maintains as it posts. The Fixed
 * Asset Register report reads the *entries* instead — a cache cannot answer an "as at" question — which made
 * it the only thing in the application that sees the two figures side by side, and all it could do was say
 * they disagreed. `rebuildCache()` and `accounting:rebuild-asset-depreciation` are the other half of that.
 *
 * Two claims here, and the second is the one that would go wrong quietly:
 *
 *  - **`bookedFor()` is the single definition of accumulated depreciation.** The report and the repair both
 *    read it, so they cannot disagree about what the ledger says — which is the fault this codebase has
 *    found twice already, in the general ledger's batching and the stocktake's valuation.
 *  - **The status is derived from the figure, not preserved across the repair.** An asset whose cache
 *    overstated what was booked has life left in it, so it goes back to `active` — and a disposed one does
 *    not come back onto the books whatever its figures say.
 *
 * `FixedAssetTest` owns the posting arithmetic and `FixedAssetRegisterReportTest` the report. Neither is
 * restated here.
 */
class AssetDepreciationCacheTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private DepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'assetcache@test.local'));
        $this->setCurrentTenant();

        $this->service = app(DepreciationService::class);
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    /** Straight line, 360k over 36 months — 10,000 a month. */
    private function asset(array $overrides = []): FixedAsset
    {
        return FixedAsset::create(array_merge([
            'name' => 'Laptop',
            'account_id' => Account::where('code', '1400')->firstOrFail()->id,
            'purchase_date' => '2026-07-01',
            'purchase_cost' => 360000,
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 36,
            'salvage_value' => 0,
        ], $overrides));
    }

    private function depreciate(FixedAsset $asset, string ...$months): void
    {
        foreach ($months as $month) {
            $this->service->depreciateAsset($asset->fresh(), Carbon::parse($month), $this->fiscalYear->id);
        }
    }

    /**
     * Leave the company with no 1500 account.
     *
     * Renamed rather than deleted: `journal_entry_lines.account_id` is `restrictOnDelete`, so an account with
     * entries against it cannot be removed at all. Renaming is the state that actually occurs — and it is the
     * one the command's own error message names.
     */
    private function loseTheAccount(): void
    {
        Account::where('code', DepreciationService::ACCUMULATED_CODE)->update(['code' => '1599']);
    }

    /** Put the cache wrong the way a bad backfill or a hand-edited row would leave it. */
    private function corrupt(FixedAsset $asset, float $to, ?string $status = null): FixedAsset
    {
        $asset->forceFill(array_filter([
            'accumulated_depreciation' => $to,
            'status' => $status,
        ]))->saveQuietly();

        return $asset->fresh();
    }

    /**
     * The command's output, run the way the scheduler would.
     *
     * `handle()` directly rather than through `artisan()`, following `ConstructionComplianceTest`'s note: the
     * command is `TenantAware`, and going through the kernel makes it switch tenant databases, which this
     * suite's connection layout cannot do. The tenant is already current either way.
     */
    private function runCommand(bool $dryRun = false): array
    {
        $command = new RebuildAssetDepreciationCommand;
        $command->setLaravel($this->app);

        $input = new ArrayInput($dryRun ? ['--dry-run' => true] : [], new InputDefinition([
            new InputOption('dry-run', null, InputOption::VALUE_NONE),
            new InputOption('tenant', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
        ]));
        $buffer = new BufferedOutput;

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        return [$command->handle($this->service), $buffer->fetch()];
    }

    // ────────────────────────────────────────────────────────────── bookedFor ──

    /** What the ledger says, asset by asset, with the month it was last booked for. */
    public function test_booked_for_reads_the_entries(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15', '2026-08-15');

        $booked = $this->service->bookedFor([$asset->getKey()]);

        $this->assertSame(20_000.0, $booked[$asset->getKey()]['accumulated']);
        $this->assertSame('2026-08-31', $booked[$asset->getKey()]['last_booked']);
    }

    /** A disposal debits 1500 to clear what accumulated, so it nets off on its own. */
    public function test_booked_for_nets_off_a_disposal(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');
        $this->service->dispose($asset->fresh(), Carbon::parse('2026-08-10'));

        $booked = $this->service->bookedFor([$asset->getKey()]);

        $this->assertSame(0.0, $booked[$asset->getKey()]['accumulated']);
    }

    /** It reads up to a date when given one, and everything when not. */
    public function test_booked_for_honours_the_date(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15', '2026-08-15');

        $this->assertSame(
            10_000.0,
            $this->service->bookedFor([$asset->getKey()], '2026-07-31')[$asset->getKey()]['accumulated'],
        );
        $this->assertSame(20_000.0, $this->service->bookedFor([$asset->getKey()])[$asset->getKey()]['accumulated']);
    }

    /**
     * No account is null; no entries is an empty array. The caller has to be able to tell them apart.
     *
     * "I cannot derive this figure" and "the figure is nought" are opposite answers about money, and the
     * report degrades on the first and reconciles on the second.
     */
    public function test_booked_for_distinguishes_no_account_from_no_entries(): void
    {
        $asset = $this->asset();

        $this->assertSame([], $this->service->bookedFor([$asset->getKey()]), 'no entries yet');

        $this->loseTheAccount();

        $this->assertNull($this->service->bookedFor([$asset->getKey()]), 'and now no account at all');
    }

    // ─────────────────────────────────────────────────────────── rebuildCache ──

    /** The cache comes back to what the entries say. */
    public function test_it_rebuilds_the_cache_from_the_entries(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15', '2026-08-15');
        $asset = $this->corrupt($asset, 55000);

        $this->assertSame(20_000.0, $this->service->rebuildCache($asset));
        $this->assertSame(20_000.0, (float) $asset->fresh()->accumulated_depreciation);
        $this->assertSame(340_000.0, $asset->fresh()->book_value);
    }

    /**
     * An asset the cache had written off goes back to active, with life left in it.
     *
     * The half of the repair nobody expects, and the reason the command prints a status column. Being
     * `fully_depreciated` is a statement about the figure, so correcting the figure has to correct the claim —
     * otherwise the repair leaves an asset that will never be depreciated again.
     */
    public function test_a_wrongly_exhausted_asset_goes_back_to_active(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');
        $asset = $this->corrupt($asset, 360000, FixedAsset::STATUS_FULLY_DEPRECIATED);

        $this->assertFalse($asset->isDepreciable(), 'nothing would depreciate it in this state');

        $this->service->rebuildCache($asset);

        $asset->refresh();
        $this->assertSame(FixedAsset::STATUS_ACTIVE, $asset->status);
        $this->assertTrue($asset->isDepreciable());
        // And it really does depreciate again, which is the point of the status being derived.
        $this->assertNotNull($this->service->depreciateAsset($asset, Carbon::parse('2026-08-15')));
    }

    /** And one the entries have exhausted is marked as such, even if the cache said otherwise. */
    public function test_an_exhausted_asset_is_marked_fully_depreciated(): void
    {
        $asset = $this->asset(['purchase_cost' => 20000, 'useful_life_months' => 2]);
        $this->depreciate($asset, '2026-07-15', '2026-08-15');
        $asset = $this->corrupt($asset, 5000, FixedAsset::STATUS_ACTIVE);

        $this->service->rebuildCache($asset);

        $this->assertSame(FixedAsset::STATUS_FULLY_DEPRECIATED, $asset->fresh()->status);
    }

    /** A disposed asset keeps its status: disposal is an event, not a position. */
    public function test_a_disposed_asset_is_not_brought_back(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');
        $this->service->dispose($asset->fresh(), Carbon::parse('2026-08-10'));
        $asset = $this->corrupt($asset->fresh(), 99000);

        $this->service->rebuildCache($asset);

        $asset->refresh();
        $this->assertSame(FixedAsset::STATUS_DISPOSED, $asset->status);
        $this->assertSame(0.0, (float) $asset->accumulated_depreciation, 'the disposal cleared it');
    }

    /**
     * With no account to derive from, the cache is left exactly as it is.
     *
     * "Cannot tell" is not "nothing". Zeroing every figure would be the loudest possible reading of a missing
     * account, and it would destroy the only numbers the company has.
     */
    public function test_it_writes_nothing_when_there_is_no_account_to_derive_from(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');
        $asset = $this->corrupt($asset, 55000);

        $this->loseTheAccount();

        $this->assertNull($this->service->rebuildCache($asset));
        $this->assertSame(55_000.0, (float) $asset->fresh()->accumulated_depreciation);
    }

    // ─────────────────────────────────────────────────────────── the command ──

    /** It names the drift, then fixes it. */
    public function test_the_command_reports_and_repairs(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15', '2026-08-15');
        $this->corrupt($asset, 55000);

        [$status, $output] = $this->runCommand();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('55,000.00', $output, 'what the cache said');
        $this->assertStringContainsString('20,000.00', $output, 'what the entries say');
        $this->assertStringContainsString('35,000.00', $output, 'the difference');
        $this->assertStringContainsString('Rebuilt 1 asset(s)', $output);

        $this->assertSame(20_000.0, (float) $asset->fresh()->accumulated_depreciation);
    }

    /** A dry run writes nothing at all. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');
        $this->corrupt($asset, 55000);

        [$status, $output] = $this->runCommand(dryRun: true);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertSame(55_000.0, (float) $asset->fresh()->accumulated_depreciation, 'untouched');
    }

    /** Running it twice is safe, and the second run says there is nothing to do. */
    public function test_it_is_idempotent(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');
        $this->corrupt($asset, 55000);

        $this->runCommand();
        [$status, $output] = $this->runCommand();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Nothing to do', $output);
        $this->assertSame(10_000.0, (float) $asset->fresh()->accumulated_depreciation);
    }

    /** An asset already in step is not rewritten, so a healthy register reports nothing. */
    public function test_an_asset_in_step_is_left_alone(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');

        [$status, $output] = $this->runCommand();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("every asset's cached depreciation agrees", $output);
        $this->assertStringNotContainsString($asset->asset_code, $output);
    }

    /** No assets at all is not an error. */
    public function test_no_assets_is_not_an_error(): void
    {
        [$status, $output] = $this->runCommand();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('there are no fixed assets', $output);
    }

    /** A missing account fails loudly rather than zeroing everything. */
    public function test_a_missing_account_fails_and_writes_nothing(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15');
        $this->corrupt($asset, 55000);

        $this->loseTheAccount();

        [$status, $output] = $this->runCommand();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('no 1500 accumulated depreciation account', $output);
        $this->assertSame(55_000.0, (float) $asset->fresh()->accumulated_depreciation);
    }

    // ───────────────────────────────────────────── the report and the repair ──

    /**
     * The report stops complaining once the repair has run, and that is the loop closing.
     *
     * Both read `bookedFor()`, so this asserts they share one definition rather than agreeing by luck: the
     * note names a drift, the command fixes the drift, and the note goes quiet.
     */
    public function test_the_report_stops_reporting_drift_once_it_is_repaired(): void
    {
        $asset = $this->asset();
        $this->depreciate($asset, '2026-07-15', '2026-08-15');
        $this->corrupt($asset, 55000);

        $before = ReportRenderers::render('FixedAssetRegister', '2026-08-31', false, []);
        $this->assertStringContainsString('CACHED DEPRECIATION IS OUT BY 35,000.00', $before['note']);

        $this->runCommand();

        $after = ReportRenderers::render('FixedAssetRegister', '2026-08-31', false, []);
        $this->assertStringNotContainsString('CACHED DEPRECIATION IS OUT', $after['note']);
        $this->assertStringContainsString('DEPRECIATION AGREES WITH ACCOUNT 1500', $after['note']);
    }
}
