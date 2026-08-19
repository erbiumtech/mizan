<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\InvoiceAllocation;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Modules\ConstructionCosting\Support\MatchTolerances;

/**
 * Days on site against what was invoiced — `docs/construction-management-plan.md` §7.3's two-way match.
 *
 * **The failure it exists to catch is named in the plan: "the classic over-billing of plant left standing after it was
 * collected."** A hire invoice arrives monthly, is approved by whoever recognises the supplier's name, and nobody
 * compares it with the days anybody actually saw the machine. This makes that comparison a query.
 *
 * **Computed, never stored**, like §5's three-way match — and unlike it, with nothing stored at all: §5 keeps the
 * *acceptance* of a variance because accepting one is a decision, and that mechanism already exists on the commitment
 * line the invoice was allocated to. A second acceptance here would be two records of one decision.
 *
 * **Cumulative, with no period filter, and that is deliberate.** A hire invoice covers a month and is dated after it;
 * the logs are dated within it. Filtering both by the same range would report a difference on every machine every
 * month and the report would stop being read — which is the failure mode §11's reconciliation is written to avoid.
 * Over the life of a hire the two figures should converge, and it is the cumulative gap that means something.
 */
class PlantHireMatch
{
    /** The logs and the invoices agree, within tolerance. */
    public const STATUS_BALANCED = 'balanced';

    /** More has been invoiced than the logs support — the case §7.3 names. */
    public const STATUS_OVER_BILLED = 'over_billed';

    /** Less has been invoiced than the logs support: a bill still to come, or a credit taken. */
    public const STATUS_UNDER_BILLED = 'under_billed';

    /** Owned plant has no invoice to match, and a hired machine with no order has nothing to match against. */
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /**
     * What this machine's logs say it was worth, and what has been billed for it.
     *
     * @return array{
     *     status: string,
     *     logged_units: float,
     *     expected: float,
     *     invoiced: float,
     *     difference: float,
     *     explanation: string,
     * }
     */
    public function for(PlantItem $item): array
    {
        if ($item->isOwned()) {
            return $this->notApplicable(
                'This machine is owned, so there is no supplier invoice to check. Its logs are the cost.',
            );
        }

        if ($item->commitment_id === null) {
            return $this->notApplicable(
                'No hire order is linked to this machine, so there is nothing to compare the logs with. Link the '
                .'order on the plant record — and until then, an invoice for this machine can be paid with nothing '
                .'saying whether the days add up.',
            );
        }

        $logs = PlantLog::query()
            ->where('plant_item_id', $item->getKey())
            ->approved()
            ->get(['id', 'working_units', 'idle_units', 'standby_units', 'charge_amount']);

        $expected = round((float) $logs->sum(fn (PlantLog $log): float => (float) $log->charge_amount), 2);
        $units = round((float) $logs->sum(fn (PlantLog $log): float => $log->totalUnits()), 2);

        // What the supplier has actually been paid for this machine: every allocation against the hire order's lines.
        // Read through the allocations rather than the invoices, because §5's whole point is that the allocation is
        // where an invoice becomes a job's cost — an unallocated invoice is a different problem, and its own queue.
        $invoiced = round((float) InvoiceAllocation::query()
            ->whereIn('commitment_line_id', CommitmentLine::query()
                ->where('commitment_id', $item->commitment_id)
                ->select('id'))
            ->sum('amount'), 2);

        $difference = round($invoiced - $expected, 2);

        return [
            'status' => $this->statusFor($difference),
            'logged_units' => $units,
            'expected' => $expected,
            'invoiced' => $invoiced,
            'difference' => $difference,
            'explanation' => $this->explain($difference, $units),
        ];
    }

    /**
     * How far apart the two figures may be before it means something.
     *
     * The price tolerance of §5's three-way match, reused rather than reinvented: this is the same question about the
     * same supplier's invoice, and a second tolerance somebody has to keep in step with the first would eventually
     * disagree with it. `MatchTolerances` resolves the company's setting and falls back to config.
     */
    private function statusFor(float $difference): string
    {
        if (abs($difference) <= MatchTolerances::minimumAmount()) {
            return self::STATUS_BALANCED;
        }

        return $difference > 0 ? self::STATUS_OVER_BILLED : self::STATUS_UNDER_BILLED;
    }

    private function explain(float $difference, float $units): string
    {
        if ($units <= 0.0) {
            return 'Nothing has been logged for this machine yet, so anything invoiced is unsupported. That is the '
                .'state a machine left on site after it was collected produces.';
        }

        return match ($this->statusFor($difference)) {
            self::STATUS_OVER_BILLED => 'More has been invoiced than the logs support, by '
                .number_format(abs($difference), 2).'. Check whether the machine was still on site for the days '
                .'being billed — plant billed after collection is the commonest way this happens.',
            self::STATUS_UNDER_BILLED => number_format(abs($difference), 2).' of logged hire has not been invoiced '
                .'yet. Ordinary mid-month, and worth chasing if the hire has ended.',
            default => 'The logs and the invoices agree.',
        };
    }

    /** @return array<string, mixed> */
    private function notApplicable(string $explanation): array
    {
        return [
            'status' => self::STATUS_NOT_APPLICABLE,
            'logged_units' => 0.0,
            'expected' => 0.0,
            'invoiced' => 0.0,
            'difference' => 0.0,
            'explanation' => $explanation,
        ];
    }
}
