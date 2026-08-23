<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Services\DepreciationService;
use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The asset register, and what is still to be charged — `docs/reports-expansion-plan.md` Phase 2.5.
 *
 * The register existed as a Filament resource: a table of assets with a depreciate action and a dispose
 * action. What it could not answer is the question a set of accounts asks at a year end — cost, depreciation
 * to date, net book value, all three totalled and tied to the accounts that hold them. The plan calls it "a
 * standard note to the accounts", and that is exactly what it is.
 *
 * **Two things in this report were not in the code the plan assumed.**
 *
 * The first is the forecast. Phase 2.5 asks for "the next twelve months' charge from `DepreciationService`'s
 * own method", and there was no such method — `runForMonth()`, `depreciateAsset()` and `dispose()` all post
 * entries. Reading a forecast out of them would have meant *booking* twelve months of depreciation to find
 * out what twelve months of depreciation would be. `DepreciationService::schedule()` is the read-only
 * projection that was missing, and it walks the model's own `monthlyDepreciation()` forward so the forecast
 * cannot drift from the entries it predicts.
 *
 * The second is the depreciation figure itself. `fixed_assets.accumulated_depreciation` is a cached column —
 * the migration says so — maintained by the service as it posts. A cache has no history, so it is only ever
 * *now*: a register drawn to a past date would have compared a today figure against an as-at ledger balance
 * and reported the difference between two dates as a discrepancy. **So the depreciation here is summed from
 * the posted entries against account 1500 instead**, which is genuinely as at the date and makes the cache
 * itself checkable — a column that has drifted from the entries is named in the note rather than believed,
 * and `accounting:rebuild-asset-depreciation` is what repairs it.
 *
 * **It reconciles on both sides, because it has two accounts to reconcile to.** Cost sits in each asset's own
 * account; depreciation sits in 1500, the contra-asset. Phase 2's rule is that the record row ties to a
 * ledger balance, and net book value is the difference of the two — so the note states each side separately.
 * A reader who is told only that the net is out by a figure does not know which half to go and look at.
 */
class FixedAssetReports
{
    use ReportShapes;

    /** How far ahead the charge is projected. Twelve months, because that is the note a balance sheet carries. */
    private const HORIZON_MONTHS = 12;

    public function register(string $asOf): array
    {
        /** @var Collection<int, FixedAsset> $assets */
        $assets = FixedAsset::query()
            // Held at the date, not held today. An asset bought next month is not in this year's note, and
            // one disposed of after the date was still on the books on it — `status` alone says neither,
            // because it is the state now.
            ->whereDate('purchase_date', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->where('status', '!=', FixedAsset::STATUS_DISPOSED)
                    ->orWhereDate('disposed_at', '>', $asOf);
            })
            ->orderBy('asset_code')
            ->get();

        if ($assets->isEmpty()) {
            return $this->emptyReport($asOf);
        }

        $depreciation = app(DepreciationService::class);

        /*
         * The entries, from the service that posts them — not a second query of this report's own.
         *
         * `bookedFor()` answers null when there is no accumulated-depreciation account at all, which is a
         * different state from "found nothing" and the one this report degrades for: the figures below fall
         * back to the cached column and the note says they tie to nothing.
         */
        $booked = $depreciation->bookedFor($assets->modelKeys(), $asOf);
        $derivable = $booked !== null;
        $booked ??= [];

        $rows = [];
        $cost = 0.0;
        $accumulated = 0.0;
        $toCome = 0.0;
        $cached = 0.0;

