<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Modules\ConstructionCosting\Models\WipSnapshot;
use App\Modules\ConstructionCosting\Support\AccrualRun;
use App\Modules\ConstructionCosting\Support\CloseCheck;
use App\Modules\ConstructionCosting\Support\CloseChecklist;
use App\Support\TenantTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Closing a month — `docs/construction-management-plan.md` §3.4 and §4.3.
 *
 * §4.3 lists five mechanisms that turn §4's reconciliation from a report into a control, and three of them are this
 * class:
 *
 *  2. **"The period cannot be closed while unbalanced."**
 *  3. **"Unless a user holding `ConstructionPeriodForceClose` accepts the difference with a stated reason."**
 *  4. **"A forced close never fudges the ledger. No plug entry, no balancing figure. Both sides stay true and the
 *     difference stays visible in every later period until the cause is fixed."**
 *
 * The fourth is the one worth reading twice, because it is what makes the third safe. Forcing a close records a name
 * against a decision and changes not one figure in either ledger. There is no adjustment, no suspense posting and no
 * rounding line — and a test asserts the strong form of that rather than trusting it.
 *
 * **There is no reopen and there never will be.** §3.4: reopening a signed-off period to slot one invoice in
 * "invalidates the WIP snapshot, the client certificate and the GL summary that all depended on that period's total —
 * and silently changing a closed month is the precise failure this whole design exists to prevent". A late cost lands in
 * the earliest open period with its real date kept and `is_late_for_period` set, which is a fact the Late Costs report
 * can show rather than a silent restatement.
 *
 * **And two blockers are this phase's rather than the plan's**, both added because the failure they prevent is silent
 * and permanent rather than merely wrong:
 *
 *  - **Cost still awaiting the general ledger.** `ConstructionGlPostingService` refuses to post into a closed period, so
 *    closing a month with pending entries orphans them from the accounts for good. The reconciliation would report a
 *    difference every month afterwards with no way left to fix it.
 *  - **An unlocked WIP position.** §4.4's whole exception is that a locked position does not move. One left unlocked
 *    against a closed month keeps recomputing from a forecast that has since changed, so the figure a bank was shown and
 *    the figure on the screen drift apart with nothing to say when.
 */
class PeriodCloseService
{
    public function __construct(
        private CostLedger $ledger,
        private AccrualService $accruals,
        private WipService $wip,
    ) {}

    /**
     * Every gate on the way out of the month, computed and not enforced — the read behind the button.
     *
     * Blocking and advisory checks are both returned. A check that fails without blocking is something somebody should
     * know before they sign, and hiding it because it is not fatal is how a month gets closed on facts nobody was shown.
     */
    public function checks(CostPeriod $period): CloseChecklist
    {
        $start = $period->period_start->toDateString();

        return new CloseChecklist($start, [
            $this->reconciliationCheck($start),
            $this->pendingCheck($start),
            $this->wipCheck($start),
            $this->accrualCheck($start),
            $this->lateCostCheck($start),
        ]);
    }

    /**
     * Close the month, with every gate enforced.
     *
     * The control totals are recorded on the period as it closes, which is §3.4's own instruction: "the control totals
     * live here rather than in a report so that closing a period *records* what the two ledgers said at the time. A
     * reconciliation computed later from live data cannot tell you what the figures were on the day somebody signed the
     * certificate."
     */
    public function close(CostPeriod $period, ?string $notes = null): CostPeriod
    {
        $this->refuseIfClosed($period);

        $checklist = $this->checks($period);

        if (! $checklist->canClose()) {
            throw new InvalidArgumentException(
                "{$period->label()} cannot be closed. ".$checklist->describe()
                .' Somebody holding ConstructionPeriodForceClose may close it anyway with a stated reason — which '
                .'corrects nothing and leaves both ledgers exactly as they are.'
            );
        }

        return $this->write($period, $notes, forced: false);
    }

