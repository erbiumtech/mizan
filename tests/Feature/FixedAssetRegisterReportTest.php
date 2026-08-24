<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\FixedAssetRegister;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\DepreciationService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\User;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Fixed Asset Register — `docs/reports-expansion-plan.md` Phase 2.5.
 *
 * `FixedAssetTest` owns the depreciation arithmetic: the straight-line charge, the salvage floor, the status
 * flipping when the base is exhausted, the disposal entry. None of it is restated here.
 *
 * What this file asserts is the four things that are this report's own:
 *
 *  - **It reconciles, on both sides.** Phase 2's rule — "post entries, run the report, assert the record row
 *    equals the ledger balance for the accounts behind it. A row-count assertion proves nothing here." Cost
 *    ties to the asset accounts and depreciation to account 1500, and the tests make them agree, then make
 *    each side disagree on purpose and check the report names the right half.
 *  - **"As at" means as at.** Depreciation is summed from the posted entries rather than read from the
 *    `accumulated_depreciation` cache, which only ever holds today's total. A register drawn to a past date
 *    that read the cache would compare a today figure against an as-at balance and call the gap a
 *    discrepancy.
 *  - **The projection is what will actually be booked.** `DepreciationService::schedule()` walks the model's
 *    own `monthlyDepreciation()` forward, so the test for it is the equivalence: project twelve months, then
 *    book twelve months, and assert they are the same list of figures. Declining balance is where a second
 *    implementation would have drifted, so it is tested in both methods.
 *  - **And it books nothing**, which is the whole reason the method exists.
 */
class FixedAssetRegisterReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-31';

    private DepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'assetreport@test.local'));
        $this->setCurrentTenant();

        $this->service = app(DepreciationService::class);
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** Straight line, 360k over 36 months — 10,000 a month, and no rounding to argue about. */
    private function asset(array $overrides = []): FixedAsset
    {
        return FixedAsset::create(array_merge([
            'name' => 'Laptop',
            'account_id' => $this->account('1400')->id,
            'purchase_date' => '2026-07-01',
            'purchase_cost' => 360000,
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 36,
            'salvage_value' => 0,
        ], $overrides));
    }

    /**
     * The entry that puts the asset's cost in the asset account.
     *
     * The register does not post it — `FixedAsset` has an *optional* `journal_entry_id` and nothing fills it
     * in — so a fixture that skips this is testing a company whose assets are in no account at all. Which is
     * a real state, and the test below for the cost side being out is exactly that state.
     */
    private function postPurchase(FixedAsset $asset): void
    {
        $service = app(JournalEntryService::class);

        $entry = $service->create([
            'entry_date' => $asset->purchase_date->toDateString(),
            'entry_type' => 'general',
            'memo' => 'Purchase of '.$asset->asset_code,
        ], [
            ['account_id' => $asset->account_id, 'debit_amount' => (float) $asset->purchase_cost],
            ['account_id' => $this->account('1100')->id, 'credit_amount' => (float) $asset->purchase_cost],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $service->post($entry->fresh());
    }

    /** Depreciate the given months, in order, the way the register's action does. */
    private function depreciate(FixedAsset $asset, string ...$months): void
    {
        foreach ($months as $month) {
            $this->service->depreciateAsset($asset->fresh(), Carbon::parse($month), $this->fiscalYear->id);
        }
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('FixedAssetRegister', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for FixedAssetRegister');

        return $payload;
    }

    private function row(array $payload, string $name): array
    {
        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $name)) {
                return $row;
            }
        }

        $this->fail("no row for [{$name}] in ".collect($payload['rows'])->flatten()->implode(' | '));
    }

    private function figure(string $cell): float
    {
        return (float) str_replace(',', '', $cell);
    }

    // ─────────────────────────────────────────────────────── the reconciliation ──

    /**
     * Cost ties to the asset accounts and depreciation to 1500, and the record row states the net.
     *
     * The assertion Phase 2 exists for. Two months booked on a 10,000-a-month asset, so every figure in the
     * report has an independent statement of itself in the ledger.
     */
    public function test_the_register_agrees_with_the_asset_and_depreciation_accounts(): void
    {
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15', '2026-08-15');

        $payload = $this->report();
        $row = $this->row($payload, 'Laptop');

        $this->assertSame(360_000.0, $this->figure($row[1]), 'cost');
        $this->assertSame(20_000.0, $this->figure($row[2]), 'two months booked');
        $this->assertSame(340_000.0, $this->figure($row[3]), 'net book value');

        $this->assertSame(340_000.0, $payload['tiles'][0]['value'], 'the register says 340,000');
        $this->assertSame(340_000.0, $payload['tiles'][1]['value'], 'and so do the accounts');

        $this->assertStringContainsString('COST AGREES WITH THE ASSET ACCOUNTS', $payload['note']);
        $this->assertStringContainsString('DEPRECIATION AGREES WITH ACCOUNT 1500', $payload['note']);
    }

    /** An asset in the register whose cost was never posted is a cost-side difference, named as such. */
    public function test_an_unposted_purchase_is_reported_on_the_cost_side(): void
    {
        $posted = $this->asset(['name' => 'Laptop']);
        $this->postPurchase($posted);

        // Entered in the register, never posted to the asset account.
        $this->asset(['name' => 'Printer', 'purchase_cost' => 60000]);

        $payload = $this->report();

        $this->assertStringContainsString('DIFFER BY 60,000.00', $payload['note']);
        $this->assertStringContainsString('THE REGISTER CARRIES COST THE ASSET ACCOUNTS DO NOT', $payload['note']);
        $this->assertStringNotContainsString('DEPRECIATION AND ACCOUNT 1500 DIFFER', $payload['note']);
    }

    /** And a depreciation entry for an asset nobody registered is a depreciation-side difference. */
    public function test_a_hand_posted_depreciation_entry_is_reported_on_the_depreciation_side(): void
    {
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15');

        $service = app(JournalEntryService::class);
        $entry = $service->create([
            'entry_date' => '2026-08-10',
            'entry_type' => 'adjusting',
            'memo' => 'Depreciation booked by hand',
        ], [
            ['account_id' => $this->account('5990')->id, 'debit_amount' => 4000],
            ['account_id' => $this->account('1500')->id, 'credit_amount' => 4000],
        ]);
        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $service->post($entry->fresh());

        $payload = $this->report();

        $this->assertSame(10_000.0, $this->figure($this->row($payload, 'Laptop')[2]), 'the row is unaffected');
        $this->assertStringContainsString('COST AGREES WITH THE ASSET ACCOUNTS', $payload['note']);
        $this->assertStringContainsString('DEPRECIATION AND ACCOUNT 1500 DIFFER BY 4,000.00', $payload['note']);
    }

    // ──────────────────────────────────────────────────────────── as at a date ──

    /**
     * Depreciation is as at the date, which the cached column cannot be.
     *
     * The reason this report sums the entries. Two months booked; read to the end of the first, the answer is
     * one month — and the cache says two, because a cache is always now.
     */
    public function test_depreciation_is_read_as_at_the_date(): void
    {
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15', '2026-08-15');

        $this->assertSame(20_000.0, (float) $asset->fresh()->accumulated_depreciation, 'the cache holds both');

        $july = $this->report('2026-07-31');

        $this->assertSame(10_000.0, $this->figure($this->row($july, 'Laptop')[2]), 'one month by then');
        $this->assertSame(350_000.0, $july['tiles'][0]['value']);
        $this->assertSame(350_000.0, $july['tiles'][1]['value'], 'and the ledger agrees at that date too');
        $this->assertStringContainsString('DEPRECIATION AGREES WITH ACCOUNT 1500', $july['note']);
    }

    /**
     * A cache that has drifted from the entries is named, because nothing else in the application compares them.
     *
     * Not a reconciliation — both figures are ours — but every book value on the register screen and every
     * future declining-balance charge is computed from the cached one.
     */
    public function test_a_drifted_cache_is_named_in_the_note(): void
    {
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15');

        // Straight to the column, the way a bad backfill or a hand-edited row would leave it.
        $asset->forceFill(['accumulated_depreciation' => 25000])->saveQuietly();

        $payload = $this->report();

        $this->assertSame(10_000.0, $this->figure($this->row($payload, 'Laptop')[2]), 'the entries win');
        $this->assertStringContainsString('DEPRECIATION AGREES WITH ACCOUNT 1500', $payload['note']);
        $this->assertStringContainsString("THE REGISTER'S CACHED DEPRECIATION IS OUT BY 15,000.00", $payload['note']);
    }

    /** An asset bought after the date was not held on it. */
    public function test_an_asset_bought_after_the_date_is_not_listed(): void
    {
        $this->asset(['name' => 'Laptop']);
        $this->asset(['name' => 'Later purchase', 'purchase_date' => '2026-09-05']);

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString('Laptop', $payload['rows'][0][0]);
    }

    /**
     * An asset disposed of after the date was still on the books on it.
     *
     * `status` is the state now, so a report that filtered on it alone would quietly drop assets out of last
     * year's note every time somebody disposed of one this year — and last year's note would change.
     */
    public function test_an_asset_disposed_of_after_the_date_is_still_listed(): void
    {
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15', '2026-08-15');
        $this->service->dispose($asset->fresh(), Carbon::parse('2026-09-10'));

        $payload = $this->report();

        $this->assertCount(1, $payload['rows'], 'still held at 31 August');
        $this->assertSame(340_000.0, $payload['tiles'][0]['value']);
        $this->assertSame(340_000.0, $payload['tiles'][1]['value'], 'and the accounts still held it then');
    }

    /** And one disposed of before the date drops out of both the report and the accounts together. */
    public function test_an_asset_disposed_of_before_the_date_is_gone_from_both_sides(): void
    {
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15');
        $this->service->dispose($asset->fresh(), Carbon::parse('2026-08-10'));

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO ASSET HELD AT THIS DATE', $payload['note']);
    }

    // ───────────────────────────────────────────────────────── the projection ──

    /**
     * The projection is the entries, in advance — straight line.
     *
     * Project twelve months, then book twelve months, and compare figure by figure. This is the test that
     * makes the forecast trustworthy: not that it looks right, but that it *is* what posting will do.
     */
    public function test_the_projection_equals_what_depreciating_actually_books(): void
    {
        $asset = $this->asset();

        $projected = $this->service->schedule($asset, Carbon::parse('2026-07-01'), 12);

        $booked = [];
        for ($month = Carbon::parse('2026-07-15'), $i = 0; $i < 12; $i++, $month->addMonthNoOverflow()) {
            $entry = $this->service->depreciateAsset($asset->fresh(), $month->copy(), $this->fiscalYear->id);
            $booked[] = [
                'month' => $entry->entry_date->toDateString(),
                'amount' => (float) $entry->lines()->where('debit_amount', '>', 0)->first()->debit_amount,
            ];
        }

        $this->assertSame($booked, $projected);
        $this->assertSame(120_000.0, array_sum(array_column($projected, 'amount')));
    }

    /**
     * And declining balance, which is where a second implementation of the rate would have drifted.
     *
     * The charge falls every month because it is a proportion of book value, so an off-by-one in the order of
     * "charge then accumulate" shows up immediately as a whole schedule of wrong figures.
     */
    public function test_the_projection_equals_what_is_booked_on_declining_balance_too(): void
    {
        $asset = $this->asset([
            'name' => 'Server',
            'purchase_cost' => 1200000,
            'salvage_value' => 120000,
            'useful_life_months' => 48,
            'depreciation_method' => 'declining_balance',
        ]);

        $projected = $this->service->schedule($asset, Carbon::parse('2026-07-01'), 12);

        $booked = [];
        for ($month = Carbon::parse('2026-07-15'), $i = 0; $i < 12; $i++, $month->addMonthNoOverflow()) {
            $entry = $this->service->depreciateAsset($asset->fresh(), $month->copy(), $this->fiscalYear->id);
            $booked[] = [
                'month' => $entry->entry_date->toDateString(),
                'amount' => (float) $entry->lines()->where('debit_amount', '>', 0)->first()->debit_amount,
            ];
        }

        $this->assertSame($booked, $projected);
        // 1,200,000 at 2/48 a month: the first charge is 50,000 and each one after it is smaller.
        $this->assertSame(50_000.0, $projected[0]['amount']);
        $this->assertLessThan($projected[0]['amount'], $projected[11]['amount']);
    }

    /** It stops at the depreciable base rather than running the full horizon. */
    public function test_the_projection_stops_when_the_base_is_exhausted(): void
    {
        $asset = $this->asset(['purchase_cost' => 30000, 'useful_life_months' => 3]);

        $projected = $this->service->schedule($asset, Carbon::parse('2026-07-01'), 12);

        $this->assertCount(3, $projected);
        $this->assertSame(30_000.0, array_sum(array_column($projected, 'amount')));
    }

    /** Salvage is a floor on the projection, exactly as it is on the posting. */
    public function test_the_projection_stops_at_salvage(): void
    {
        $asset = $this->asset(['purchase_cost' => 100000, 'salvage_value' => 40000, 'useful_life_months' => 12]);

        $projected = $this->service->schedule($asset, Carbon::parse('2026-07-01'), 24);

        $this->assertSame(60_000.0, array_sum(array_column($projected, 'amount')));
    }

    /** A month before the purchase is skipped, not charged and not returned as a nought. */
    public function test_the_projection_charges_nothing_before_the_purchase(): void
    {
        $asset = $this->asset(['purchase_date' => '2026-09-15']);

        $projected = $this->service->schedule($asset, Carbon::parse('2026-07-01'), 12);

        $this->assertSame('2026-09-30', $projected[0]['month'], 'the month of the purchase is the first charge');
        $this->assertCount(10, $projected, 'July and August are not months of ownership');
    }

    /** A disposed asset has no future charge at all. */
    public function test_a_disposed_asset_has_no_projection(): void
    {
        $asset = $this->asset();
        $this->service->dispose($asset);

        $this->assertSame([], $this->service->schedule($asset->fresh(), Carbon::parse('2026-09-01'), 12));
    }

    /**
     * The schedule starts where it is told to, and says which months it is charging.
     *
     * The report's own rule is built on this: it starts at the month of the report date unless entries run
     * past it, in which case it starts after the last month booked. Asserted here, at the level where the
     * months are visible, because the twelve-month *total* is identical either way for a straight-line asset
     * with life to spare — a window that had slipped a month would not show up in a total at all.
     */
    public function test_the_schedule_names_the_months_it_charges(): void
    {
        $asset = $this->asset();

        $projected = $this->service->schedule($asset, Carbon::parse('2026-09-15'), 3);

        $this->assertSame(['2026-09-30', '2026-10-31', '2026-11-30'], array_column($projected, 'month'));
    }

    /**
     * A month nobody remembered to run is still to come, not lost.
     *
     * Depreciation is booked from an action on the register, by hand, so months get missed. The register's
     * depreciation column cannot include a charge that was never posted, so if the forecast also started
     * *after* it, that month would appear in neither figure and the two together would understate the asset's
     * life. Thirteen months of life, one month booked, so the whole remainder falls inside the horizon and
     * the sum is checkable.
     */
    public function test_a_missed_month_is_still_to_come(): void
    {
        $asset = $this->asset(['purchase_cost' => 130000, 'useful_life_months' => 13]);
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15'); // and nobody ran August

        $row = $this->row($this->report(), 'Laptop');

        $this->assertSame(10_000.0, $this->figure($row[2]), 'only July is booked');
        $this->assertSame(120_000.0, $this->figure($row[4]), 'August onwards is still to come');
        $this->assertSame(130_000.0, $this->figure($row[2]) + $this->figure($row[4]), 'nothing is lost between them');
    }

    /** An asset with nothing left to charge says so, rather than showing a nought. */
    public function test_an_exhausted_asset_shows_no_charge_to_come(): void
    {
        $asset = $this->asset(['purchase_cost' => 20000, 'useful_life_months' => 2]);
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15', '2026-08-15');

        $this->assertSame('—', $this->row($this->report(), 'Laptop')[4]);
    }

    /**
     * Reading the report books nothing.
     *
     * The reason `schedule()` had to be written at all: the only way to obtain a forecast before it was to
     * call `runForMonth()`, which posts. A report that quietly depreciated the company's assets every time
     * somebody opened it would be discovered at a year end, by which point the ledger is wrong.
     */
    public function test_the_report_posts_nothing(): void
    {
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15');

        $before = JournalEntry::count();
        $cached = (float) $asset->fresh()->accumulated_depreciation;

        $this->report();
        $this->report('2026-12-31');

        $this->assertSame($before, JournalEntry::count(), 'the report posted an entry');
        $this->assertSame($cached, (float) $asset->fresh()->accumulated_depreciation, 'the report moved the cache');
    }

    /**
     * The register is a handful of queries whatever the asset count, not a handful per asset.
     *
     * The plan's risk list names per-row queries in these reports as the thing to watch, and this report has a
     * per-asset call in it — `schedule()`, once for every row. It is pure arithmetic on a replica today; this
     * is what notices the day somebody gives it a relation to read.
     */
    public function test_the_report_does_not_query_per_asset(): void
    {
        foreach (range(1, 10) as $i) {
            $asset = $this->asset(['name' => 'Asset '.$i, 'purchase_cost' => 120000 + $i * 1000]);
            $this->postPurchase($asset);
            $this->depreciate($asset, '2026-07-15');
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(10, $payload['rows']);
        $this->assertLessThanOrEqual(
            8,
            $queries,
            "the report ran {$queries} queries for ten assets, which is per-asset rather than aggregate",
        );
    }

    // ─────────────────────────────────────────────────────── how it is stated ──

    /** The record row foots the columns above it. */
    public function test_the_footer_foots_the_columns(): void
    {
        $first = $this->asset(['name' => 'Laptop']);
        $second = $this->asset(['name' => 'Printer', 'purchase_cost' => 60000, 'useful_life_months' => 12]);
        $this->postPurchase($first);
        $this->postPurchase($second);
        $this->depreciate($first, '2026-07-15');
        $this->depreciate($second, '2026-07-15');

        $payload = $this->report();

        foreach ([1 => 'cost', 2 => 'depreciation', 3 => 'net book value'] as $column => $label) {
            $this->assertSame(
                array_sum(array_map(fn (array $row): float => $this->figure($row[$column]), $payload['rows'])),
                $this->figure($payload['footer'][$column]),
                "the footer does not foot {$label}",
            );
        }

        $this->assertSame(420_000.0, $this->figure($payload['footer'][1]));
        $this->assertSame(15_000.0, $this->figure($payload['footer'][2]), '10,000 and 5,000');
    }

    /** A company with no assets held at the date reads as such, not as a nought reconciliation. */
    public function test_a_company_with_no_assets_says_so(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertNull($payload['footer']);
        $this->assertSame('NO ASSET HELD AT THIS DATE', $payload['note']);
        $this->assertSame(0.0, $payload['tiles'][0]['value']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $asset = $this->asset();
        $this->postPurchase($asset);
        $this->depreciate($asset, '2026-07-15');

        $onThePage = Livewire::test(FixedAssetRegister::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('FixedAssetRegister', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(FixedAssetRegister::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(User::factory()->create(['status' => 1]));

        $this->assertFalse(FixedAssetRegister::canAccess());
    }
}