        foreach ($assets as $asset) {
            // The entries when there is an account to read them from; the cached column when there is not.
            // Falling back to nought would report every asset at full cost, which is a worse answer than a
            // figure that is merely out of date.
            $toDate = $derivable
                ? round((float) ($booked[$asset->getKey()]['accumulated'] ?? 0.0), 2)
                : round((float) $asset->accumulated_depreciation, 2);

            $bookValue = round((float) $asset->purchase_cost - $toDate, 2);

            $charge = round(array_sum(array_column(
                $depreciation->schedule(
                    $asset,
                    $this->projectFrom($asOf, $booked[$asset->getKey()]['last_booked'] ?? null),
                    self::HORIZON_MONTHS,
                    $toDate,
                ),
                'amount',
            )), 2);

            $rows[] = [
                (string) $asset->asset_code.' · '.$asset->name,
                number_format((float) $asset->purchase_cost, 0),
                number_format($toDate, 0),
                number_format($bookValue, 0),
                // A dash rather than a nought: an asset with nothing left to charge and one whose next
                // charge happens to round to nothing are different facts, and only the first is common.
                $charge > 0 ? number_format($charge, 0) : '—',
                $this->basis($asset),
            ];

            $cost += (float) $asset->purchase_cost;
            $accumulated += $toDate;
            $toCome += $charge;
            $cached += (float) $asset->accumulated_depreciation;
        }

        $ledger = app(GeneralLedgerService::class);
        $costLedger = round(array_sum($ledger->balancesFor(
            $assets->pluck('account_id')->filter()->unique()->values()->all(),
            $asOf,
        )), 2);
        $accumulatedLedger = $derivable
            ? round(array_sum($ledger->balancesFor(
                Account::where('code', DepreciationService::ACCUMULATED_CODE)->pluck('id')->all(),
                $asOf,
            )), 2)
            : null;

        $netBook = round($cost - $accumulated, 2);