    /**
     * §4.3's third mechanism: close over an unexplained difference, **with a stated reason**.
     *
     * **Nothing is corrected.** §4.3's fourth mechanism is the promise attached: no plug entry, no balancing figure,
     * both ledgers exactly as they were, and the difference visible in every later period until its cause is fixed.
     *
     * What is recorded is the reason *and the facts it was a reason for* — every check it overrode, in words. A reason
     * with no facts beside it ("closing anyway, the client needs the report") tells whoever reads it in a year nothing
     * about what was known at the time.
     */
    public function forceClose(CostPeriod $period, string $reason): CostPeriod
    {
        $this->refuseIfClosed($period);

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Forcing a close needs a reason. Nothing is being corrected — both ledgers stay exactly as they are and '
                .'the difference stays visible in every later period — so the reason is the only thing this records.'
            );
        }

        $checklist = $this->checks($period);

        if ($checklist->canClose() && $checklist->warnings() === []) {
            throw new InvalidArgumentException(
                "{$period->label()} passes every check, so there is nothing to force. Close it normally."
            );
        }

        $note = 'Forced close: '.$reason;

        foreach ($checklist->overriddenSentences() as $sentence) {
            $note .= "\nOverridden — ".$sentence;
        }

        return $this->write($period, $note, forced: true);
    }

    /**
     * Open a month — §4.5's reversal belongs here, not to the close.
     *
     * §4.5: "its own failure mode — the reversal not running, so the accrual and the real invoice both sit in the ledger
     * and the job costs double for a month — is why the reversal belongs to period *open* rather than period close." A
     * step attached to opening the month runs before anybody looks at the figures. A step attached to closing it runs
     * after everybody has.
     *
     * Creating the period and rolling the accruals in one act, because a month that exists without its accruals rolled
     * is the state that failure mode describes.
     *
     * Returns the unwind rather than the period, because the unwind is the part worth reporting: the period is a row
     * anybody can look up, and "reversed nothing, re-accrued 400,000 of goods received not invoiced" is the sentence
     * somebody needs on screen.
     */
    public function open(string $periodStart): AccrualRun
    {
        $period = CostPeriod::forDate($periodStart);

        if ($period->isClosed()) {
            throw new InvalidArgumentException(
                "{$period->label()} is {$period->status} and there is no way back into it. §3.4: reopening a signed-off "
                .'period invalidates the WIP snapshot, the client certificate and the GL summary that all depended on '
                .'its total. A late cost lands in the earliest open period with its own date kept.'
            );
        }

        return $this->accruals->open($period->period_start->toDateString());
    }

    /**
     * The write, shared by both doors.
     *
     * One method rather than two, because §4.3's fourth mechanism is a promise about what a *forced* close does not do —
     * and the cheapest way to keep that promise is for the forced path to run exactly the same code, differing only in
     * the note it records.
     */
    private function write(CostPeriod $period, ?string $notes, bool $forced): CostPeriod
    {
        $start = $period->period_start->toDateString();
        $reconciliation = Reconciliation::latestFor($start);

        return TenantTransaction::run(function () use ($period, $start, $notes, $reconciliation, $forced): CostPeriod {
            // The ledger's own primitive does the status change and the job-cost control total, and it is deliberately
            // still the only writer of those — this method adds the gate and the general-ledger side rather than
            // duplicating what §3.4 already built.
            $this->ledger->closePeriod($period);

            $period->update([
                'gl_control_total' => $reconciliation?->gl_cost ?? $this->glControlTotal($start),
                'difference' => $reconciliation?->difference,
                'notes' => trim(($period->notes ? $period->notes."\n" : '').($notes ?? ''))
                    ?: ($forced ? 'Forced close.' : null),
            ]);

            /*
             * The reconciliation is marked as the period's, and the period as reconciled — but only when it actually
             * balanced.
             *
             * §3.4's third status is `reconciled`, and it has to mean something stronger than `closed`. A forced close
             * over a difference is closed and not reconciled, which is exactly the distinction somebody reading the
             * period list needs.
             */
            if ($reconciliation?->isBalanced()) {
                $period->update([
                    'status' => CostPeriod::STATUS_RECONCILED,
                    'reconciled_at' => now(),
                ]);
            }

            return $period->refresh();
        });
    }

    private function refuseIfClosed(CostPeriod $period): void
    {
        if ($period->isClosed()) {
            throw new InvalidArgumentException(
                "{$period->label()} is already {$period->status}, and there is no reopen. §3.4: silently changing a "
                .'closed month is the precise failure this design exists to prevent.'
            );
        }
    }

    /**
     * §4.3's second mechanism, as a check.
     *
     * **A month nobody has reconciled blocks too**, and that is not the same failure as an unbalanced one — it is worse.
     * §4 opens with "a second ledger that nobody proves is a second ledger that is wrong", and a close gate that only
     * caught *proved* differences would wave through every company that never runs the report.
     */
    private function reconciliationCheck(string $start): CloseCheck
    {
        $run = Reconciliation::latestFor($start);

        if ($run === null) {
            return CloseCheck::blocks(
                'reconciled',
                'The month has never been reconciled',
                'A second ledger that nobody proves is a second ledger that is wrong. Record a run on the '
                .'Reconciliation page, or let the weekly construction:reconcile do it.',
            );
        }

        if ($run->blocksClose()) {
            return CloseCheck::blocks(
                'reconciled',
                'The two ledgers do not agree',
                'Difference '.number_format((float) $run->difference, 2).'. Fix the cause, or accept the difference on '
                .'the Reconciliation page with a stated reason — which corrects nothing and leaves it visible in every '
                .'later period.',
            );
        }

        return CloseCheck::pass(
            'reconciled',
            'The two ledgers agree',
            $run->isAccepted()
                ? 'A difference of '.number_format((float) $run->difference, 2).' was accepted, not corrected. It is '
                    .'still there.'
                : 'Difference nil.',
        );
    }

    /**
     * **Cost still awaiting the general ledger blocks**, and this is a phase-11e addition rather than the plan's.
     *
     * `ConstructionGlPostingService` refuses to post into a closed period, so closing a month with pending entries
     * orphans them from the accounts permanently. The reconciliation would report a difference every month afterwards
     * with no way left to fix it — a silent, permanent loss, which is a harder failure than an unexplained difference.
     */
    private function pendingCheck(string $start): CloseCheck
    {
        $pending = CostEntry::query()->awaitingGl()->inPeriod($start);
        $count = $pending->count();

        if ($count === 0) {
            return CloseCheck::pass('pending', 'Nothing is awaiting the general ledger');
        }

        $amount = (float) $pending->sum('amount');
        $missing = $this->missingAccounts($start);

        return CloseCheck::blocks(
            'pending',
            'Cost is still awaiting the general ledger',
            $count.' entr'.($count === 1 ? 'y' : 'ies').' worth '.number_format($amount, 2).'. Posting refuses to '
            .'reach a closed month, so closing now orphans them from the accounts for good.'
            .($missing === [] ? ' Post the period from Cost periods.' : ' First nominate: '.implode(', ', $missing).'.'),
        );
    }

    /**
     * An unlocked WIP position blocks — also this phase's addition, and for a related reason.
     *
     * §4.4's exception exists because a locked position does not move. One left unlocked against a closed month keeps
     * recomputing from a forecast that has since changed, so the figure a bank was shown and the figure on the screen
     * drift apart with nothing to say when they parted.
     *
     * A job with **no** position does not block. WIP is used or it is not, and a company that computes none should not be
     * unable to close a month — but a job with a method chosen and no position is worth naming, which is the warning
     * below.
     */
    private function wipCheck(string $start): CloseCheck
    {
        $unlocked = WipSnapshot::query()->forPeriod($start)->whereNull('locked_at')->count();

        if ($unlocked > 0) {
            return CloseCheck::blocks(
                'wip_locked',
                'A work-in-progress position is still live',
                $unlocked.' position(s) recompute every time somebody opens them. Against a closed month that means the '
                .'figure a bank was shown and the figure on the screen drift apart. Lock them on the Work in progress '
                .'page.',
            );
        }

        $uncomputed = Job::query()->live()
            ->whereNotNull('percent_complete_method')
            ->whereNotIn('id', WipSnapshot::query()->forPeriod($start)->select('job_id'))
            ->pluck('code');

        if ($uncomputed->isNotEmpty()) {
            return CloseCheck::warns(
                'wip_locked',
                'A live job has no work-in-progress position for the month',
                $uncomputed->implode(', ').'. Each has a percent-complete method set, so a position was expected. A WIP '
                .'report missing a job is a balance sheet missing a contract.',
            );
        }

        return CloseCheck::pass('wip_locked', 'Every work-in-progress position is locked');
    }

    /**
     * Whether the month's accruals were ever rolled — advisory, not blocking.
     *
     * §4.5 puts the roll at period *open*, so a month closed without one is a month whose accruals were never unwound
     * — which the *next* open will fix, since the unwind reverses everything standing whatever period raised it. Worth
     * saying, not worth blocking.
     */
    private function accrualCheck(string $start): CloseCheck
    {
        $rolled = DB::table('construction_cost_batches')
            ->whereIn('kind', ['accrual', 'accrual_reversal'])
            ->whereDate('period_start', $start)
            ->exists();

        $standing = CostEntry::query()
            ->where('kind', CostEntry::KIND_ACCRUAL)
            ->whereNull('reverses_id')
            ->whereNull('reversed_by_id')
            ->whereDate('posting_period', '<=', $start)
            ->count();

        if ($rolled || $standing === 0) {
            return CloseCheck::pass('accruals', 'Accruals were rolled into this month');
        }

        return CloseCheck::warns(
            'accruals',
            'Accruals were never rolled into this month',
            $standing.' accrual(s) from this month or earlier are still standing. The next month\'s open will unwind '
            .'them — the unwind reverses everything standing whatever raised it — so this is worth knowing rather than '
            .'fixing now.',
        );
    }

    /** Late costs — §3.4's report, as a line on the close checklist. */
    private function lateCostCheck(string $start): CloseCheck
    {
        $late = CostEntry::query()->inPeriod($start)->where('is_late_for_period', true);
        $count = $late->count();

        if ($count === 0) {
            return CloseCheck::pass('late_costs', 'No cost arrived late for its own month');
        }

        return CloseCheck::warns(
            'late_costs',
            'Cost arrived after the month it belonged to',
            $count.' entr'.($count === 1 ? 'y' : 'ies').' worth '.number_format((float) $late->sum('amount'), 2)
            .' was incurred in an earlier month that had already closed, so it landed here with its own date kept. '
            .'That is the design, not an error — but the earlier month\'s total is not what this cost belongs to.',
        );
    }

    /** Which posting rules have cost waiting and no account, so the pending blocker can name the fix. */
    private function missingAccounts(string $start): array
    {
        return CostEntry::query()
            ->awaitingGl()
            ->inPeriod($start)
            ->whereNotNull('gl_purpose')
            ->distinct()
            ->pluck('gl_purpose')
            ->filter(fn (string $purpose): bool => ControlAccount::forPurpose($purpose) === null)
            ->map(fn (string $purpose): string => ControlAccount::PURPOSES[$purpose] ?? $purpose)
            ->values()
            ->all();
    }

    /**
     * The general ledger's cost for the month, for a close with no reconciliation run behind it.
     *
     * Only reachable through a forced close, since an unreconciled month blocks — and it is here so that even a forced
     * close records *both* control totals. §3.4 wants the period to say what each ledger held on the day; a forced close
     * that recorded only one side would leave the very month somebody had doubts about the least explicable.
     */
    private function glControlTotal(string $start): float
    {
        $accountIds = ControlAccount::accountIdsOfKind(ControlAccount::KIND_COST);

        if ($accountIds === []) {
            return 0.0;
        }

        $end = CostPeriod::startFor($start)->copy()->endOfMonth()->toDateString();

        $row = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereIn('jel.account_id', $accountIds)
            ->where('je.is_posted', true)
            ->whereDate('je.entry_date', '>=', $start)
            ->whereDate('je.entry_date', '<=', $end)
            ->selectRaw('COALESCE(SUM(jel.debit_amount), 0) as debits, COALESCE(SUM(jel.credit_amount), 0) as credits')
            ->first();

        return round((float) $row->debits - (float) $row->credits, 2);
    }
}
