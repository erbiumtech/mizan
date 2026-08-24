<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\GlPosting;
use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Modules\ConstructionCosting\Support\PostingLine;
use App\Modules\ConstructionCosting\Support\PostingPlan;
use App\Support\TenantTransaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What construction owes the general ledger — `docs/construction-management-plan.md` §4.1.
 *
 * §4.1's table settles who posts what, and the rule reduces to one sentence: **where a GL document already exists for a
 * cost, construction posts nothing and mirrors it; where the cost is construction-only, construction posts it in a
 * summary journal.** A supplier invoice, a payment, a stock movement and a payslip already reached the books, so
 * posting them again would double the company's cost. Site labour with no payroll behind it, burden absorption, internal
 * plant recovery, overhead allocation, the two accruals and WIP reached nothing, so this service is their only route.
 *
 * **Summary, not per entry, and §4.1 says why in one line:** "a hundred thousand entries a period would make
 * `journal_entry_lines` the largest table in the tenant and the general ledger unreadable." One line-pair per period per
 * (GL account × cost type).
 *
 * **What makes summary posting honest is the link back.** §4.1 again: "a summary posting without that link is a number
 * in the accounts nobody can explain, and should be treated as a defect rather than a shortcut." Every contributing
 * entry is stamped with the journal entry's id, so `GlPosting::entries()` explodes any line into its constituents in one
 * query — jobs, cost codes, unit rates and all.
 *
 * **Three refusals, each of which could have been a fudge:**
 *
 *  - A period that is closed does not post. A journal dated into a signed-off month restates the total a certificate and
 *    a WIP snapshot were built on, which §3.4 calls "the precise failure this whole design exists to prevent".
 *  - An entry whose rule has no control account **stays pending and is reported**, rather than being credited to a
 *    suspense account. §4.3's fourth mechanism is the same principle for the whole reconciliation: "a forced close never
 *    fudges the ledger. No plug entry, no balancing figure."
 *  - A reversal needs a reason. What it backs out is a figure in the books that somebody has already read.
 */
class ConstructionGlPostingService
{
    public function __construct(private JournalEntryService $journals) {}

    /**
     * What the period would post, computed and not written — the read behind the button.
     *
     * A preview matters more here than on most screens: this is the one act in the module that writes into the general
     * ledger, and somebody should be able to see the four lines and the accounts before they exist.
     */
    public function plan(string $periodStart): PostingPlan
    {
        $start = CostPeriod::startFor($periodStart)->toDateString();

        $entries = CostEntry::query()
            ->awaitingGl()
            ->inPeriod($start)
            ->get();

        $lines = [];
        $skipped = [];
        $total = 0.0;
        $counted = 0;

        foreach ($this->group($entries) as $key => $group) {
            [$purpose, $costType] = $group['key'];
            $resolution = $this->resolve($purpose, $costType);

            if ($resolution['line'] === null) {
                $skipped[$key] = [
                    'count' => $group['entries']->count(),
                    'amount' => round((float) $group['entries']->sum('amount'), 2),
                    'reason' => $resolution['reason'],
                ];

                continue;
            }

            $amount = round((float) $group['entries']->sum('amount'), 2);

            /*
             * A group summing to nothing posts nothing.
             *
             * An entry and its reversal in the same period is the ordinary case — §3.3 makes a correction a reversal
             * rather than an edit — and a zero-value journal line is a line in the accounts that says nothing happened,
             * which is worse than the silence it replaces. Both entries are still stamped as posted below, because they
             * *have* been dealt with and leaving them pending would report them as owed forever.
             */
            if (abs($amount) < 0.005) {
                $skipped[$key] = [
                    'count' => $group['entries']->count(),
                    'amount' => 0.0,
                    'reason' => $resolution['line']['label'].': the entries in this period cancel to nothing, so no '
                        .'journal line was written.',
                ];

                continue;
            }

            $lines[] = new PostingLine(
                purpose: $purpose,
                purposeLabel: $resolution['line']['label'],
                debitAccountId: $resolution['line']['debit'],
                creditAccountId: $resolution['line']['credit'],
                costType: $costType,
                amount: $amount,
                entryIds: $group['entries']->pluck('id')->all(),
            );

            $total += $amount;
            $counted += $group['entries']->count();
        }

        return new PostingPlan($start, $lines, $skipped, round($total, 2), $counted);
    }

