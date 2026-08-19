<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Plant logs in, internal hire out — `docs/construction-management-plan.md` §7.3.
 *
 * **The rule this service exists for: an owned machine's log books cost and a hired machine's does not.** §4.1 decides
 * it — if a GL document already exists for a cost, construction mirrors or stays out of the way, and a hired machine's
 * supplier invoice is exactly that document. It reaches the job through §5's allocation chain, so a cost entry here as
 * well would charge the job twice for the same excavator. What a hired machine's log produces instead is
 * `charge_amount`: what the agreement says those days were worth, which is the left-hand side of §7.3's two-way match.
 *
 * Three more rules, each a way plant cost goes wrong quietly:
 *
 *  - **A machine with no rate cannot be approved.** Booking a day of excavator at zero is §18.1's healthy-looking
 *    figure hiding an absence, and on plant it is worse than on labour because nobody expects a machine to be free.
 *  - **A null rate means not charged, and the log says so.** Idle falling back to the working rate would inflate every
 *    job that ever had a machine standing.
 *  - **A meter cannot go backwards.** A mismatch between engine hours and charged hours is ordinary and never refused;
 *    a negative movement is a typo or a replaced instrument, and either needs a person.
 *
 * The recovery credit itself — Plant Internal Hire Recovery — is §11's posting service, like burden's. This service
 * writes the debit as `pending` and §4.2's reconciling-items list is where the pair meets.
 */
class PlantService
{
    public function __construct(private readonly CostLedger $ledger) {}

