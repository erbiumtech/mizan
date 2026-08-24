<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Modules\ConstructionCosting\Support\ReconciliationCause;
use App\Modules\ConstructionCosting\Support\ReconciliationResult;
use App\Support\TenantDb;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Proving the two ledgers equal — `docs/construction-management-plan.md` §4.2 and §4.3.
 *
 * §4 opens with the sentence this whole class exists to answer: **"A second ledger that nobody proves is a second ledger
 * that is wrong."** §3 bought a job-cost ledger with unit rates, non-GL costs and its own calendar; the price is that
 * nothing forces the two to agree, and this is the invoice for it.
 *
 * **The arithmetic, verbatim from §4.2:**
 *
 * ```
 * GL cost for the period
 *   less   GL cost carrying no job        [UNALLOCATED — shown, never spread]
 *   plus   job cost still awaiting the GL [the reconciling item]
 * = Expected job-cost total
 *   vs     Σ construction_cost_entries.amount where gl_treatment != 'memo'
 * Difference                              must be 0.00
 * ```
 *
 * **"Posted entries only — the same filter `FinancialReportService` uses, which must be matched exactly or the two sides
 * will disagree about drafts and nobody will know why."** That filter is `journal_entries.is_posted = true` with
 * `entry_date` inside the window, and it is written here in exactly that form for exactly that reason. A draft journal is
 * not the general ledger.
 *
 * **"Shown, never spread"** shapes the unallocated line. A cost journalled straight to `5020` from the Accounting panel
 * belongs to no job; apportioning it across jobs would balance the report and put money on jobs nobody charged it to.
 * It comes off whole, on its own line, where somebody has to look at it.
 *
 * **And the cause breakdown is not a nicety.** §4.2: the drill-down by cause "is what makes it a tool rather than a
 * number". A difference with no causes attached is a figure somebody screenshots and argues about for a fortnight.
 */
class ReconciliationService
{
    /**
     * Compute a period's reconciliation without storing it — the read behind the page.
     *
     * **Refuses to pretend when no cost control account is nominated.** With none, the GL side is zero, the job side is
     * whatever was recorded, and the difference is the entire job cost — a report that looks like a catastrophe and
     * means nobody has set the module up. §17.6's rule about a rate with no denominator, applied to a reconciliation:
     * say what is missing rather than print a number that reads as a finding.
     */
    public function compute(string $periodStart): ReconciliationResult
    {
        $period = CostPeriod::startFor($periodStart);
        $start = $period->toDateString();
        $end = $period->copy()->endOfMonth()->toDateString();

        $costAccountIds = ControlAccount::accountIdsOfKind(ControlAccount::KIND_COST);

        if ($costAccountIds === []) {
            return new ReconciliationResult(
                periodStart: $start,
                glCost: 0.0, unallocatedGlCost: 0.0, pendingJobCost: 0.0,
                expectedJobCost: 0.0, jobCost: 0.0, difference: 0.0,
                causes: [],
                scopeWarning: 'No job-cost control account is nominated, so there is no general-ledger side to '
                    .'reconcile against and no difference can be computed. Nominate the accounts this company keeps job '
                    .'cost on under Control accounts — until then this report would show the whole of job cost as a '
                    .'difference, which reads as a catastrophe and means nobody has set the module up.',
            );
        }

        $glCost = $this->glCostFor($costAccountIds, $start, $end);
        $unallocated = $this->unallocatedGlCost($costAccountIds, $start, $end);
        $pending = $this->pendingJobCost($start);
        $jobCost = $this->jobCost($start);

        $expected = round($glCost - $unallocated['amount'] + $pending, 2);
        $difference = round($expected - $jobCost, 2);

        return new ReconciliationResult(
            periodStart: $start,
            glCost: $glCost,
            unallocatedGlCost: $unallocated['amount'],
            pendingJobCost: $pending,
            expectedJobCost: $expected,
            jobCost: $jobCost,
            difference: $difference,
            causes: $this->causes($start, $end, $costAccountIds, $unallocated, $difference),
        );
    }