    /**
     * Post the period, and stamp what was posted.
     *
     * The journal is dated to the **period end** rather than today: a June posting run made on the 4th of July belongs
     * in June's accounts, and dating it to the run date would move a month's cost into the following one every time
     * somebody was late.
     */
    public function post(string $periodStart, ?string $notes = null): GlPosting
    {
        $period = CostPeriod::forDate($periodStart);

        if ($period->isClosed()) {
            throw new InvalidArgumentException(
                "Cost period {$period->label()} is {$period->status} and cannot be posted. A journal dated into a "
                .'signed-off month restates the total the certificate and the WIP snapshot were built on.'
            );
        }

        $plan = $this->plan($periodStart);

        if (! $plan->hasSomethingToPost()) {
            throw new InvalidArgumentException('Nothing to post for '.$period->label().'. '.$plan->describe());
        }

        return TenantTransaction::run(function () use ($period, $plan, $notes): GlPosting {
            $entry = $this->journals->create([
                'entry_date' => $period->period_end->toDateString(),
                'entry_type' => 'general',
                'memo' => 'Construction job cost — '.$period->label(),
            ], $this->journalLines($plan));

            // Approved and posted in one act, like `InventoryService::postSystemEntry()`. A system journal that waited
            // for a human approval would leave the two ledgers disagreeing until somebody clicked, and the decision was
            // taken when the cost was approved rather than here.
            $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
            $this->journals->post($entry);

            $posting = GlPosting::create([
                'period_start' => $plan->periodStart,
                'journal_entry_id' => $entry->getKey(),
                'entry_count' => $plan->entryCount,
                'total_amount' => $plan->total,
                'line_count' => count($plan->lines),
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'notes' => $notes,
            ]);

            $this->stamp($plan, $entry);

            return $posting;
        });
    }