    /**
     * Log a machine's day as a draft.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function log(PlantItem $item, Job $job, CostCode $code, array $attributes): PlantLog
    {
        $loggedOn = Carbon::parse($attributes['logged_on'] ?? now())->toDateString();

        $working = (float) ($attributes['working_units'] ?? 0);
        $idle = (float) ($attributes['idle_units'] ?? 0);
        $standby = (float) ($attributes['standby_units'] ?? 0);

        $this->guardCode($code);
        $this->guardUnits($working, $idle, $standby);
        $this->guardMeter($attributes['meter_start'] ?? null, $attributes['meter_end'] ?? null);

        return TenantTransaction::run(fn (): PlantLog => PlantLog::create(array_merge($attributes, [
            'plant_item_id' => $item->getKey(),
            'job_id' => $job->getKey(),
            'cost_code_id' => $code->getKey(),
            'logged_on' => $loggedOn,
            'working_units' => $working,
            'idle_units' => $idle,
            'standby_units' => $standby,
        ])));
    }

    /**
     * Approve a log, which prices it — and, for an owned machine, books it.
     *
     * The rates are snapshotted here for the reason §7.1 snapshots a labour rate: the charge has to survive somebody
     * revising the machine's rate next month. Approving a hired machine's log is not a no-op — it is what fixes the
     * figure the supplier's invoice will be checked against, which is the whole point of logging hired plant at all.
     */
    public function approve(PlantLog $log): PlantLog
    {
        if (! $log->isDraft()) {
            throw new InvalidArgumentException(
                "That log is {$log->status} and cannot be approved again. A correction to an approved log is a "
                .'reversal, which keeps both the original and the reason.'
            );
        }

        // Loaded explicitly: a log approved from the register is a row out of the table with no relations on it, and
        // lazy loading is disabled application-wide.
        $log->loadMissing(['plantItem', 'job', 'costCode']);

        $item = $log->plantItem;

        if (! $item->hasChargeableRate()) {
            throw new InvalidArgumentException(
                "{$item->displayName()} has no rate set, so this day cannot be priced. A machine charged at nothing "
                .'makes a job look cheap and the fleet look free — set the working rate on the plant record first.'
            );
        }

        $charge = $log->chargeFrom($item->working_rate, $item->idle_rate, $item->standby_rate);

        return TenantTransaction::run(function () use ($log, $item, $charge): PlantLog {
            $log->update([
                'working_rate' => $item->working_rate,
                'idle_rate' => $item->idle_rate,
                'standby_rate' => $item->standby_rate,
                'charge_amount' => $charge,
                'status' => PlantLog::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            $log->refresh();

            /*
             * **Owned only.** A hired machine's cost is its supplier invoice, and this log is the check against it —
             * §7.3 and §4.1. Writing an entry here as well is the double-charge this branch exists to prevent, and it
             * would be invisible: both figures would look like plant cost on the same job and code.
             */
            if ($item->isOwned() && $charge > 0.0) {
                $log->update(['cost_entry_id' => $this->writeInternalHire($log, $charge)->getKey()]);
            }

            return $log->refresh();
        });
    }

    /**
     * The internal hire charge — a debit to the job that §11 credits to Plant Internal Hire Recovery.
     *
     * `pending` rather than `memo`, for the reason resolved at §7.3 in Phase 7b: charging a job with no credit anywhere
     * means "the fleet looks free while every job looks expensive", and the recovery account is what the depreciation,
     * fuel and repairs of the machine accumulate against.
     *
     * The quantity is the **chargeable** units rather than every unit on site, so the unit rate reads as the rate
     * actually applied — a machine that stood idle uncharged would otherwise report a rate below its own.
     */
    private function writeInternalHire(PlantLog $log, float $charge): CostEntry
    {
        $units = $this->chargeableUnits($log);

        return $this->ledger->record($log->job, $log->costCode, [
            'kind' => CostEntry::KIND_ACTUAL,
            'gl_treatment' => CostEntry::GL_PENDING,
            'amount' => $charge,
            'quantity' => $units > 0.0 ? $units : null,
            'unit_of_measure' => $log->plantItem?->meter_unit === PlantItem::METER_KILOMETRES ? 'km' : 'hr',
            'unit_rate' => $units > 0.0 ? round($charge / $units, 4) : null,
            'incurred_on' => $log->logged_on->toDateString(),
            'wbs_node_id' => $log->wbs_node_id,
            'worker_id' => $log->operator_worker_id,
            'description' => $log->description ?: 'Internal hire — '.$log->displayName(),
            'source_type' => $log::class,
            'source_id' => $log->getKey(),
        ]);
    }

    /**
     * The units that actually carried a rate.
     *
     * Not `totalUnits()`: a day of eight working hours and four uncharged idle ones is eight chargeable units, and
     * dividing the charge by twelve would report a rate two thirds of the one the company set.
     */
    private function chargeableUnits(PlantLog $log): float
    {
        $units = 0.0;

        foreach ([
            ['working_units', 'working_rate'],
            ['idle_units', 'idle_rate'],
            ['standby_units', 'standby_rate'],
        ] as [$unitColumn, $rateColumn]) {
            if ((float) ($log->{$rateColumn} ?? 0) > 0.0) {
                $units += (float) $log->{$unitColumn};
            }
        }

        return round($units, 2);
    }

    /** Reverse an approved log: the cost entry where there is one, and the status either way. */
    public function reverse(PlantLog $log, string $reason): PlantLog
    {
        if (! $log->isApproved()) {
            throw new InvalidArgumentException(
                "That log is {$log->status}. Only an approved log has anything to reverse — a draft is deleted or "
                .'corrected in place.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Reversing a plant log needs a reason. On an owned machine the cost report will show both rows, and '
                .'on a hired one this log is what an invoice is checked against.'
            );
        }

        $log->loadMissing('costEntry');

        return TenantTransaction::run(function () use ($log, $reason): PlantLog {
            if ($log->costEntry !== null && ! $log->costEntry->isReversed()) {
                $this->ledger->reverse($log->costEntry, $reason);
            }

            $log->update([
                'status' => PlantLog::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ]);

            return $log->refresh();
        });
    }

    /**
     * Update a draft in place — §3.3's line, the same as a labour record's.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(PlantLog $log, array $attributes): PlantLog
    {
        if (! $log->isDraft()) {
            throw new InvalidArgumentException(
                "That log is {$log->status} and is already priced. Reverse it and log the day again."
            );
        }

        $this->guardUnits(
            (float) ($attributes['working_units'] ?? $log->working_units),
            (float) ($attributes['idle_units'] ?? $log->idle_units),
            (float) ($attributes['standby_units'] ?? $log->standby_units),
        );
        $this->guardMeter(
            $attributes['meter_start'] ?? $log->meter_start,
            $attributes['meter_end'] ?? $log->meter_end,
        );

        $log->update($attributes);

        return $log->refresh();
    }

    // ------------------------------------------------------------------ the guards

    private function guardCode(CostCode $code): void
    {
        if (! $code->is_leaf) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} is a heading. Booking plant against it would double-count in every "
                .'rolled-up total.'
            );
        }

        if (! $code->is_active) {
            throw new InvalidArgumentException("Cost code {$code->code} is switched off and cannot take new cost.");
        }
    }

    private function guardUnits(float $working, float $idle, float $standby): void
    {
        if ($working < 0.0 || $idle < 0.0 || $standby < 0.0) {
            throw new InvalidArgumentException('Negative plant units are not a correction. Reverse the log instead.');
        }

        if ($working + $idle + $standby <= 0.0) {
            throw new InvalidArgumentException(
                'A log of no units tells nobody anything. A machine that did not turn up is a log nobody enters.'
            );
        }
    }

    /**
     * A meter cannot go backwards.
     *
     * Only the direction is checked. A machine's engine hours legitimately differ from its charged hours — warming up,
     * travelling between faces, an operator leaving it running through lunch — so refusing a mismatch would refuse the
     * ordinary case and teach everybody to leave the readings blank, which loses the evidence entirely.
     */
    private function guardMeter(mixed $start, mixed $end): void
    {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return;
        }

        if ((float) $end < (float) $start) {
            throw new InvalidArgumentException(
                'The closing meter reading is lower than the opening one, which a meter cannot do. Either a reading '
                .'is mistyped, or the instrument was replaced — and a replaced meter needs somebody to say so.'
            );
        }
    }
}
