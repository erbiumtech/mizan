<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\ForecastRun;
use App\Modules\ConstructionCosting\Models\ProgressMeasurement;
use App\Modules\ConstructionCosting\Models\WipSnapshot;
use App\Support\TenantDb;
use App\Support\TenantTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Work in progress, and the loss that must be taken at once — `docs/construction-management-plan.md` §4.4.
 *
 * **The one place in this plan where a total is stored on purpose**, and §4.4 argues the exception against
 * `docs/new-module-checklist.md` §10 rather than assuming it: "a WIP position is a judgement at a point in time — the
 * surveyor's forecast, the surveyed percentage, the loss provision — not a derivation from immutable facts. Recomputing
 * last March's WIP with today's forecast would silently restate a month that was signed off, reported to a bank and used
 * to compute a bonus."
 *
 * So `compute()` is live for an unlocked month and `snapshotFor()` returns the frozen row for a locked one, and the two
 * are different methods rather than one with a flag — because a caller should have to know which it is asking for.
 *
 * **Three rules with money behind them:**
 *
 *  - **Pending variations are shown and excluded.** §4.4 asks for both, and the pair is the point: a job whose contract
 *    value looks comfortable while eleven million of variations sit unapproved is a job about to be in trouble, and one
 *    figure cannot say so.
 *  - **The whole expected loss is recognised immediately, never pro-rated.** A loss spread across the remaining months
 *    is the single most common way a loss-making contract reports as profitable until the month it finishes.
 *  - **The journal posts the movement, not the balance**, and §4.4 forbids having the choice twice: "choose one; two code
 *    paths each choosing differently is the failure."
 *
 * **The revenue side is read with the query builder.** Contract sums, variations and certified billings live in
 * `construction_contracts`, which already declares an edge to this module (§6c's commitment path) — so naming `Contract`
 * or `PaymentCertificate` here would be a two-cycle and `TANGLED_MODULE_BUDGET = 0` would be right to fail it. Same
 * discipline as `AccrualService`, same reason.
 */
class WipService
{
    public function __construct(private JournalEntryService $journals) {}

    /**
     * The position as it stands, computed from today's facts.
     *
     * **Refuses when the job has not chosen a percent-complete method**, because choosing for them would pick the answer
     * that flatters an over-spending job: cost-to-cost on a job running over reports *more* progress for spending more
     * money, which is exactly backwards. §4.4 makes the method a per-job choice for that reason and this is where the
     * choice is enforced.
     */
    public function compute(Job $job, string $periodStart): WipSnapshot
    {
        $start = CostPeriod::startFor($periodStart)->toDateString();

        $method = $job->percent_complete_method;

        if ($method === null) {
            throw new InvalidArgumentException(
                "{$job->code} has no percent-complete method set, so its work-in-progress position cannot be computed. "
                .'Choose cost-to-cost, surveyed or milestone on the job — picking one here would flatter an '
                .'over-spending job, since cost-to-cost reports more progress for spending more money.'
            );
        }

        $cost = $this->costToDate($job, $start);
        $forecast = $this->forecast($job, $start, $cost['total']);
        $revenue = $this->contractValue($job, $start);
        $percent = $this->percentComplete($job, $method, $start, $cost['total'], $forecast['final_cost']);

        /*
         * Revenue recognised is percent complete of the contract value, and nothing else.
         *
         * Not "cost plus a margin", which would make revenue move with cost on a fixed-price job — the arithmetic that
         * hides an overrun by reporting more revenue for spending more money.
         */
        $revenueRecognised = round($revenue['contract_value'] * $percent / 100, 2);

        /*
         * **The whole expected loss, immediately.** §4.4, and never pro-rated.
         *
         * The forecast final cost against the contract value. A job forecast to finish 3,000,000 over takes the whole
         * 3,000,000 now, at 4% complete or 96%.
         */
        $provision = max(0.0, round($forecast['final_cost'] - $revenue['contract_value'], 2));

        /*
         * §4.4's two positions.
         *
         * Costs and recognised profit in excess of billings is what has been earned and not billed. Billings in excess is
         * the reverse. The loss provision reduces the asset side, because a loss recognised is not an asset — that is
         * what recognising it *means*.
         */
        $earned = round($revenueRecognised - $provision, 2);
        $net = round($earned - $revenue['billings_to_date'], 2);

        $attributes = [
            'job_id' => $job->getKey(),
            'period_start' => $start,
            'percent_complete_method' => $method,
            'percent_complete' => round($percent, 4),
            'cost_to_date' => $cost['actual'],
            'accrued_to_date' => $cost['accrued'],
            'forecast_final_cost' => $forecast['final_cost'],
            'forecast_run_id' => $forecast['run_id'],
            'contract_sum_original' => $revenue['contract_sum_original'],
            'variations_approved' => $revenue['variations_approved'],
            'variations_pending' => $revenue['variations_pending'],
            'contract_value' => $revenue['contract_value'],
            'revenue_recognised' => $revenueRecognised,
            'billings_to_date' => $revenue['billings_to_date'],
            'provision_for_loss' => $provision,
            'contract_asset' => max(0.0, $net),
            'contract_liability' => max(0.0, -$net),
        ];

        $existing = WipSnapshot::query()->where('job_id', $job->getKey())->forPeriod($start)->first();

        if ($existing?->isLocked()) {
            /*
             * A locked month is frozen and this returns it unchanged, deliberately rather than by refusing.
             *
             * §4.4's whole exception is that last March's position is what it was. A caller asking for it should get it;
             * a caller wanting today's numbers is asking about an open month. Throwing here would make every report have
             * to know which months are locked before it could ask.
             */
            return $existing;
        }

        return TenantTransaction::run(function () use ($existing, $attributes, $job, $start): WipSnapshot {
            if ($existing !== null) {
                $existing->update($attributes);

                return $existing->refresh();
            }

            return WipSnapshot::create($attributes + [
                'previous_snapshot_id' => $this->previousLocked($job, $start)?->getKey(),
                'created_by' => auth()->id(),
            ]);
        });
    }

    /** The stored row for a month, whether or not it is locked. Null where the month was never computed. */
    public function snapshotFor(Job $job, string $periodStart): ?WipSnapshot
    {
        return WipSnapshot::query()
            ->where('job_id', $job->getKey())
            ->forPeriod($periodStart)
            ->first();
    }

    /**
     * Freeze a month's position.
     *
     * **After this, the row does not move.** §4.4: the month was signed off, reported to a bank and used to compute a
     * bonus. Computed once from today's facts and then locked, which is why this recomputes first — locking a stale row
     * would freeze figures that were true a fortnight ago.
     */
    public function lock(Job $job, string $periodStart): WipSnapshot
    {
        $snapshot = $this->compute($job, $periodStart);

        if ($snapshot->isLocked()) {
            throw new InvalidArgumentException(
                $snapshot->displayName().' is already locked. A locked position does not move — that is what locking it '
                .'is for.'
            );
        }

        $snapshot->update([
            'locked_at' => now(),
            'locked_by' => auth()->id(),
            'previous_snapshot_id' => $this->previousLocked($job, $snapshot->period_start->toDateString())?->getKey(),
        ]);

        return $snapshot->refresh();
    }

    /**
     * Post the **movement** from the previous locked snapshot — §4.4, and the choice it forbids making twice.
     *
     * "The WIP journal posts the movement from the previous locked snapshot, not the balance. Reversing last month's
     * whole position and re-posting this month's is a defensible alternative — `journal_entries` has a `reversing` type
     * already — but it produces a profit and loss whose gross figures are enormous and whose monthly movement has to be
     * inferred. **Choose one; two code paths each choosing differently is the failure.**"
     *
     * So there is one code path and it posts the movement. A month whose position has not moved posts nothing, which is
     * the honest answer: a zero-value journal is a line in the accounts saying nothing happened.
     */
    public function postMovement(WipSnapshot $snapshot): ?JournalEntry
    {
        if (! $snapshot->isLocked()) {
            throw new InvalidArgumentException(
                'A work-in-progress position is posted when it is locked, not before. An unlocked position is '
                .'recomputed every time somebody opens it, and a journal against a figure that moves is a journal '
                .'nobody can explain.'
            );
        }

        if ($snapshot->isPosted()) {
            throw new InvalidArgumentException($snapshot->displayName().' has already been posted.');
        }

        $wip = ControlAccount::forPurpose('wip_movement');
        $revenueAccount = ControlAccount::query()->active()->ofKind(ControlAccount::KIND_REVENUE)->first();

        if ($wip === null || $revenueAccount === null) {
            throw new InvalidArgumentException(
                'Posting a work-in-progress movement needs a work-in-progress account and a contract revenue account '
                .'nominated under Control accounts. Without both there is nothing to debit or credit, and crediting a '
                .'suspense account would put a figure in the books nobody chose.'
            );
        }

        $movement = round($snapshot->netPosition() - ($snapshot->previousSnapshot?->netPosition() ?? 0.0), 2);

        if (abs($movement) < 0.01) {
            /*
             * Nothing moved, so nothing posts.
             *
             * A zero-value journal line is a line in the accounts saying nothing happened, which is worse than the
             * silence it replaces — the same argument §11a makes about a cancelling group.
             */
            return null;
        }

        return TenantTransaction::run(function () use ($snapshot, $wip, $revenueAccount, $movement): JournalEntry {
            $amount = abs($movement);
            $increasing = $movement > 0;

            $entry = $this->journals->create([
                // The month's end, not today. A June position posted in July belongs in June, the same rule §11a's
                // summary journal follows and for the same reason.
                'entry_date' => $snapshot->period_start->copy()->endOfMonth()->toDateString(),
                'entry_type' => 'general',
                'memo' => 'WIP movement — '.$snapshot->displayName(),
            ], [
                [
                    'account_id' => $increasing ? $wip->account_id : $revenueAccount->account_id,
                    'debit_amount' => $amount,
                    'description' => 'WIP movement '.$snapshot->period_start->format('F Y'),
                ],
                [
                    'account_id' => $increasing ? $revenueAccount->account_id : $wip->account_id,
                    'credit_amount' => $amount,
                    'description' => 'WIP movement '.$snapshot->period_start->format('F Y'),
                ],
            ]);

            $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
            $this->journals->post($entry);

            $snapshot->update(['journal_entry_id' => $entry->getKey(), 'posted_at' => now()]);

            return $entry;
        });
    }

    /**
     * The movement this snapshot would post, as a read.
     *
     * Its own method because the number is worth showing before it is posted, and because §4.4's choice — movement, not
     * balance — is easier to trust when somebody can see the two figures it came from.
     *
     * @return array{from: float, to: float, movement: float}
     */
    public function movement(WipSnapshot $snapshot): array
    {
        $from = $snapshot->previousSnapshot?->netPosition() ?? 0.0;
        $to = $snapshot->netPosition();

        return ['from' => $from, 'to' => $to, 'movement' => round($to - $from, 2)];
    }

    /**
     * Cost to date on the job tree, to the end of the period.
     *
     * Cumulative rather than the month's own, because a WIP position is a balance-sheet figure: what has been spent on
     * this contract so far, against what has been billed for it so far.
     *
     * @return array{actual: float, accrued: float, total: float}
     */
    private function costToDate(Job $job, string $start): array
    {
        $end = CostPeriod::startFor($start)->copy()->endOfMonth()->toDateString();

        $rows = CostEntry::query()
            ->forJobTree($job)
            ->where('gl_treatment', '!=', CostEntry::GL_MEMO)
            ->whereDate('posting_period', '<=', $end)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = '".CostEntry::KIND_ACCRUAL."' THEN amount ELSE 0 END), 0) as accrued")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind != '".CostEntry::KIND_ACCRUAL."' THEN amount ELSE 0 END), 0) as actual")
            ->first();

        $actual = round((float) $rows->actual, 2);
        $accrued = round((float) $rows->accrued, 2);

        return ['actual' => $actual, 'accrued' => $accrued, 'total' => round($actual + $accrued, 2)];
    }

    /**
     * The approved forecast — §4.4's "estimated total cost".
     *
     * **Issued runs only.** A draft forecast is a surveyor's working paper; a WIP position built on one would restate
     * itself every time they saved. The latest issued run at or before the period, because a forecast issued in June is
     * the best estimate June had.
     *
     * Falls back to cost to date where no forecast has been issued, and that is a *conservative* fallback rather than an
     * optimistic one: it says "we know of no cost beyond what we have spent", which recognises no loss it cannot see
     * rather than inventing a completion figure.
     *
     * @return array{final_cost: float, run_id: int|null}
     */
    private function forecast(Job $job, string $start, float $costToDate): array
    {
        $run = ForecastRun::query()
            ->where('job_id', $job->getKey())
            ->where('status', 'issued')
            ->whereDate('period_start', '<=', $start)
            ->orderByDesc('period_start')
            ->first();

        if ($run === null) {
            return ['final_cost' => $costToDate, 'run_id' => null];
        }

        $final = (float) TenantDb::table('construction_forecast_lines')
            ->where('forecast_run_id', $run->getKey())
            ->sum('forecast_final_cost');

        return ['final_cost' => round($final, 2), 'run_id' => $run->getKey()];
    }

    /**
     * The revenue side, read out of the contract tables.
     *
     * **Approved variations only in the contract value, pending shown beside it** — §4.4. `is_price_provisional` is
     * excluded from the approved figure for the reason §9 gives it: a provisionally priced variation is an approved
     * *scope* at a figure nobody has agreed, and putting it in contract value would recognise revenue at a rate that may
     * not survive the argument.
     *
     * Billings are certified rather than invoiced: §10.4 puts the boundary at the certificate, and a certificate not yet
     * turned into an invoice has still been billed as far as the contract is concerned.
     *
     * @return array{contract_sum_original: float, variations_approved: float, variations_pending: float, contract_value: float, billings_to_date: float}
     */
    private function contractValue(Job $job, string $start): array
    {
        $end = CostPeriod::startFor($start)->copy()->endOfMonth()->toDateString();
        $jobIds = Job::query()->inSubtree($job)->pluck('id');

        // The client-side contracts: `side = receivable` is §8.1's discriminator in the one table it uses for both.
        $contracts = TenantDb::table('construction_contracts')
            ->whereIn('job_id', $jobIds)
            ->where('side', 'receivable')
            ->pluck('contract_sum', 'id');

        if ($contracts->isEmpty()) {
            /*
             * No contract, so no revenue side.
             *
             * The job's own `contract_sum` is deliberately not used as a fallback: §8 makes the contract the document
             * that carries the sum, and a WIP position computed off a figure typed on the job would disagree with every
             * certificate issued against the contract.
             */
            return [
                'contract_sum_original' => 0.0, 'variations_approved' => 0.0, 'variations_pending' => 0.0,
                'contract_value' => 0.0, 'billings_to_date' => 0.0,
            ];
        }

        $contractIds = $contracts->keys()->all();

        $approved = (float) TenantDb::table('construction_variations')
            ->whereIn('contract_id', $contractIds)
            ->whereIn('status', ['approved', 'incorporated'])
            ->where('is_price_provisional', false)
            ->sum(DB::raw('COALESCE(approved_amount, assessed_amount, quoted_amount, 0)'));

        /*
         * Pending: submitted or priced, plus anything approved at a provisional price.
         *
         * The provisional ones belong here rather than nowhere: the scope is agreed and the money is not, which is
         * exactly the "about to be in trouble" state §4.4 wants visible beside the contract value rather than inside it.
         */
        $pending = (float) TenantDb::table('construction_variations')
            ->whereIn('contract_id', $contractIds)
            ->where(fn ($q) => $q
                ->whereIn('status', ['submitted', 'priced', 'approved_in_principle'])
                ->orWhere(fn ($inner) => $inner
                    ->whereIn('status', ['approved', 'incorporated'])
                    ->where('is_price_provisional', true)))
            ->sum(DB::raw('COALESCE(approved_amount, assessed_amount, quoted_amount, 0)'));

        $original = round((float) $contracts->sum(), 2);

        $billings = (float) TenantDb::table('construction_payment_certificates')
            ->whereIn('contract_id', $contractIds)
            ->whereIn('status', ['issued', 'paid'])
            ->whereDate('period_end', '<=', $end)
            ->max('gross_value_to_date');

        return [
            'contract_sum_original' => $original,
            'variations_approved' => round($approved, 2),
            'variations_pending' => round($pending, 2),
            'contract_value' => round($original + $approved, 2),
            'billings_to_date' => round($billings, 2),
        ];
    }

    /**
     * §4.4's three methods, and the job chooses which.
     *
     * **Cost to cost** is cost to date over forecast final cost. It needs no surveyor and is wrong in a specific
     * direction: a job running over reports more progress for spending more money, which is why §4.4 makes the method a
     * per-job decision rather than a default.
     *
     * **Surveyed** is §14's measured progress — the weighted percentage across the control accounts, which somebody
     * walked the site to produce.
     *
     * **Milestone** is the proportion of the contract's milestone value that has been achieved. Zero rather than a
     * fallback where the job has no milestones: a milestone job with nothing achieved is genuinely at nought per cent,
     * and quietly switching to cost-to-cost would report progress the contract does not recognise.
     */
    private function percentComplete(Job $job, string $method, string $start, float $costToDate, float $forecastFinal): float
    {
        return match ($method) {
            WipSnapshot::METHOD_COST_TO_COST => $forecastFinal <= 0.0
                ? 0.0
                : min(100.0, round($costToDate / $forecastFinal * 100, 4)),

            WipSnapshot::METHOD_SURVEYED => $this->surveyedPercent($job, $start),

            WipSnapshot::METHOD_MILESTONE => $this->milestonePercent($job, $start),

            default => 0.0,
        };
    }

    /**
     * §14's measured progress, weighted by budget at completion.
     *
     * Weighted rather than averaged, because a simple mean of the control accounts' percentages would let a 20,000
     * cost code count as much as a 20,000,000 one — and on a job with many small codes that is most of the answer.
     */
    private function surveyedPercent(Job $job, string $start): float
    {
        $measurements = ProgressMeasurement::query()
            ->whereIn('job_id', Job::query()->inSubtree($job)->select('id'))
            ->whereDate('period_start', '<=', $start)
            ->orderBy('period_start')
            ->get()
            // The latest measurement per control account: a code measured in June and again in July has one position.
            ->keyBy(fn (ProgressMeasurement $m): string => $m->cost_code_id.'|'.($m->wbs_node_id ?? '-'));

        $weighted = 0.0;
        $weight = 0.0;

        foreach ($measurements as $measurement) {
            $bac = (float) ($measurement->budget_at_completion ?? 0);

            if ($bac <= 0.0) {
                continue;
            }

            $weighted += $bac * (float) $measurement->percent_complete;
            $weight += $bac;
        }

        return $weight <= 0.0 ? 0.0 : min(100.0, round($weighted / $weight, 4));
    }

    /**
     * The proportion of milestone value achieved.
     *
     * Milestones are contract items of type `milestone` (§8.2), and achievement is what has been certified against them
     * — because a milestone is achieved when the certifier says it is, not when the contractor says so.
     */
    private function milestonePercent(Job $job, string $start): float
    {
        $end = CostPeriod::startFor($start)->copy()->endOfMonth()->toDateString();
        $jobIds = Job::query()->inSubtree($job)->pluck('id');

        $items = TenantDb::table('construction_contract_items as ci')
            ->join('construction_contracts as c', 'c.id', '=', 'ci.contract_id')
            ->whereIn('c.job_id', $jobIds)
            ->where('c.side', 'receivable')
            ->where('ci.item_type', 'milestone')
            ->where('ci.is_active', true)
            ->pluck('ci.scheduled_value', 'ci.id');

        if ($items->isEmpty()) {
            return 0.0;
        }

        $achieved = (float) TenantDb::table('construction_certificate_lines as cl')
            ->join('construction_payment_certificates as pc', 'pc.id', '=', 'cl.payment_certificate_id')
            ->whereIn('pc.status', ['issued', 'paid'])
            ->whereDate('pc.period_end', '<=', $end)
            ->whereIn('cl.contract_item_id', $items->keys()->all())
            ->sum('cl.cumulative_work_value');

        $total = (float) $items->sum();

        return $total <= 0.0 ? 0.0 : min(100.0, round($achieved / $total * 100, 4));
    }

    /** The most recent locked snapshot before this month, which is what a movement is measured from. */
    private function previousLocked(Job $job, string $start): ?WipSnapshot
    {
        return WipSnapshot::query()
            ->where('job_id', $job->getKey())
            ->locked()
            ->whereDate('period_start', '<', $start)
            ->orderByDesc('period_start')
            ->first();
    }
}