        return $this->table(
            'FixedAssetRegister',
            'Fixed Asset Register',
            $this->subtitle('as at '.$asOf),
            ['Asset', 'Cost', 'Depreciation to date', 'Net book value', 'Charge to come (12m)', 'Basis'],
            'minmax(0, 1fr) 9rem 12rem 11rem 12rem 13rem',
            [1, 2, 3, 4],
            $rows,
            [
                ['label' => 'NET BOOK VALUE', 'value' => $netBook, 'accent' => true],
                // The ledger's own version of the same figure beside it, not in the note: the comparison is
                // the report, and a reader should not have to hold one of the two numbers in their head.
                [
                    'label' => 'ASSET ACCOUNTS LESS DEPRECIATION',
                    'value' => round($costLedger - (float) ($accumulatedLedger ?? 0.0), 2),
                    'accent' => false,
                ],
            ],
            $this->note(
                $assets->count(),
                round($cost, 2),
                round($accumulated, 2),
                $costLedger,
                $accumulatedLedger,
                round($cached, 2),
            ),
            [
                'Total — '.$assets->count().' assets',
                number_format($cost, 0),
                number_format($accumulated, 0),
                number_format($netBook, 0),
                number_format($toCome, 0),
                '',
            ],
            'No asset held at this date.',
        );
    }

    /**
     * The first month the projection should charge for.
     *
     * The month the report is drawn in, unless depreciation has already been booked past it — then the month
     * after the last one booked. **Both halves matter.** Depreciation is run from an action on the register,
     * by hand, so a month gets missed; starting at the date's own month keeps that missed charge in "still to
     * come", where it belongs, instead of losing it between a depreciation figure that never included it and
     * a forecast that starts after it. And an asset already depreciated to the end of the year must not be
     * charged again for months the ledger has entries for.
     */
    private function projectFrom(string $asOf, ?string $lastBooked): Carbon
    {
        $from = Carbon::parse($asOf)->startOfMonth();

        if ($lastBooked === null) {
            return $from;
        }

        $after = Carbon::parse($lastBooked)->startOfMonth()->addMonthNoOverflow();

        return $after->gt($from) ? $after : $from;
    }

    /** How the asset is being written down, in the words the form uses. */
    private function basis(FixedAsset $asset): string
    {
        $method = $asset->depreciation_method === 'declining_balance' ? 'Declining balance' : 'Straight line';

        return $method.' · '.$asset->useful_life_months.'m';
    }

    /**
     * Whether the register agrees with the accounts, and which side to look at when it does not.
     *
     * **Each side separately.** Net book value is a subtraction, so a difference in it says nothing about
     * where to look — a cost misposted and a depreciation entry booked by hand read identically in the net
     * and are found in completely different places.
     *
     * The cached column is reported last and only when it disagrees. It is not a reconciliation — both
     * figures are this application's own — but a cache that has drifted from the entries means the register
     * screen, the asset form and every future declining-balance charge are working from a wrong book value,
     * and this report is the only place the two are ever put side by side.
     */
    private function note(
        int $count,
        float $cost,
        float $accumulated,
        float $costLedger,
        ?float $accumulatedLedger,
        float $cached,
    ): string {
        $costDifference = round($cost - $costLedger, 2);

        // Which way round the difference falls, because the two directions are different faults with
        // different fixes. The register holding more is the common one: nothing posts an asset's cost when
        // it is entered, so a company that books purchases straight to the bank has every asset in this
        // register and none of them in an asset account.
        $clauses = [
            $count.' assets',
            match (true) {
                abs($costDifference) < 0.01 => 'cost agrees with the asset accounts',
                $costDifference > 0 => 'cost and the asset accounts differ by '
                    .number_format($costDifference, 2).' — the register carries cost the asset accounts do '
                    .'not, an asset entered but never posted',
                default => 'cost and the asset accounts differ by '
                    .number_format(abs($costDifference), 2).' — the asset accounts carry cost the register '
                    .'does not, an asset bought without being entered',
            },
        ];

        if ($accumulatedLedger === null) {
            // Nothing to tie to, said plainly. The figures above are still the register's own, and a reader
            // told the account is missing knows both why there is no comparison and what to do about it.
            $clauses[] = 'no '.DepreciationService::ACCUMULATED_CODE.' accumulated depreciation account, so '
                .'the charge is the register\'s own cached figure and ties to nothing';

            return mb_strtoupper(implode(' · ', $clauses));
        }

        $accumulatedDifference = round($accumulated - $accumulatedLedger, 2);

        $clauses[] = match (true) {
            abs($accumulatedDifference) < 0.01 => 'depreciation agrees with account '
                .DepreciationService::ACCUMULATED_CODE,
            // The register's figure is the entries stamped with one of these assets, so the account holding
            // more means it holds depreciation belonging to nothing this report can show.
            $accumulatedDifference < 0 => 'depreciation and account '.DepreciationService::ACCUMULATED_CODE
                .' differ by '.number_format(abs($accumulatedDifference), 2).' — an entry against the '
                .'account for an asset that is not in the register',
            default => 'depreciation and account '.DepreciationService::ACCUMULATED_CODE.' differ by '
                .number_format($accumulatedDifference, 2).' — depreciation stamped with an asset and posted '
                .'somewhere other than the account',
        };

        if (abs(round($cached - $accumulated, 2)) >= 0.01) {
            $clauses[] = 'the register\'s cached depreciation is out by '
                .number_format(abs(round($cached - $accumulated, 2)), 2).' — book values on the asset screen '
                .'are wrong until it is rebuilt';
        }

        return mb_strtoupper(implode(' · ', $clauses));
    }

    /** A company with no assets at the date, said plainly — and with the balanced flag untouched. */
    private function emptyReport(string $asOf): array
    {
        return $this->table(
            'FixedAssetRegister',
            'Fixed Asset Register',
            $this->subtitle('as at '.$asOf),
            ['Asset', 'Cost', 'Depreciation to date', 'Net book value', 'Charge to come (12m)', 'Basis'],
            'minmax(0, 1fr) 9rem 12rem 11rem 12rem 13rem',
            [1, 2, 3, 4],
            [],
            [
                ['label' => 'NET BOOK VALUE', 'value' => 0.0, 'accent' => true],
                ['label' => 'ASSET ACCOUNTS LESS DEPRECIATION', 'value' => 0.0, 'accent' => false],
            ],
            'NO ASSET HELD AT THIS DATE',
            null,
            'No asset held at this date. An asset bought later, or disposed of by then, is not shown here.',
        );
    }
}