    /**
     * Compute and store — §4.3's first mechanism, and what the command calls.
     *
     * The run is stored whether or not it balances, because §4.3 wants a history: a period that has been balanced every
     * month and broke in March is a different problem from one nobody has ever proved, and only a series of rows can say
     * which.
     */
    public function run(string $periodStart, ?string $notes = null): Reconciliation
    {
        $result = $this->compute($periodStart);

        return Reconciliation::create([
            'period_start' => $result->periodStart,
            'run_at' => now(),
            'run_by' => auth()->id(),
            'gl_cost' => $result->glCost,
            'unallocated_gl_cost' => $result->unallocatedGlCost,
            'pending_job_cost' => $result->pendingJobCost,
            'expected_job_cost' => $result->expectedJobCost,
            'job_cost' => $result->jobCost,
            'difference' => $result->difference,
            'status' => $result->isBalanced()
                ? Reconciliation::STATUS_BALANCED
                : Reconciliation::STATUS_UNBALANCED,
            'causes' => $result->causesForStorage(),
            'notes' => $notes ?? $result->scopeWarning,
        ]);
    }

    /**
     * §4.3's third mechanism: accept a difference **with a stated reason**.
     *
     * **This fixes nothing and is not meant to.** §4.3's fourth mechanism is the promise that goes with it: "a forced
     * close never fudges the ledger. No plug entry, no balancing figure. Both sides stay true and the difference stays
     * visible in every later period until the cause is fixed." What this records is a name against a decision to carry
     * on — which is a control precisely because it is uncomfortable to sign.
     */
    public function accept(Reconciliation $reconciliation, string $reason): Reconciliation
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Accepting a difference needs a reason. Nothing is being corrected — the difference stays visible in '
                .'every later period until its cause is fixed — so the reason is the only thing this records.'
            );
        }

        if ($reconciliation->isBalanced()) {
            throw new InvalidArgumentException(
                'That run balances, so there is no difference to accept.'
            );
        }

        $reconciliation->update([
            'status' => Reconciliation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by' => auth()->id(),
            'accepted_reason' => $reason,
        ]);

        return $reconciliation->refresh();
    }

    /**
     * The general ledger's cost for the period.
     *
     * **The filter is `FinancialReportService`'s, exactly.** §4.2 warns what happens otherwise: "which must be matched
     * exactly or the two sides will disagree about drafts and nobody will know why." Debits less credits, so a credit
     * note against a cost account reduces the figure the way it reduces the accounts.
     *
     * @param  array<int, int>  $costAccountIds
     */
    private function glCostFor(array $costAccountIds, string $start, string $end): float
    {
        $rows = TenantDb::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereIn('jel.account_id', $costAccountIds)
            ->where('je.is_posted', true)
            ->whereDate('je.entry_date', '>=', $start)
            ->whereDate('je.entry_date', '<=', $end)
            ->selectRaw('COALESCE(SUM(jel.debit_amount), 0) as debits, COALESCE(SUM(jel.credit_amount), 0) as credits')
            ->first();

        return round((float) $rows->debits - (float) $rows->credits, 2);
    }

    /**
     * GL cost with no job-cost entry behind it — §4.2's UNALLOCATED line.
     *
     * A journal entry counts as *accounted for* when at least one cost entry names it. Construction's own summary
     * postings do so by construction (§4.1's batch link), and a mirrored entry does so through the invoice's journal
     * entry — which is why Phase 11c made `InvoiceAllocationService` carry it.
     *
     * Everything else is somebody having posted a cost to a control account outside this module. §4.2 calls that "by a
     * wide margin the most common cause", and it is the one this line exists to make impossible to miss.
     *
     * @param  array<int, int>  $costAccountIds
     * @return array{amount: float, count: int, rows: array<int, array<string, mixed>>}
     */
    private function unallocatedGlCost(array $costAccountIds, string $start, string $end): array
    {
        $accountedFor = CostEntry::query()
            ->whereNotNull('journal_entry_id')
            ->distinct()
            ->pluck('journal_entry_id')
            ->all();

        $rows = TenantDb::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereIn('jel.account_id', $costAccountIds)
            ->where('je.is_posted', true)
            ->whereDate('je.entry_date', '>=', $start)
            ->whereDate('je.entry_date', '<=', $end)
            ->when($accountedFor !== [], fn ($q) => $q->whereNotIn('je.id', $accountedFor))
            ->groupBy('je.id', 'je.entry_number', 'je.entry_date', 'je.memo')
            ->selectRaw('je.id, je.entry_number, je.entry_date, je.memo, '
                .'COALESCE(SUM(jel.debit_amount), 0) - COALESCE(SUM(jel.credit_amount), 0) as amount')
            ->get();

        return [
            'amount' => round((float) $rows->sum('amount'), 2),
            'count' => $rows->count(),
            'rows' => $rows->map(fn ($row): array => [
                'entry_number' => $row->entry_number,
                'entry_date' => $row->entry_date,
                'memo' => $row->memo,
                'amount' => round((float) $row->amount, 2),
            ])->all(),
        ];
    }

    /** Job cost that has not reached the general ledger yet — the reconciling item. */
    private function pendingJobCost(string $start): float
    {
        return round((float) CostEntry::query()->awaitingGl()->inPeriod($start)->sum('amount'), 2);
    }

    /**
     * The job-cost side: everything except memo.
     *
     * §3.2's four treatments, and memo is the one that "deliberately never will" reach the accounts — a notional tender
     * comparison, an unposted overhead allocation. Including it would report a difference equal to a figure nobody ever
     * intended the general ledger to see.
     */
    private function jobCost(string $start): float
    {
        return round((float) CostEntry::query()
            ->inPeriod($start)
            ->where('gl_treatment', '!=', CostEntry::GL_MEMO)
            ->sum('amount'), 2);
    }

    /**
     * §4.2's drill-down, in the order the plan lists it.
     *
     * @param  array<int, int>  $costAccountIds
     * @param  array{amount: float, count: int, rows: array<int, array<string, mixed>>}  $unallocated
     * @return array<int, ReconciliationCause>
     */
    private function causes(string $start, string $end, array $costAccountIds, array $unallocated, float $difference): array
    {
        $causes = [
            $this->pendingByAge($start),
            $this->glWithNoCostEntry($unallocated),
            $this->journalReversedOrUnposted($start),
            $this->unallocatedPurchaseInvoices($start, $end),
            $this->closedJobEntries($start),
            $this->absorptionGaps($start),
        ];

        // Rounding last, and only what the named causes do not account for. §4.2: "isolated so it cannot be used to
        // explain anything else."
        $explained = array_sum(array_map(fn (ReconciliationCause $c): float => $c->amount, $causes));
        $residual = round($difference - $explained, 2);

        $causes[] = new ReconciliationCause(
            key: 'rounding',
            label: 'Unexplained residual',
            count: abs($residual) < 0.01 ? 0 : 1,
            amount: $residual,
            explanation: abs($residual) <= Reconciliation::TOLERANCE
                ? 'Within a paisa, which is rounding across a month of two-decimal entries and explains nothing else.'
                : 'Not accounted for by any cause above. This is the figure worth chasing: a cost account posted to '
                    .'outside the control-account list, or a journal whose date falls in this month while its cost '
                    .'entry fell in another.',
        );

        return $causes;
    }

    /**
     * Entries still pending, **by age**, which is what makes the list actionable.
     *
     * A month-old pending entry is a posting run nobody has pressed. A six-month-old one is an absorption account nobody
     * has nominated, and §7.3 says what that costs: "job cost exceeds GL cost by exactly the burden, growing every
     * month, with no error anywhere". One number could not tell them apart.
     */
    private function pendingByAge(string $start): ReconciliationCause
    {
        $entries = CostEntry::query()
            ->awaitingGl()
            ->whereDate('posting_period', '<=', $start)
            ->get(['id', 'posting_period', 'amount']);

        $reference = Carbon::parse($start);
        $buckets = ['this month' => 0.0, 'one to three months' => 0.0, 'over three months' => 0.0];

        foreach ($entries as $entry) {
            // Absolute and floored, because `diffInMonths` is signed in Carbon 3 and a June entry read against an
            // August reference comes back as -2. Unsigned, that bucketed every old entry as "this month" — which is the
            // one distinction this cause exists to draw.
            $months = (int) floor(abs(Carbon::parse($entry->posting_period)->diffInMonths($reference)));
            $key = $months < 1 ? 'this month' : ($months <= 3 ? 'one to three months' : 'over three months');
            $buckets[$key] += (float) $entry->amount;
        }

        $rows = [];

        foreach ($buckets as $label => $amount) {
            if (abs($amount) >= 0.01) {
                $rows[] = ['age' => $label, 'amount' => round($amount, 2)];
            }
        }

        return new ReconciliationCause(
            key: 'pending_by_age',
            label: 'Job cost awaiting the general ledger',
            count: $entries->count(),
            // Zero, because pending cost is already a *line* of §4.2's statement rather than an unexplained cause. It
            // appears here so somebody can see its age; counting it twice would make the residual wrong.
            amount: 0.0,
            explanation: $entries->isEmpty()
                ? 'Nothing is waiting.'
                : 'Already allowed for in the statement above. Shown by age because a month-old pending entry is a '
                    .'posting run nobody has pressed and a six-month-old one is an absorption account nobody has '
                    .'nominated.',
            rows: $rows,
        );
    }

    /**
     * §4.2's most common cause: **somebody journalled a cost straight to a control account from the Accounting panel.**
     *
     * The accounts are perfectly correct. The job knows nothing about it. Nothing else in the application would ever
     * mention it.
     *
     * @param  array{amount: float, count: int, rows: array<int, array<string, mixed>>}  $unallocated
     */
    private function glWithNoCostEntry(array $unallocated): ReconciliationCause
    {
        return new ReconciliationCause(
            key: 'gl_without_cost_entry',
            label: 'General ledger cost with no job behind it',
            count: $unallocated['count'],
            // Zero for the same reason as pending: it is a line of the statement, subtracted whole, never spread.
            amount: 0.0,
            explanation: $unallocated['count'] === 0
                ? 'Every posted cost on a control account has a job-cost entry behind it.'
                : 'Subtracted whole above and never apportioned across jobs. Usually somebody posting a cost straight '
                    .'to a control account from the Accounting panel, or a purchase invoice posted with no allocation. '
                    .'The accounts are right and the job is under-costed.',
            rows: $unallocated['rows'],
        );
    }

    /**
     * **The nastiest**, in §4.2's own words: a cost entry pointing at a journal entry that was later reversed or
     * unposted.
     *
     * Nasty because the correction happened in another module and this one was never told. The job still carries the
     * cost, the general ledger no longer does, and both look internally consistent.
     *
     * **Two shapes, and neither is a flag on the journal entry**, because Accounting does not keep one:
     *
     *  - *Unposted*: the entry the cost points at is not posted, so `FinancialReportService`'s filter — and therefore the
     *    GL side of this reconciliation — cannot see it.
     *  - *Reversed*: `JournalEntryService::reverse()` writes a new entry of type `reversing` whose `reference` is the
     *    original's `entry_number`. And it dates the reversal **today**, deliberately, so a March cost reversed in July
     *    leaves March's GL side intact and July's short — which is precisely why this cause is worth its own section
     *    rather than being left to the residual.
     */
    private function journalReversedOrUnposted(string $start): ReconciliationCause
    {
        $rows = TenantDb::table('construction_cost_entries as ce')
            ->join('journal_entries as je', 'je.id', '=', 'ce.journal_entry_id')
            ->whereDate('ce.posting_period', $start)
            ->where('ce.gl_treatment', '!=', CostEntry::GL_MEMO)
            ->where(function ($outer) {
                $outer
                    ->where('je.is_posted', false)
                    ->orWhereExists(fn ($q) => $q
                        ->from('journal_entries as rev')
                        ->where('rev.entry_type', 'reversing')
                        ->where('rev.is_posted', true)
                        ->whereColumn('rev.reference', 'je.entry_number'));
            })
            ->select(['ce.id', 'ce.amount', 'je.entry_number', 'je.status'])
            ->get();

        /*
         * A mirrored entry whose invoice was never posted is the same failure one step earlier, and it is common enough
         * to be worth naming separately in the sentence: the allocation was made before somebody posted the invoice, so
         * the job carries a cost the accounts have not yet seen and nothing marks it as pending.
         */
        $unposted = CostEntry::query()
            ->inPeriod($start)
            ->where('gl_treatment', CostEntry::GL_MIRRORED)
            ->whereNull('journal_entry_id')
            ->get(['id', 'amount']);

        $amount = round((float) $rows->sum('amount') + (float) $unposted->sum('amount'), 2);
        $count = $rows->count() + $unposted->count();

        return new ReconciliationCause(
            key: 'journal_reversed_or_unposted',
            label: 'Job cost whose general-ledger side is gone',
            count: $count,
            amount: $amount,
            explanation: $count === 0
                ? 'Every job cost claiming a general-ledger side has a posted journal behind it.'
                : 'A journal was reversed or unposted in another module and this one was never told, or a mirrored '
                    .'entry was written against an invoice nobody has posted. The job still carries the cost, the '
                    .'accounts no longer do, and both look internally consistent.',
            rows: $rows->map(fn ($row): array => [
                'cost_entry_id' => $row->id,
                'entry_number' => $row->entry_number,
                'status' => $row->status,
                'amount' => round((float) $row->amount, 2),
            ])->all(),
        );
    }

    /**
     * **Rendered even when empty**, which is §4.2's explicit instruction for this cause and this cause alone.
     *
     * §5 calls an unallocated purchase invoice "the single most likely silent failure in the module": the accounts are
     * perfectly correct and the job is under-costed. A section that vanished when the list was empty would be
     * indistinguishable from a section nobody had built — so a zero somebody has seen is worth more than a blank.
     *
     * Guarded on Invoicing, because `construction_costing` does not require it (§18.1) and a company with no purchase
     * invoices cannot have unallocated ones.
     */
    private function unallocatedPurchaseInvoices(string $start, string $end): ReconciliationCause
    {
        if (! modules()->enabled('invoicing')) {
            return new ReconciliationCause(
                key: 'unallocated_purchase_invoices',
                label: 'Purchase invoices with no job allocation',
                count: 0,
                amount: 0.0,
                explanation: 'Invoicing is not licensed, so there are no purchase invoices to allocate.',
                renderWhenEmpty: true,
            );
        }

        $rows = TenantDb::table('invoices as i')
            ->where('i.kind', 'purchase')
            ->whereDate('i.invoice_date', '>=', $start)
            ->whereDate('i.invoice_date', '<=', $end)
            ->whereNotIn('i.status', ['draft', 'void'])
            ->whereNotExists(fn ($q) => $q
                ->from('construction_invoice_allocations as a')
                ->whereColumn('a.invoice_id', 'i.id'))
            ->select(['i.id', 'i.invoice_number', 'i.invoice_date', 'i.total'])
            ->get();

        return new ReconciliationCause(
            key: 'unallocated_purchase_invoices',
            label: 'Purchase invoices with no job allocation',
            count: $rows->count(),
            // Not added to the residual: an unallocated invoice may be a genuine overhead that belongs to no job. What
            // this cause does is put it in front of somebody, which is all §5 asks of it.
            amount: 0.0,
            explanation: $rows->isEmpty()
                ? 'None — every purchase invoice in the period has been attributed to a job, or is still draft.'
                : 'Each of these leaves the accounts perfectly correct and a job under-costed. Some are genuine '
                    .'overheads belonging to no job; the point is that somebody has looked.',
            renderWhenEmpty: true,
            rows: $rows->map(fn ($row): array => [
                'invoice_number' => $row->invoice_number,
                'invoice_date' => $row->invoice_date,
                'total' => round((float) $row->total, 2),
            ])->all(),
        );
    }

    /**
     * Cost landing on a job somebody has closed.
     *
     * Not an error in itself — a retention release or a defect rectification lands on a closed job legitimately — but it
     * is the shape of a mis-coded invoice, and §1.2's job tree makes it easy to pick the wrong one from a list.
     */
    private function closedJobEntries(string $start): ReconciliationCause
    {
        $rows = TenantDb::table('construction_cost_entries as ce')
            ->join('construction_jobs as j', 'j.id', '=', 'ce.job_id')
            ->whereDate('ce.posting_period', $start)
            ->whereIn('j.status', Job::DORMANT_STATUSES)
            ->groupBy('j.id', 'j.code', 'j.name', 'j.status')
            ->selectRaw('j.code, j.name, j.status, COUNT(*) as entries, COALESCE(SUM(ce.amount), 0) as amount')
            ->get();

        return new ReconciliationCause(
            key: 'closed_job_entries',
            label: 'Cost on a job that is no longer live',
            count: (int) $rows->sum('entries'),
            // Zero: this cost is in both ledgers and explains no difference. It is here because it is the shape of a
            // mis-coding, not because it fails to reconcile.
            amount: 0.0,
            explanation: $rows->isEmpty()
                ? 'No cost landed on a closed or cancelled job this month.'
                : 'Legitimate for a retention release or defect rectification, and the shape of a mis-coded invoice '
                    .'otherwise. Reconciles either way — this is a coding check, not a difference.',
            rows: $rows->map(fn ($row): array => [
                'job' => $row->code.' — '.$row->name,
                'status' => $row->status,
                'entries' => (int) $row->entries,
                'amount' => round((float) $row->amount, 2),
            ])->all(),
        );
    }

    /**
     * **Absorption gaps** — pending cost whose credit account was never nominated.
     *
     * §7.3's failure, quantified: "charge either and never absorb it and job cost exceeds GL cost by exactly the burden,
     * growing every month, with no error anywhere". §11a refuses to post these and reports them; this is the same fact
     * seen from the reconciliation, where it is the reason the difference will not close.
     */
    private function absorptionGaps(string $start): ReconciliationCause
    {
        $purposes = CostEntry::query()
            ->awaitingGl()
            ->whereDate('posting_period', '<=', $start)
            ->whereNotNull('gl_purpose')
            ->selectRaw('gl_purpose, COUNT(*) as entries, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('gl_purpose')
            ->get();

        $rows = [];
        $amount = 0.0;
        $count = 0;

        foreach ($purposes as $row) {
            if (ControlAccount::forPurpose($row->gl_purpose) !== null) {
                continue;
            }

            $rows[] = [
                'purpose' => ControlAccount::PURPOSES[$row->gl_purpose] ?? $row->gl_purpose,
                'entries' => (int) $row->entries,
                'amount' => round((float) $row->amount, 2),
            ];
            $amount += (float) $row->amount;
            $count += (int) $row->entries;
        }

        return new ReconciliationCause(
            key: 'absorption_gaps',
            label: 'Charged to jobs with no account to absorb it',
            count: $count,
            // Zero, because this cost is inside the pending line of the statement already. Its value here is that it
            // names *which* account is missing, which is the difference between a chase-list and a fix.
            amount: 0.0,
            explanation: $count === 0
                ? 'Every rule that job cost owes a credit for has an account nominated.'
                : 'This is the difference that will not close on its own. Nominate the accounts named below under '
                    .'Control accounts — until then this grows every month with no error anywhere.',
            rows: $rows,
        );
    }
}
