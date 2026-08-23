<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Services\DepreciationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Put each asset's cached depreciation back in step with the entries behind it.
 *
 * `fixed_assets.accumulated_depreciation` is a cache — the migration says so — maintained by
 * `DepreciationService` as it posts. The Fixed Asset Register report reads the *entries* instead, because a
 * cache cannot answer an "as at" question, and that made it the one thing in the application that sees the
 * two figures side by side. It reports the drift in its own note. This is what does something about it.
 *
 * **The cache is not cosmetic, which is why a repair path ships with the report rather than after it.**
 * `FixedAsset::book_value` subtracts the cached column, so while it is wrong: every declining-balance charge
 * is computed from a wrong book value, the register screen shows a wrong one, and a disposal books a wrong
 * loss. A drift is therefore not a display fault that can wait — it is arithmetic that the next thing to run
 * bakes into the ledger.
 *
 * It only ever writes what disagrees, so running it twice is safe, and --dry-run shows the whole plan first.
 */
class RebuildAssetDepreciationCommand extends Command
{
    use TenantAware;

    protected $signature = 'accounting:rebuild-asset-depreciation
                            {--dry-run : List what would change without writing anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = "Rebuild each asset's cached accumulated depreciation from its posted entries";

    public function handle(DepreciationService $depreciation): int
    {
        $assets = FixedAsset::query()->orderBy('asset_code')->get();

        if ($assets->isEmpty()) {
            $this->info('Nothing to do: there are no fixed assets.');

            return self::SUCCESS;
        }

        $booked = $depreciation->bookedFor($assets->modelKeys());

        /*
         * No accumulated-depreciation account means no ledger to derive anything from, and the honest
         * response is to write nothing. Zeroing every cache would be the *loudest* possible reading of
         * "cannot tell", and it would destroy the only figures the company has.
         */
        if ($booked === null) {
            $this->error(sprintf(
                'There is no %s accumulated depreciation account, so there are no entries to rebuild from.',
                DepreciationService::ACCUMULATED_CODE,
            ));
            $this->line('  Run ChartOfAccountsSeeder, or check the account was not renamed to another code.');

            return self::FAILURE;
        }

        $drifted = $assets->filter(function (FixedAsset $asset) use ($booked): bool {
            $entries = (float) ($booked[$asset->getKey()]['accumulated'] ?? 0.0);

            return abs(round((float) $asset->accumulated_depreciation - $entries, 2)) >= 0.01;
        });

        if ($drifted->isEmpty()) {
            $this->info("Nothing to do: every asset's cached depreciation agrees with its entries.");

            return self::SUCCESS;
        }

        $this->report($drifted, $booked);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — nothing was written. Drop --dry-run to apply.');

            return self::SUCCESS;
        }

        foreach ($drifted as $asset) {
            $depreciation->rebuildCache($asset);
        }

        $this->newLine();
        $this->info(sprintf('Rebuilt %d asset(s) from the ledger.', $drifted->count()));

        return self::SUCCESS;
    }

    /**
     * The plan, before anything is written.
     *
     * The status column is here because it is the half of the repair nobody expects: `fully_depreciated` is a
     * statement about the figure, so an asset whose cache overstated what was booked goes back to `active`
     * with life left in it — and somebody reading a list of money columns should be told that before it
     * happens rather than discovering an asset started depreciating again.
     *
     * @param  Collection<int, FixedAsset>  $drifted
     * @param  array<int, array{accumulated: float, last_booked: string|null}>  $booked
     */
    private function report(Collection $drifted, array $booked): void
    {
        $this->newLine();
        $this->line('Cached depreciation that disagrees with the entries behind it:');

        $this->table(
            ['Asset', 'Cached', 'Entries', 'Difference', 'Status'],
            $drifted->map(function (FixedAsset $asset) use ($booked): array {
                $entries = round((float) ($booked[$asset->getKey()]['accumulated'] ?? 0.0), 2);
                $cached = round((float) $asset->accumulated_depreciation, 2);

                return [
                    $asset->asset_code.' · '.$asset->name,
                    number_format($cached, 2),
                    number_format($entries, 2),
                    number_format($cached - $entries, 2),
                    $this->statusChange($asset, $entries),
                ];
            })->all(),
        );
    }

    /** What the status becomes, and a dash where the repair does not move it. */
    private function statusChange(FixedAsset $asset, float $entries): string
    {
        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            // Disposal is an event, not a position. Nothing here brings an asset back onto the books.
            return 'disposed (left alone)';
        }

        $after = round($asset->depreciable_base - $entries, 2) <= 0
            ? FixedAsset::STATUS_FULLY_DEPRECIATED
            : FixedAsset::STATUS_ACTIVE;

        return $after === $asset->status ? '—' : $asset->status.' → '.$after;
    }
}
