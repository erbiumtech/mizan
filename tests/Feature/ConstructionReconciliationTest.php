<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Console\Commands\Reconcile;
use App\Modules\ConstructionCosting\Filament\Pages\ReconciliationReport;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Modules\ConstructionCosting\Policies\ReconciliationPolicy;
use App\Modules\ConstructionCosting\Services\ConstructionGlPostingService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\ReconciliationService;
use App\Modules\Core\Models\CompanyModule;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Proving the two ledgers equal — §4.2 and §4.3, Phase 11c.
 *
 * §4 opens with the sentence this suite defends: **"A second ledger that nobody proves is a second ledger that is
 * wrong."** §3 bought a job-cost ledger with unit rates, non-GL costs and its own calendar, and §4 is the price.
 *
 * Four things under test, in the order §4.2 and §4.3 raise them:
 *
 *  - **The arithmetic**, exactly as the plan writes it: GL cost, less cost carrying no job, plus job cost awaiting the
 *    GL, against job cost recorded, difference nil.
 *  - **"Shown, never spread."** Unallocated GL cost comes off whole and is never apportioned across jobs — because
 *    apportioning it would balance the report and put money on jobs nobody charged it to.
 *  - **The causes**, which §4.2 says are "what makes it a tool rather than a number" — including the one the plan
 *    insists is "rendered even when empty".
 *  - **§4.3's mechanisms**: the command that writes a row and notifies, and accepting a difference with a stated reason
 *    — which fixes nothing, by design.
 */
class ConstructionReconciliationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labour;

    private CostLedger $ledger;

    private ReconciliationService $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'reconcile@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'invoicing'] as $module) {
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

        $this->ledger = app(CostLedger::class);
        $this->reconciler = app(ReconciliationService::class);
    }

    private function account(string $code, string $type = 'expense'): Account
    {
        return Account::firstOrCreate(['code' => $code], ['name' => "Account {$code}", 'type' => $type]);
    }

    private function nominate(string $code, string $kind, ?string $purpose = null, ?string $costType = null): ControlAccount
    {
        return ControlAccount::create([
            'account_id' => $this->account($code, $kind === 'cost' ? 'expense' : 'liability')->getKey(),
            'kind' => $kind,
            'purpose' => $purpose,
            'cost_type' => $costType,
        ]);
    }

    private function nominateCostAndBurden(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->nominate('5910', ControlAccount::KIND_RECOVERY, 'labour_burden');
    }

    private function record(float $amount, array $attributes = []): CostEntry
    {
        return $this->ledger->record($this->job, $this->labour, array_merge([
            'amount' => $amount,
            'incurred_on' => '2026-08-10',
            'description' => 'Site labour',
        ], $attributes));
    }

    private function burden(float $amount, array $attributes = []): CostEntry
    {
        return $this->record($amount, array_merge([
            'is_burden' => true,
            'gl_purpose' => 'labour_burden',
        ], $attributes));
    }

    /** A posted journal, as another module or the Accounting panel would write it. */
    private function postJournal(string $date, array $lines, string $memo = 'Journal'): JournalEntry
    {
        $entry = app(JournalEntryService::class)->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => $memo,
        ], $lines);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry);

        return $entry->refresh();
    }

    private function runCommand(?string $period = null): int
    {
        $command = new Reconcile;
        $command->setLaravel($this->app);
        $input = new ArrayInput($period === null ? [] : ['--period' => $period], new InputDefinition([
            new InputOption('period', null, InputOption::VALUE_OPTIONAL),
        ]));
        $buffer = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        return $command->handle(app(ReconciliationService::class));
    }

    // ---------------------------------------------------------------- the arithmetic

    /**
     * **The whole of §4.2 in the simplest case that exercises it.**
     *
     * Burden charged to the job, posted to the general ledger by §11a. Both ledgers hold the same 50,000 and the
     * difference is nil.
     */
    public function test_a_posted_cost_reconciles_to_nothing(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);
        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $statement = $this->reconciler->compute('2026-08-01');

        $this->assertSame(50_000.0, $statement->glCost);
        $this->assertSame(50_000.0, $statement->jobCost);
        $this->assertSame(0.0, $statement->difference);
        $this->assertTrue($statement->isBalanced());
    }

    /**
     * **Job cost awaiting the general ledger is a reconciling item, not a difference.**
     *
     * The cost is on the job and nobody has posted it. That is a state, not an error, and treating it as a difference
     * would report every company that has not run its month-end as broken.
     */
    public function test_pending_cost_is_a_reconciling_item(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);

        $statement = $this->reconciler->compute('2026-08-01');

        $this->assertSame(0.0, $statement->glCost, 'nothing has been posted');
        $this->assertSame(50_000.0, $statement->pendingJobCost);
        $this->assertSame(50_000.0, $statement->expectedJobCost);
        $this->assertTrue($statement->isBalanced());
    }

    /**
     * A mirrored cost reconciles without construction having posted anything.
     *
     * §4.1's first row: the purchase invoice reached the books, and the job-cost entry is the same money seen from the
     * job's side. Both ledgers hold it once.
     */
    public function test_a_mirrored_cost_reconciles_against_the_invoices_own_journal(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');

        $journal = $this->postJournal('2026-08-15', [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 90_000, 'description' => 'Supplier invoice'],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 90_000, 'description' => 'Supplier invoice'],
        ], 'Purchase invoice');

        $this->record(90_000, [
            'gl_treatment' => CostEntry::GL_MIRRORED,
            'journal_entry_id' => $journal->getKey(),
        ]);

        $statement = $this->reconciler->compute('2026-08-01');

        $this->assertSame(90_000.0, $statement->glCost);
        $this->assertSame(0.0, $statement->unallocatedGlCost, 'the journal has a job behind it');
        $this->assertTrue($statement->isBalanced());
    }

    /**
     * **A memo cost is excluded from the job side.**
     *
     * §3.2: memo means "deliberately never will" reach the accounts — a notional tender comparison, an unposted
     * allocation. Including it would report a difference equal to a figure nobody ever intended the ledger to see.
     */
    public function test_a_memo_cost_is_outside_the_reconciliation(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(30_000, ['gl_treatment' => CostEntry::GL_MEMO]);

        $statement = $this->reconciler->compute('2026-08-01');

        $this->assertSame(0.0, $statement->jobCost);
        $this->assertTrue($statement->isBalanced());
    }

    /**
     * **"Posted entries only — the same filter `FinancialReportService` uses."**
     *
     * §4.2 says what happens otherwise: "the two sides will disagree about drafts and nobody will know why". A draft
     * journal is not the general ledger.
     */
    public function test_a_draft_journal_is_not_general_ledger_cost(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');

        app(JournalEntryService::class)->create([
            'entry_date' => '2026-08-15',
            'entry_type' => 'general',
            'memo' => 'Not posted',
        ], [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 70_000],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 70_000],
        ]);

        $this->assertSame(0.0, $this->reconciler->compute('2026-08-01')->glCost);
    }

    /** A journal dated outside the month is outside the month. */
    public function test_a_journal_outside_the_period_is_outside_the_figure(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->postJournal('2026-09-03', [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 70_000],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 70_000],
        ]);

        $this->assertSame(0.0, $this->reconciler->compute('2026-08-01')->glCost);
    }

    /** A credit to a cost account reduces the GL side, the way it reduces the accounts. */
    public function test_a_credit_note_reduces_the_general_ledger_side(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');

        $journal = $this->postJournal('2026-08-15', [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 90_000],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 90_000],
        ]);
        $credit = $this->postJournal('2026-08-20', [
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'debit_amount' => 10_000],
            ['account_id' => $this->account('5100')->getKey(), 'credit_amount' => 10_000],
        ], 'Credit note');

        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED, 'journal_entry_id' => $journal->getKey()]);
        $this->record(-10_000, ['gl_treatment' => CostEntry::GL_MIRRORED, 'journal_entry_id' => $credit->getKey()]);

        $statement = $this->reconciler->compute('2026-08-01');

        $this->assertSame(80_000.0, $statement->glCost);
        $this->assertTrue($statement->isBalanced());
    }

    /**
     * **The refusal, and it is the same shape as §17.6's.**
     *
     * With no cost control account nominated the GL side is zero and the difference is the entire job cost — a report
     * that looks like a catastrophe and means nobody has set the module up. Say what is missing rather than print a
     * number that reads as a finding.
     */
    public function test_with_no_control_account_the_report_says_so_rather_than_reporting_everything(): void
    {
        $this->burden(412_900);

        $statement = $this->reconciler->compute('2026-08-01');

        $this->assertNotNull($statement->scopeWarning);
        $this->assertSame(0.0, $statement->difference, 'not 412,900, which would read as a finding');
        $this->assertStringContainsString('No job-cost control account is nominated', $statement->describe());
        $this->assertStringContainsString('nobody has set the module up', $statement->describe());
    }

    // -------------------------------------------------- shown, never spread

    /**
     * **§4.2's most common cause: somebody journalled a cost straight to `5020` from the Accounting panel.**
     *
     * The accounts are perfectly correct. The job knows nothing about it. Nothing else in the application would ever
     * mention it.
     */
    public function test_general_ledger_cost_with_no_job_is_subtracted_whole(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');

        $this->postJournal('2026-08-15', [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 120_000],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 120_000],
        ], 'Straight to 5100 from the Accounting panel');

        $statement = $this->reconciler->compute('2026-08-01');

        $this->assertSame(120_000.0, $statement->glCost);
        $this->assertSame(120_000.0, $statement->unallocatedGlCost);
        $this->assertSame(0.0, $statement->expectedJobCost, 'it comes off whole');
        $this->assertTrue($statement->isBalanced(), 'and the report balances while naming it');
    }

    /**
     * And it is **named**, so balancing is not the same as being satisfied.
     *
     * A reconciliation that subtracted the unallocated cost and said nothing about it would balance perfectly on a
     * company posting half its cost outside the module.
     */
    public function test_unallocated_general_ledger_cost_is_named_as_a_cause(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->postJournal('2026-08-15', [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 120_000],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 120_000],
        ], 'JE-STRAY');

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'gl_without_cost_entry');

        $this->assertSame(1, $cause->count);
        $this->assertStringContainsString('never apportioned across jobs', $cause->explanation);
        $this->assertStringContainsString('the job is under-costed', $cause->explanation);
        $this->assertSame(120_000.0, $cause->rows[0]['amount']);
    }

    /**
     * **Never spread**, asserted directly: the cost lands on no job.
     *
     * Apportioning it would balance the report and put money on jobs nobody charged it to, which is worse than the gap
     * it hides.
     */
    public function test_unallocated_cost_is_never_pushed_onto_a_job(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->postJournal('2026-08-15', [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 120_000],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 120_000],
        ]);

        $this->reconciler->run('2026-08-01');

        $this->assertSame(0, CostEntry::query()->count(), 'the reconciliation writes no cost entry, ever');
    }

    // ---------------------------------------------------------------- the causes

    /**
     * **§4.2's nastiest**: a cost entry pointing at a journal entry that was later reversed.
     *
     * Nasty because the correction happened in another module and this one was never told. `JournalEntryService::reverse()`
     * dates the reversal today, so August's GL side stays intact and the difference surfaces where the cost sits.
     */
    public function test_a_cost_whose_journal_was_reversed_is_named(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');

        $journal = $this->postJournal('2026-08-15', [
            ['account_id' => $this->account('5100')->getKey(), 'debit_amount' => 90_000],
            ['account_id' => $this->account('2400', 'liability')->getKey(), 'credit_amount' => 90_000],
        ]);
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED, 'journal_entry_id' => $journal->getKey()]);

        app(JournalEntryService::class)->reverse($journal);

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'journal_reversed_or_unposted');

        $this->assertSame(1, $cause->count);
        $this->assertSame(90_000.0, $cause->amount);
        $this->assertStringContainsString('was never told', $cause->explanation);
    }

    /**
     * And a mirrored entry against an invoice nobody has posted is the same failure one step earlier.
     *
     * The allocation was made before the invoice was posted, so the job carries a cost the accounts have not seen and
     * nothing marks it as pending.
     */
    public function test_a_mirrored_entry_with_no_journal_behind_it_is_named(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'journal_reversed_or_unposted');

        $this->assertSame(1, $cause->count);
        $this->assertSame(90_000.0, $cause->amount);
        $this->assertFalse($this->reconciler->compute('2026-08-01')->isBalanced());
    }

    /**
     * **Rendered even when empty**, which is §4.2's explicit instruction for this cause and this cause alone.
     *
     * §5 calls an unallocated purchase invoice "the single most likely silent failure in the module". A section that
     * vanished when the list was empty would be indistinguishable from a section nobody had built.
     */
    public function test_unallocated_purchase_invoices_render_even_when_there_are_none(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'unallocated_purchase_invoices');

        $this->assertSame(0, $cause->count);
        $this->assertFalse($cause->isSignificant());
        $this->assertTrue($cause->shouldRender(), '§4.2 asks for this one specifically');
        $this->assertTrue($cause->renderWhenEmpty);
    }

    /** And when there is one, it is listed. */
    public function test_an_unallocated_purchase_invoice_is_listed(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');

        DB::table('invoices')->insert([
            'invoice_number' => 'PINV-1',
            'kind' => 'purchase',
            'contact_id' => DB::table('contacts')->insertGetId([
                'name' => 'A supplier', 'kind' => 'supplier', 'created_at' => now(), 'updated_at' => now(),
            ]),
            'invoice_date' => '2026-08-18',
            'status' => 'issued',
            'total' => 260_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'unallocated_purchase_invoices');

        $this->assertSame(1, $cause->count);
        $this->assertSame('PINV-1', $cause->rows[0]['invoice_number']);
        $this->assertStringContainsString('the point is that somebody has looked', $cause->explanation);
    }

    /**
     * **Absorption gaps name which account is missing**, which is the difference between a chase-list and a fix.
     *
     * §7.3: charge burden and absorb it nowhere and "job cost exceeds GL cost by exactly the burden, growing every
     * month, with no error anywhere".
     */
    public function test_an_absorption_gap_names_the_missing_account(): void
    {
        // Cost account nominated, burden absorption deliberately not.
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->burden(412_900);

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'absorption_gaps');

        $this->assertSame(1, $cause->count);
        $this->assertSame('Labour burden absorbed', $cause->rows[0]['purpose']);
        $this->assertSame(412_900.0, $cause->rows[0]['amount']);
        $this->assertStringContainsString('will not close on its own', $cause->explanation);
    }

    /** With the account nominated the gap closes, which is what makes the cause a diagnosis rather than noise. */
    public function test_nominating_the_account_closes_the_absorption_gap(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(412_900);

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'absorption_gaps');

        $this->assertSame(0, $cause->count);
    }

    /** Pending cost is shown **by age**, because a month old and six months old are different problems. */
    public function test_pending_cost_is_bucketed_by_age(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(10_000, ['incurred_on' => '2026-02-10']);
        $this->burden(20_000, ['incurred_on' => '2026-07-10']);
        $this->burden(30_000, ['incurred_on' => '2026-08-10']);

        $cause = collect($this->reconciler->compute('2026-08-01')->causes)
            ->firstWhere('key', 'pending_by_age');

        $this->assertSame(3, $cause->count);
        $ages = array_column($cause->rows, 'age');
        $this->assertContains('this month', $ages);
        $this->assertContains('one to three months', $ages);
        $this->assertContains('over three months', $ages);
    }

    /**
     * Cost on a closed job is a coding check, not a difference.
     *
     * Legitimate for a retention release or a defect rectification, and the shape of a mis-coded invoice otherwise. It
     * reconciles either way, which is why it carries no amount into the residual.
     */
    public function test_cost_on_a_closed_job_is_named_without_being_a_difference(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);
        $this->job->update(['status' => Job::STATUS_CLOSED]);

        $statement = $this->reconciler->compute('2026-08-01');
        $cause = collect($statement->causes)->firstWhere('key', 'closed_job_entries');

        $this->assertSame(1, $cause->count);
        $this->assertSame(0.0, $cause->amount, 'it reconciles — this is a coding check');
        $this->assertTrue($statement->isBalanced());
    }

    /**
     * **Rounding is isolated so it cannot be used to explain anything else** — §4.2, verbatim.
     *
     * The residual is what the named causes do not account for. A residual folded into any other line is a residual
     * that grows.
     */
    public function test_the_residual_is_its_own_cause_and_is_last(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        // A cost entry recorded with no GL side and no rule — nothing named can explain it.
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED, 'journal_entry_id' => null]);

        $causes = $this->reconciler->compute('2026-08-01')->causes;

        $this->assertSame('rounding', end($causes)->key);
    }

    /** And within a paisa it says so, rather than reporting arithmetic as a finding. */
    public function test_a_paisa_of_rounding_is_named_as_rounding(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);
        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $causes = $this->reconciler->compute('2026-08-01')->causes;
        $residual = end($causes);

        $this->assertSame(0, $residual->count);
        $this->assertStringContainsString('explains nothing else', $residual->explanation);
    }

    // ---------------------------------------------------------------- §4.3's mechanisms

    /** A run is stored whether or not it balances, because a history is what says whether a difference is new. */
    public function test_a_run_is_stored_either_way(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);
        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $balanced = $this->reconciler->run('2026-08-01');
        $this->assertSame(Reconciliation::STATUS_BALANCED, $balanced->status);

        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);
        $unbalanced = $this->reconciler->run('2026-08-01');

        $this->assertSame(Reconciliation::STATUS_UNBALANCED, $unbalanced->status);
        $this->assertSame(2, Reconciliation::query()->forPeriod('2026-08-01')->count());
        $this->assertSame($unbalanced->getKey(), Reconciliation::latestFor('2026-08-01')->getKey());
    }

    /**
     * **The stored figures do not move when the ledger does** — §3.4's argument about control totals.
     *
     * "A reconciliation computed later from live data cannot tell you what the figures were on the day somebody signed
     * the certificate."
     */
    public function test_a_stored_run_keeps_the_figures_it_was_computed_from(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);
        $run = $this->reconciler->run('2026-08-01');

        $this->burden(900_000);

        $this->assertSame('50000.00', $run->fresh()->pending_job_cost);
    }

    /** An unbalanced run blocks a close; an accepted one does not. §4.3's second and third mechanisms. */
    public function test_an_unbalanced_run_blocks_a_close_and_an_accepted_one_does_not(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);

        $run = $this->reconciler->run('2026-08-01');
        $this->assertTrue($run->blocksClose());

        $this->reconciler->accept($run, 'The supplier journal was posted to the wrong month and is being corrected.');

        $this->assertFalse($run->fresh()->blocksClose());
        $this->assertTrue($run->fresh()->isAccepted());
    }

    /**
     * **Accepting fixes nothing, and that is §4.3's fourth mechanism.**
     *
     * "A forced close never fudges the ledger. No plug entry, no balancing figure. Both sides stay true and the
     * difference stays visible in every later period until the cause is fixed."
     */
    public function test_accepting_a_difference_writes_no_balancing_entry(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);

        $entriesBefore = CostEntry::query()->count();
        $journalsBefore = JournalEntry::query()->count();

        $run = $this->reconciler->run('2026-08-01');
        $this->reconciler->accept($run, 'Being corrected next month.');

        $this->assertSame($entriesBefore, CostEntry::query()->count(), 'no plug entry');
        $this->assertSame($journalsBefore, JournalEntry::query()->count(), 'no balancing journal');
        $this->assertSame('-90000.00', $run->fresh()->difference, 'and the difference is still there');
        $this->assertFalse($this->reconciler->compute('2026-08-01')->isBalanced(), 'in every later look');
    }

    /** Accepting needs a reason. It is the only thing the acceptance records. */
    public function test_accepting_without_a_reason_is_refused(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);
        $run = $this->reconciler->run('2026-08-01');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');
        $this->reconciler->accept($run, '  ');
    }

    /** And a balanced run has nothing to accept. */
    public function test_a_balanced_run_cannot_be_accepted(): void
    {
        $this->nominateCostAndBurden();
        $run = $this->reconciler->run('2026-08-01');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no difference to accept');
        $this->reconciler->accept($run, 'Fine by me.');
    }

    /**
     * **Accepting is `ConstructionPeriodForceClose`**, which §4.3 names.
     *
     * Asserted against the policy class rather than the gate, because `Gate::before` makes an Administrator pass every
     * ability and the assertion would then be about nothing.
     */
    public function test_accepting_is_the_force_close_grant(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);
        $run = $this->reconciler->run('2026-08-01');

        $policy = app(ReconciliationPolicy::class);

        $this->assertFalse($policy->accept($this->makeUser('Manager', 'closer@test.local'), $run));
        $this->assertTrue($policy->accept($this->makeUser('CEO', 'forcecloser@test.local'), $run));
    }

    /** Running one is a read, so it rides on the view grant — a control nobody may run is not a control. */
    public function test_running_a_reconciliation_rides_on_the_view_grant(): void
    {
        $policy = app(ReconciliationPolicy::class);

        $this->assertTrue($policy->create($this->makeUser('Accountant', 'surveyor2@test.local')));
    }

    /** A run is never edited and never deleted: the way to change its answer is to run it again. */
    public function test_a_run_is_neither_editable_nor_deletable(): void
    {
        $this->nominateCostAndBurden();
        $run = $this->reconciler->run('2026-08-01');
        $policy = app(ReconciliationPolicy::class);
        $ceo = $this->makeUser('CEO', 'ceo2@test.local');

        $this->assertFalse($policy->update($ceo, $run));
        $this->assertFalse($policy->delete($ceo, $run));
    }

    // ---------------------------------------------------------------- the command

    /**
     * **§4.3's first mechanism**, and the exit code is what a scheduler watches.
     *
     * Non-zero because the company's bookkeeping failed, not because the command did — every period was reconciled and
     * every row was written.
     */
    public function test_the_command_writes_a_row_and_fails_loudly_when_unbalanced(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(90_000, ['gl_treatment' => CostEntry::GL_MIRRORED]);

        $this->assertSame(1, $this->runCommand('2026-08-01'));
        $this->assertSame(Reconciliation::STATUS_UNBALANCED, Reconciliation::latestFor('2026-08-01')->status);
    }

    /** And succeeds quietly when it balances. */
    public function test_the_command_succeeds_when_the_period_balances(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);
        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $this->assertSame(0, $this->runCommand('2026-08-01'));
        $this->assertSame(Reconciliation::STATUS_BALANCED, Reconciliation::latestFor('2026-08-01')->status);
    }

    /**
     * **Every open period, not just the current one.**
     *
     * A difference that appeared in March and was never looked at does not stop being a difference in July, and a
     * command that only checked the current month would report a clean bill of health on a company with four months of
     * gaps behind it.
     */
    public function test_the_command_reconciles_every_open_period(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(10_000, ['incurred_on' => '2026-06-10', 'gl_treatment' => CostEntry::GL_MIRRORED]);
        $this->record(20_000, ['incurred_on' => '2026-07-10', 'gl_treatment' => CostEntry::GL_MIRRORED]);
        $this->record(30_000, ['incurred_on' => '2026-08-10', 'gl_treatment' => CostEntry::GL_MIRRORED]);

        $this->runCommand();

        foreach (['2026-06-01', '2026-07-01', '2026-08-01'] as $period) {
            $this->assertNotNull(Reconciliation::latestFor($period), "{$period} was reconciled");
        }
    }

    /**
     * **Closed periods are skipped.**
     *
     * Their figures are what somebody signed, they cannot change, and re-reporting them nightly would bury the one
     * month that still can be fixed.
     */
    public function test_the_command_leaves_closed_periods_alone(): void
    {
        $this->nominate('5100', ControlAccount::KIND_COST, null, 'labour');
        $this->record(10_000, ['incurred_on' => '2026-06-10', 'gl_treatment' => CostEntry::GL_MIRRORED]);
        CostPeriod::forDate('2026-06-01')->update(['status' => CostPeriod::STATUS_CLOSED]);

        $this->runCommand();

        $this->assertNull(Reconciliation::latestFor('2026-06-01'));
    }

    /** Skipped entirely for a company without the module, like every other scheduled command in this suite. */
    public function test_the_command_skips_a_company_without_the_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())
            ->where('module', 'construction_costing')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertSame(0, $this->runCommand('2026-08-01'));
        $this->assertSame(0, Reconciliation::query()->count());
    }

    // ---------------------------------------------------------------- the page

    /** It renders, and the statement is on it. */
    public function test_the_report_renders_the_statement(): void
    {
        $this->nominateCostAndBurden();
        $this->burden(50_000);
        app(ConstructionGlPostingService::class)->post('2026-08-01');

        Livewire::test(ReconciliationReport::class)
            ->set('data.period_start', '2026-08-01')
            ->assertSuccessful()
            ->assertSee('Expected job-cost total')
            ->assertSee('Difference');
    }

    /** A month nobody has ever proved says so, because §4's opening sentence is about exactly that state. */
    public function test_the_report_says_when_a_month_has_never_been_reconciled(): void
    {
        $this->nominateCostAndBurden();

        Livewire::test(ReconciliationReport::class)
            ->set('data.period_start', '2026-08-01')
            ->assertSee('has never been reconciled');
    }

    /** And the refusal reaches the page rather than a difference equal to the whole job. */
    public function test_the_report_prints_the_refusal_when_no_account_is_nominated(): void
    {
        $this->burden(412_900);

        Livewire::test(ReconciliationReport::class)
            ->set('data.period_start', '2026-08-01')
            ->assertSee('Nothing to reconcile against')
            ->assertDontSee('412,900.00');
    }
}
