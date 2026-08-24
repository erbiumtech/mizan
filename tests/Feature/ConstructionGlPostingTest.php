<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Policies\GlPostingPolicy;
use App\Modules\ConstructionCosting\Services\ConstructionGlPostingService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * What construction owes the general ledger — §4.1, Phase 11a.
 *
 * §4.1 reduces to one sentence: **where a GL document already exists for a cost, construction posts nothing and mirrors
 * it; where the cost is construction-only, construction posts it in a summary journal.** Everything here is a
 * consequence of that, and the tests are grouped by which consequence.
 *
 *  - **Nothing double-posts.** A mirrored entry is somebody else's posting seen from the job's side, and posting it
 *    again would double the company's cost. A memo entry deliberately never posts at all.
 *  - **Summary, and it explodes.** §4.1: "a summary posting without that link is a number in the accounts nobody can
 *    explain, and should be treated as a defect rather than a shortcut." One line-pair per (account × cost type), and
 *    every contributing entry stamped with the journal it reached.
 *  - **A missing control account is reported, never fudged.** §7.3's failure is the one being prevented: charge burden
 *    and absorb it nowhere and "job cost exceeds GL cost by exactly the burden, growing every month, with no error
 *    anywhere". So the entries stay pending and the plan says so, in words, with the figure.
 *  - **A closed period does not post**, because a journal dated into a signed-off month restates the total a
 *    certificate was built on.
 */
class ConstructionGlPostingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labour;

    private CostCode $plant;

    private CostLedger $ledger;

    private ConstructionGlPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'glposting@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->labour = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);
        $this->plant = CostCode::create([
            'code' => '05.100', 'name' => 'Excavation plant', 'cost_type' => CostCode::TYPE_PLANT, 'unit' => 'hr',
        ]);

        $this->ledger = app(CostLedger::class);
        $this->posting = app(ConstructionGlPostingService::class);
    }

    /** A general-ledger account, created rather than borrowed so no assertion below is ambiguous about which leg it found. */
    private function account(string $code, string $name, string $type): Account
    {
        return Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
    }

    /** Nominate a control account. `purpose` null means report scope only. */
    private function nominate(string $code, string $kind, ?string $purpose = null, ?string $costType = null): ControlAccount
    {
        return ControlAccount::create([
            'account_id' => $this->account($code, "Account {$code}", in_array($kind, ['cost', 'wip'], true) ? 'expense' : 'liability')->getKey(),
            'kind' => $kind,
            'purpose' => $purpose,
            'cost_type' => $costType,
        ]);
    }

    /** The minimum chart a burden posting needs: somewhere to debit and somewhere to credit. */
    private function nominateForBurden(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->nominate('5910', ControlAccount::KIND_RECOVERY, 'labour_burden');
    }

    private function record(CostCode $code, float $amount, array $attributes = []): CostEntry
    {
        return $this->ledger->record($this->job, $code, array_merge([
            'amount' => $amount,
            'incurred_on' => '2026-08-10',
            'description' => 'Test cost',
        ], $attributes));
    }

    private function burden(float $amount, array $attributes = []): CostEntry
    {
        return $this->record($this->labour, $amount, array_merge([
            'is_burden' => true,
            'gl_purpose' => 'labour_burden',
            'gl_treatment' => CostEntry::GL_PENDING,
        ], $attributes));
    }

    // ---------------------------------------------------------------- nothing double-posts

    /**
     * **§4.1's first row, and the most expensive one to get wrong.**
     *
     * A supplier invoice already debited the cost account and credited the payable when Invoicing posted it. The job-cost
     * entry is the same money seen from the job's side. Posting it again would state the company's material cost twice —
     * and both figures would look right, because each is internally consistent.
     */
    public function test_a_mirrored_entry_is_never_posted_again(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record($this->labour, 100_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);

        $plan = $this->posting->plan('2026-08-01');

        $this->assertFalse($plan->hasSomethingToPost());
        $this->assertStringContainsString('Mirrored entries were posted by the document that caused them', $plan->describe());
    }

    /** A memo entry deliberately never reaches the accounts, and the plan says so in the same sentence. */
    public function test_a_memo_entry_is_never_posted(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record($this->labour, 100_000, ['gl_treatment' => CostEntry::GL_MEMO]);

        $this->assertFalse($this->posting->plan('2026-08-01')->hasSomethingToPost());
    }

    /**
     * And a posted entry is not posted twice.
     *
     * The idempotence that makes a scheduled posting run safe: somebody running it twice on the last day of the month
     * must not double a month of burden.
     */
    public function test_a_second_run_finds_nothing_left_to_post(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);

        $this->posting->post('2026-08-01');

        $this->assertFalse($this->posting->plan('2026-08-01')->hasSomethingToPost());
        $this->expectException(InvalidArgumentException::class);
        $this->posting->post('2026-08-01');
    }

    // ---------------------------------------------------------------- the summary journal

    /** One line-pair, balanced, dated to the period end. */
    public function test_a_pending_entry_posts_as_one_balanced_line_pair(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);

        $posting = $this->posting->post('2026-08-01');
        $entry = $posting->journalEntry;

        $this->assertTrue($entry->is_posted);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(2, $entry->lines->count());
        $this->assertSame('50000.00', number_format((float) $entry->lines->sum('debit_amount'), 2, '.', ''));
        $this->assertSame(2, $posting->line_count * 2, 'one PostingLine became two journal lines');
    }

    /**
     * **Dated to the period end, not to the run date.**
     *
     * A June run made on the 4th of July belongs in June's accounts. Dating it to today would push a month's cost into
     * the following month every time somebody was late, which is a restatement nobody asked for and nothing reports.
     */
    public function test_the_journal_is_dated_to_the_period_end(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $this->travelTo('2026-09-04 10:00');

        $posting = $this->posting->post('2026-08-01');

        $this->assertSame('2026-08-31', $posting->journalEntry->entry_date->toDateString());
    }

    /**
     * **§4.1's batch link — the condition under which summary posting is honest.**
     *
     * "A summary posting without that link is a number in the accounts nobody can explain, and should be treated as a
     * defect rather than a shortcut." A line of 150,000 answers "which three entries is that?" in one query.
     */
    public function test_a_posted_line_explodes_into_its_constituent_entries(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $this->burden(60_000, ['incurred_on' => '2026-08-14']);
        $this->burden(40_000, ['incurred_on' => '2026-08-20']);

        $posting = $this->posting->post('2026-08-01');

        $this->assertSame(3, $posting->entries()->count());
        $this->assertSame('150000.00', number_format((float) $posting->entries()->sum('amount'), 2, '.', ''));
        $this->assertSame(3, $posting->entry_count, 'and the count is recorded, not recomputed');
    }

    /**
     * §4.1's grouping: **per (GL account × cost type)**, so a company keeping labour and plant cost apart gets two
     * line-pairs rather than one number a month.
     */
    public function test_the_journal_groups_by_account_and_cost_type(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->nominate('5200', ControlAccount::KIND_COST, null, 'plant');
        $this->nominate('5910', ControlAccount::KIND_RECOVERY, 'labour_burden');
        $this->nominate('5920', ControlAccount::KIND_RECOVERY, 'plant_internal_hire');

        $this->burden(50_000);
        $this->record($this->plant, 30_000, ['gl_purpose' => 'plant_internal_hire']);

        $posting = $this->posting->post('2026-08-01');

        $this->assertSame(2, $posting->line_count, 'two rules against two cost types is two line-pairs');
        $this->assertSame(4, $posting->journalEntry->lines->count());

        $labourDebit = $posting->journalEntry->lines
            ->firstWhere('account_id', ControlAccount::costAccountFor('labour')->account_id);
        $this->assertSame('50000.00', $labourDebit->debit_amount);
    }

    /** The lines say which rule and which cost type they came from, because four lines from one run need explaining. */
    public function test_a_journal_line_names_the_rule_and_the_cost_type(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);

        $line = $this->posting->post('2026-08-01')->journalEntry->lines->first();

        $this->assertStringContainsString('Labour burden absorbed', $line->description);
        $this->assertStringContainsString('Labour', $line->description);
        $this->assertStringContainsString('1 job cost entry', $line->description);
    }

    /** And the contributing entries carry the treatment, the journal and the debit account they reached. */
    public function test_a_posted_entry_is_stamped_with_where_it_went(): void
    {
        $this->nominateForBurden();
        $entry = $this->burden(50_000);

        $posting = $this->posting->post('2026-08-01');
        $entry->refresh();

        $this->assertSame(CostEntry::GL_POSTED, $entry->gl_treatment);
        $this->assertSame($posting->journal_entry_id, $entry->journal_entry_id);
        $this->assertSame((int) ControlAccount::costAccountFor('labour')->account_id, (int) $entry->gl_account_id);
        $this->assertNotNull($entry->posted_to_gl_at);
    }

    /**
     * **`gl_treatment` earns its keep here.**
     *
     * After a posting run, `posted` and `mirrored` are both non-null on `journal_entry_id` and mean different things —
     * one says this ledger posted it and the other says somebody else did. §3.2's column, not an inference from a null.
     */
    public function test_posted_and_mirrored_are_distinguishable_after_a_run(): void
    {
        $this->nominateForBurden();
        $posted = $this->burden(50_000);
        $mirrored = $this->record($this->labour, 90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);

        $this->posting->post('2026-08-01');

        $this->assertSame(CostEntry::GL_POSTED, $posted->refresh()->gl_treatment);
        $this->assertSame(CostEntry::GL_MIRRORED, $mirrored->refresh()->gl_treatment);
        $this->assertNull($mirrored->journal_entry_id, 'construction never touched it');
    }

    // ------------------------------------------------------- a missing account is reported

    /**
     * **§7.3's failure, refused rather than fudged.**
     *
     * "Charge either and never absorb it and job cost exceeds GL cost by exactly the burden, growing every month, with
     * no error anywhere." So with no absorption account the burden stays pending — and the plan says which account is
     * missing and what it is costing not to have it.
     */
    public function test_burden_with_no_absorption_account_stays_pending_and_is_named(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $entry = $this->burden(412_900);

        $plan = $this->posting->plan('2026-08-01');

        $this->assertFalse($plan->hasSomethingToPost());
        $this->assertTrue($plan->hasSkipped());
        $this->assertSame(1, $plan->skippedCount());
        $this->assertSame(412_900.0, $plan->skippedAmount());
        $this->assertStringContainsString('Labour burden absorbed', $plan->describe());
        $this->assertStringContainsString('growing every month', $plan->describe());
        $this->assertSame(CostEntry::GL_PENDING, $entry->refresh()->gl_treatment, 'nothing was fudged');
    }

    /** No suspense account, and no plug. The whole point of §4.3's fourth mechanism, one level down. */
    public function test_a_missing_account_never_produces_a_balancing_figure(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->burden(412_900);

        $before = JournalEntry::query()->count();

        try {
            $this->posting->post('2026-08-01');
            $this->fail('posting with nothing postable should refuse');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Nothing to post', $e->getMessage());
        }

        $this->assertSame($before, JournalEntry::query()->count(), 'no journal was written at all');
    }

    /**
     * **Posting what it can is the right behaviour, not a compromise.**
     *
     * Refusing the whole run over one missing account would leave *everything* pending, which makes §4.2's
     * reconciliation unreadable rather than merely incomplete. The defence against that being quiet is that the
     * skipped half travels with the result.
     */
    public function test_one_missing_account_does_not_stop_the_rules_that_are_set_up(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->nominate('5200', ControlAccount::KIND_COST, null, 'plant');
        $this->nominate('5910', ControlAccount::KIND_RECOVERY, 'labour_burden');
        // Plant internal hire deliberately not nominated.

        $this->burden(50_000);
        $unabsorbed = $this->record($this->plant, 30_000, ['gl_purpose' => 'plant_internal_hire']);

        $posting = $this->posting->post('2026-08-01');

        $this->assertSame('50000.00', $posting->total_amount);
        $this->assertSame(CostEntry::GL_PENDING, $unabsorbed->refresh()->gl_treatment);
        $this->assertStringContainsString(
            'Plant internal hire recovery',
            $this->posting->plan('2026-08-01')->describe(),
            'and it says so again next month, with the figure',
        );
    }

    /** A missing *debit* account is its own refusal, with its own sentence. */
    public function test_a_missing_cost_account_is_named_separately(): void
    {
        $this->nominate('5910', ControlAccount::KIND_RECOVERY, 'labour_burden');
        $this->burden(50_000);

        $plan = $this->posting->plan('2026-08-01');

        $this->assertFalse($plan->hasSomethingToPost());
        $this->assertStringContainsString('nothing to debit', $plan->describe());
    }

    /**
     * An entry no rule matches is reported as unruled rather than guessed at.
     *
     * A material cost with no `gl_purpose` and no source document is not something this service should invent a credit
     * for. §4.1's table has no row for it, and inventing one would put a figure somewhere nobody chose.
     */
    public function test_an_entry_with_no_rule_is_reported_rather_than_guessed(): void
    {
        $material = CostCode::create([
            'code' => '03.200', 'name' => 'Blockwork', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 'm2',
        ]);
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'material');
        $this->record($material, 20_000);

        $plan = $this->posting->plan('2026-08-01');

        $this->assertFalse($plan->hasSomethingToPost());
        $this->assertStringContainsString('No posting rule matches these entries', $plan->describe());
    }

    /**
     * **At most one account per rule, enforced by the database.**
     *
     * A service that found two candidates and took the first would absorb half a company's burden to one account and
     * half to another depending on insertion order, and no report would ever say so. So it is a unique index rather
     * than a convention.
     */
    public function test_two_accounts_cannot_claim_the_same_rule(): void
    {
        $this->nominate('5910', ControlAccount::KIND_RECOVERY, 'labour_burden');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->nominate('5911', ControlAccount::KIND_RECOVERY, 'labour_burden');
    }

    /** Any number of accounts may be in the report's scope with no rule attached — the nulls are not unique. */
    public function test_many_accounts_may_be_in_scope_with_no_posting_rule(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->nominate('5200', ControlAccount::KIND_COST, null, 'material');
        $this->nominate('5300', ControlAccount::KIND_COST, null, 'plant');

        $this->assertCount(3, ControlAccount::accountIdsOfKind(ControlAccount::KIND_COST));
    }

    /**
     * One job-cost account for everything is a company's right, and the fallback is what allows it.
     *
     * `5099` rather than `5000`: the shipped chart's `5000` is an expense *heading* that cannot accept entries, and
     * borrowing it would have made this test fail for a reason that has nothing to do with the fallback.
     */
    public function test_a_single_cost_account_serves_every_cost_type(): void
    {
        $this->nominate('5099', ControlAccount::KIND_COST);
        $this->nominate('5910', ControlAccount::KIND_RECOVERY, 'labour_burden');

        $this->burden(50_000);

        $this->assertSame('50000.00', $this->posting->post('2026-08-01')->total_amount);
    }

    // ---------------------------------------------------------------- the closed period

    /**
     * **A closed period does not post.**
     *
     * §3.4: silently changing a closed month "is the precise failure this whole design exists to prevent". A journal
     * dated into it restates the total the certificate and the WIP snapshot were built on.
     */
    public function test_a_closed_period_refuses_to_post(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);

        CostPeriod::forDate('2026-08-01')->update(['status' => CostPeriod::STATUS_CLOSED]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be posted');
        $this->posting->post('2026-08-01');
    }

    // ---------------------------------------------------------------- reversal

    /**
     * A reversing journal, not an unposting.
     *
     * §3.3's rule with more force in the general ledger than in the cost ledger: the accounts are what somebody has
     * already read, and deleting a line they read is worse than showing them the line that cancelled it.
     */
    public function test_reversing_a_posting_writes_the_opposite_journal(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);

        $posting = $this->posting->post('2026-08-01');
        $reversal = $this->posting->reverse($posting, 'The burden rate was set wrong for August.');

        $this->assertTrue($reversal->isReversal());
        $this->assertSame($posting->getKey(), $reversal->reverses_gl_posting_id);
        $this->assertSame('-50000.00', $reversal->total_amount);

        // Both journals still in the books, summing to nothing — the cheapest possible check that the reversal was
        // complete, which is §3.2's argument for a batch being a row.
        $accountId = ControlAccount::costAccountFor('labour')->account_id;
        $net = JournalEntryLine::query()->where('account_id', $accountId)->sum('debit_amount')
            - JournalEntryLine::query()->where('account_id', $accountId)->sum('credit_amount');
        $this->assertSame(0.0, round((float) $net, 2));
    }

    /**
     * **The cost entries go back to pending, not to memo.**
     *
     * The cost is still on the job and no longer in the books, so §4.2's reconciliation should report it as owed.
     * Marking them memo would balance the report and lose the cost, which is the flattering wrong answer.
     */
    public function test_a_reversed_postings_entries_are_owed_again(): void
    {
        $this->nominateForBurden();
        $entry = $this->burden(50_000);

        $posting = $this->posting->post('2026-08-01');
        $this->posting->reverse($posting, 'Wrong rate.');
        $entry->refresh();

        $this->assertSame(CostEntry::GL_PENDING, $entry->gl_treatment);
        $this->assertNull($entry->journal_entry_id);
        $this->assertNull($entry->posted_to_gl_at);
        $this->assertTrue($this->posting->plan('2026-08-01')->hasSomethingToPost(), 'and they will post again');
    }

    /** A reversal needs a reason. What it backs out is a figure somebody has already read. */
    public function test_a_reversal_without_a_reason_is_refused(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $posting = $this->posting->post('2026-08-01');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');
        $this->posting->reverse($posting, '   ');
    }

    /** And a posting is reversed once. Two negatives with nothing between them is a trail nobody can follow. */
    public function test_a_posting_cannot_be_reversed_twice(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $posting = $this->posting->post('2026-08-01');
        $this->posting->reverse($posting, 'Wrong rate.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been reversed');
        $this->posting->reverse($posting, 'Again.');
    }

    /** Nor is a reversal itself reversible — post the period again instead. */
    public function test_a_reversal_is_not_itself_reversible(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $reversal = $this->posting->reverse($this->posting->post('2026-08-01'), 'Wrong rate.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('itself a reversal');
        $this->posting->reverse($reversal, 'And back again.');
    }

    // ---------------------------------------------------------------- corrections in the period

    /**
     * **An entry and its reversal in the same month post nothing and are still dealt with.**
     *
     * §3.3 makes a correction a reversal rather than an edit, so a month with a corrected site sheet in it has a pair
     * summing to zero. A zero-value journal line says nothing happened, which is worse than the silence it replaces —
     * but leaving the pair pending would report them as owed to the general ledger forever.
     */
    public function test_a_cancelling_pair_writes_no_journal_line_and_does_not_stay_pending(): void
    {
        $this->nominateForBurden();
        $charged = $this->burden(50_000);
        $reversed = $this->burden(-50_000, ['kind' => CostEntry::KIND_REVERSAL, 'reverses_id' => $charged->getKey()]);

        $plan = $this->posting->plan('2026-08-01');
        $this->assertFalse($plan->hasSomethingToPost());
        $this->assertStringContainsString('cancel to nothing', $plan->describe());

        /*
         * Nothing to post means nothing posts — so the pair is dealt with by a run that has something *else* to post.
         *
         * A second entry under the same rule would not exercise this at all: it would join the same group, take the
         * total off zero and post normally. The cancellation has to be the whole of one group while another group
         * carries the run, which is what the plant entry below is for.
         */
        $this->nominate('5200', ControlAccount::KIND_COST, null, 'plant');
        $this->nominate('5920', ControlAccount::KIND_RECOVERY, 'plant_internal_hire');
        $this->record($this->plant, 20_000, ['gl_purpose' => 'plant_internal_hire']);

        $posting = $this->posting->post('2026-08-01');

        $this->assertSame('20000.00', $posting->total_amount, 'only the plant group carried a line');
        $this->assertSame(1, $posting->line_count);
        $this->assertSame(CostEntry::GL_POSTED, $charged->refresh()->gl_treatment);
        $this->assertSame(CostEntry::GL_POSTED, $reversed->refresh()->gl_treatment);
        $this->assertFalse(
            $this->posting->plan('2026-08-01')->hasSkipped(),
            'and the pair is not reported as owed next month',
        );
    }

    /**
     * A rule whose entries net to a credit swaps the sides rather than writing a negative debit.
     *
     * `JournalEntryService` validates that a line has one side or the other. A negative debit would balance
     * arithmetically and read as nonsense in the general ledger.
     */
    public function test_a_net_credit_swaps_the_sides(): void
    {
        $this->nominateForBurden();
        $charged = $this->burden(20_000);
        $this->burden(-50_000, ['kind' => CostEntry::KIND_REVERSAL, 'reverses_id' => $charged->getKey()]);

        $posting = $this->posting->post('2026-08-01');
        $costAccountId = (int) ControlAccount::costAccountFor('labour')->account_id;
        $costLine = $posting->journalEntry->lines->firstWhere('account_id', $costAccountId);

        $this->assertSame('30000.00', $costLine->credit_amount, 'the cost account is credited, not debited by -30,000');
        $this->assertSame('0.00', $costLine->debit_amount);
        $this->assertTrue($posting->journalEntry->isBalanced());
    }

    // ---------------------------------------------------------------- the rules and who holds them

    /** `gl_purpose` is what the writers set, so the poster never has to guess. */
    public function test_the_writers_name_the_credit_they_owe(): void
    {
        $entry = $this->burden(50_000);

        $this->assertSame('labour_burden', $entry->gl_purpose);
    }

    /**
     * The inference is a fallback for history and still works.
     *
     * Every row in a live tenant was written before the column existed. Burden is recoverable from its flag, which is
     * the shape most of that history is in.
     */
    public function test_a_row_written_before_the_column_existed_still_posts(): void
    {
        $this->nominateForBurden();
        $entry = $this->burden(50_000);
        // What a pre-Phase-11 row looks like: flagged, and carrying no purpose.
        $entry->update(['gl_purpose' => null]);

        $this->assertSame('50000.00', $this->posting->post('2026-08-01')->total_amount);
    }

    /**
     * **`ConstructionGlPost` is the only permission in this module that governs writing to another module's ledger.**
     *
     * Asserted against the policy class rather than through the gate, because `Gate::before` makes an Administrator
     * pass every ability and the assertion would then be about nothing.
     */
    public function test_posting_is_its_own_grant_and_a_surveyor_does_not_hold_it(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $posting = $this->posting->post('2026-08-01');

        $policy = app(GlPostingPolicy::class);
        $surveyor = $this->makeUser('Accountant', 'surveyor@test.local');
        $manager = $this->makeUser('Manager', 'bookkeeper@test.local');

        $this->assertFalse($policy->create($surveyor), 'whoever approves the cost does not decide what reaches the books');
        $this->assertTrue($policy->create($manager));
        $this->assertTrue($policy->reverse($manager, $posting), 'and whoever may post may take it back out');
    }

    /** A posting is never edited and never deleted: it is the row that says what reached the accounts. */
    public function test_a_posting_is_neither_editable_nor_deletable(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $posting = $this->posting->post('2026-08-01');
        $policy = app(GlPostingPolicy::class);
        $manager = $this->makeUser('Manager', 'bookkeeper2@test.local');

        $this->assertFalse($policy->update($manager, $posting));
        $this->assertFalse($policy->delete($manager, $posting));
        // Reversing is the one thing that *is* open on a live posting, which is what makes the two above a decision
        // rather than a blanket refusal.
        $this->assertTrue($policy->reverse($manager, $posting->fresh()));
    }

    /** And a reversed posting cannot be reversed again through the policy either. */
    public function test_the_policy_closes_the_door_a_second_reversal_would_use(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $posting = $this->posting->post('2026-08-01');
        $this->posting->reverse($posting, 'Wrong rate.');

        $this->assertFalse(app(GlPostingPolicy::class)->reverse(
            $this->makeUser('Manager', 'bookkeeper3@test.local'),
            $posting->fresh(),
        ));
    }

    // ---------------------------------------------------------------- what is not stored

    /**
     * The run's totals are recorded rather than recomputed.
     *
     * §3.4 makes the same argument about the period's control totals: "a reconciliation computed later from live data
     * cannot tell you what the figures were on the day somebody signed the certificate." An entry reversed next month
     * must not change what this run says it posted.
     */
    public function test_the_run_remembers_what_it_posted_even_after_the_ledger_moves(): void
    {
        $this->nominateForBurden();
        $this->burden(50_000);
        $posting = $this->posting->post('2026-08-01');

        // Next month, somebody reverses the underlying cost.
        $this->burden(-50_000, ['incurred_on' => '2026-09-05', 'kind' => CostEntry::KIND_REVERSAL]);

        $this->assertSame('50000.00', $posting->fresh()->total_amount, 'August still posted 50,000');
    }

    /** Nothing about a control account is inferred from a code, which is §4.2's whole point. */
    public function test_no_account_code_is_hard_coded_anywhere_in_the_rules(): void
    {
        // A company whose chart puts job cost on 7100 and burden recovery on 7910 reconciles exactly as well.
        $this->nominate('7100', ControlAccount::KIND_COST, null, 'labour');
        $this->nominate('7910', ControlAccount::KIND_RECOVERY, 'labour_burden');
        $this->burden(50_000);

        $posting = $this->posting->post('2026-08-01');

        $this->assertSame(
            (int) Account::where('code', '7100')->value('id'),
            (int) $posting->journalEntry->lines->firstWhere('debit_amount', '>', 0)->account_id,
        );
    }
}