    /**
     * Back a posting out of the books — both sides.
     *
     * A reversing journal rather than an unposting, because §3.3's rule applies to the general ledger with more force
     * than to the cost ledger: the accounts are what somebody has already read, and deleting a line they read is worse
     * than showing them the line that cancelled it.
     *
     * **The cost entries go back to `pending`**, which is the honest state: the cost is still on the job and no longer in
     * the books, so §4.2's reconciliation should report it as owed. Setting them to `memo` would balance the report and
     * lose the cost.
     */
    public function reverse(GlPosting $posting, string $reason): GlPosting
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A reversal needs a reason. What it backs out is a figure in the accounts somebody has already read.'
            );
        }

        if ($posting->isReversal()) {
            throw new InvalidArgumentException(
                'That is itself a reversal. Post the period again rather than reversing a reversal — two negatives in '
                .'the ledger with nothing between them is a trail nobody can follow.'
            );
        }

        if ($posting->isReversed()) {
            throw new InvalidArgumentException(
                $posting->displayName().' has already been reversed.'
            );
        }

        $original = $posting->journalEntry;

        if ($original === null) {
            throw new InvalidArgumentException(
                $posting->displayName().' has no journal entry behind it, so there is nothing in the accounts to '
                .'reverse. The cost entries it stamped need putting back to pending by hand.'
            );
        }

        return TenantTransaction::run(function () use ($posting, $original, $reason): GlPosting {
            $lines = $original->lines->map(fn ($line): array => [
                'account_id' => $line->account_id,
                // Swapped, which is what a reversal is.
                'debit_amount' => (float) $line->credit_amount,
                'credit_amount' => (float) $line->debit_amount,
                'description' => 'Reversal — '.$line->description,
            ])->all();

            $entry = $this->journals->create([
                'entry_date' => now()->toDateString(),
                'entry_type' => 'general',
                'memo' => 'Reversal of '.$posting->displayName().' — '.$reason,
            ], $lines);

            $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
            $this->journals->post($entry);

            // Back to pending, not memo. The cost is still on the job and no longer in the books, and §4.2's report
            // should say so.
            CostEntry::query()
                ->where('journal_entry_id', $original->getKey())
                ->update([
                    'gl_treatment' => CostEntry::GL_PENDING,
                    'journal_entry_id' => null,
                    'posted_to_gl_at' => null,
                ]);

            return GlPosting::create([
                'period_start' => $posting->period_start->toDateString(),
                'journal_entry_id' => $entry->getKey(),
                'entry_count' => $posting->entry_count,
                'total_amount' => -1 * (float) $posting->total_amount,
                'line_count' => count($lines),
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'reverses_gl_posting_id' => $posting->getKey(),
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Group the period's pending entries by what they owe and what they are.
     *
     * §4.1's "(GL account × cost type)" expressed as (rule × cost type) — the rule is what resolves to the account
     * pair, and grouping by the rule rather than by the account means a company that later re-points a rule at a
     * different account gets a correctly grouped journal without this method changing.
     *
     * @param  Collection<int, CostEntry>  $entries
     * @return array<string, array{key: array{0: string, 1: ?string}, entries: Collection<int, CostEntry>}>
     */
    private function group(Collection $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $purpose = $this->purposeFor($entry);
            $key = ($purpose ?? '__unruled__').'|'.$entry->cost_type;

            $grouped[$key] ??= ['key' => [$purpose ?? '__unruled__', $entry->cost_type], 'entries' => collect()];
            $grouped[$key]['entries']->push($entry);
        }

        return $grouped;
    }

    /**
     * Which credit rule an entry owes.
     *
     * **`gl_purpose` first, and it is what a writer should always set.** The code that records a cost knows which
     * account it owes; the code that posts it should not have to guess, and §4.5's two accruals prove the point — a
     * goods-received accrual and a subcontract accrual are the same shape of row owing different accounts, so no
     * inference could ever tell them apart.
     *
     * **The inference below covers history only.** Every row written before this column existed carries null, and
     * three shapes are recoverable from what those rows already hold: burden is flagged, internal plant has a plant log
     * behind it, and anything else labour-shaped was site labour with no payroll behind it (§4.1's table). Anything not
     * in that set is unruled, and unruled means reported rather than guessed.
     */
    private function purposeFor(CostEntry $entry): ?string
    {
        if ($entry->gl_purpose !== null && $entry->gl_purpose !== '') {
            return $entry->gl_purpose;
        }

        if ($entry->is_burden) {
            return 'labour_burden';
        }

        if ($entry->source_type === (new PlantLog)->getMorphClass()) {
            return 'plant_internal_hire';
        }

        if ($entry->kind === CostEntry::KIND_ALLOCATION) {
            return 'overhead_allocation';
        }

        return $entry->cost_type === 'labour' ? 'site_labour' : null;
    }

    /**
     * The account pair for a rule, or the sentence saying which account is missing.
     *
     * Every branch returns a reason somebody can act on. "Cannot post" tells a book-keeper nothing; "no account is
     * nominated for labour burden absorbed, so 84 entries worth 412,900 stay pending" tells them what to go and set up
     * and what it is costing them not to.
     *
     * @return array{line: array{label: string, debit: int, credit: int}|null, reason: string}
     */
    private function resolve(string $purpose, ?string $costType): array
    {
        if ($purpose === '__unruled__') {
            return ['line' => null, 'reason' => 'No posting rule matches these entries, so nothing can be credited for '
                .'them. Set `gl_purpose` on whatever writes them, or record them as memo if they deliberately never '
                .'reach the accounts.'];
        }

        $label = ControlAccount::PURPOSES[$purpose] ?? $purpose;
        $credit = ControlAccount::forPurpose($purpose);

        if ($credit === null) {
            return ['line' => null, 'reason' => "No control account is nominated for \"{$label}\", so there is nothing "
                .'to credit. Nominate one under Control accounts — charging a job and absorbing it nowhere makes job '
                .'cost exceed GL cost by exactly this figure, growing every month, with no error anywhere.'];
        }

        $debit = ControlAccount::costAccountFor((string) $costType);

        if ($debit === null) {
            return ['line' => null, 'reason' => 'No job-cost control account is nominated for '
                .($costType ?: 'these entries').', so there is nothing to debit. Nominate one under Control accounts.'];
        }

        return [
            'line' => [
                'label' => $label,
                'debit' => (int) $debit->account_id,
                'credit' => (int) $credit->account_id,
            ],
            'reason' => '',
        ];
    }

    /**
     * The plan as journal lines.
     *
     * A negative group — a period whose reversals outweighed its charges under one rule — swaps the sides rather than
     * writing a negative debit. `JournalEntryService` validates that a line has one side or the other, and a negative
     * debit would balance arithmetically while reading as nonsense in the general ledger.
     *
     * @return array<int, array<string, mixed>>
     */
    private function journalLines(PostingPlan $plan): array
    {
        $lines = [];

        foreach ($plan->lines as $line) {
            $amount = abs($line->amount);
            $reversed = $line->amount < 0;

            $lines[] = [
                'account_id' => $reversed ? $line->creditAccountId : $line->debitAccountId,
                'debit_amount' => $amount,
                'description' => $line->memo(),
            ];
            $lines[] = [
                'account_id' => $reversed ? $line->debitAccountId : $line->creditAccountId,
                'credit_amount' => $amount,
                'description' => $line->memo(),
            ];
        }

        return $lines;
    }

    /**
     * Stamp every contributing entry with the journal it reached.
     *
     * §4.1's batch link, and the reason `gl_treatment` is a column rather than `journal_entry_id IS NULL`: after this
     * runs, `posted` and `mirrored` are both non-null and mean different things — one says this ledger posted it and the
     * other says somebody else did.
     */
    private function stamp(PostingPlan $plan, JournalEntry $entry): void
    {
        foreach ($plan->lines as $line) {
            CostEntry::query()
                ->whereIn('id', $line->entryIds)
                ->update([
                    'gl_treatment' => CostEntry::GL_POSTED,
                    'journal_entry_id' => $entry->getKey(),
                    'gl_account_id' => $line->debitAccountId,
                    'posted_to_gl_at' => now(),
                ]);
        }

        /*
         * The cancelling groups are stamped too — see `plan()`.
         *
         * An entry and its reversal in the same period have been dealt with, and leaving them pending would report them
         * as owed to the general ledger forever. They carry the journal entry of the run that dealt with them, so the
         * trail is intact even though they contributed no line to it.
         */
        foreach ($plan->skipped as $key => $skipped) {
            if (! str_contains($skipped['reason'], 'cancel to nothing')) {
                continue;
            }

            [$purpose, $costType] = explode('|', $key);

            CostEntry::query()
                ->awaitingGl()
                ->inPeriod($plan->periodStart)
                ->where('cost_type', $costType)
                ->get()
                ->filter(fn (CostEntry $e): bool => ($this->purposeFor($e) ?? '__unruled__') === $purpose)
                ->each(fn (CostEntry $e) => $e->update([
                    'gl_treatment' => CostEntry::GL_POSTED,
                    'journal_entry_id' => $entry->getKey(),
                    'posted_to_gl_at' => now(),
                ]));
        }
    }
}
