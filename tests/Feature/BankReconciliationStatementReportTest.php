<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\BankReconciliationStatement as BankReconciliationPage;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\User;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Bank Reconciliation Statement — `docs/reports-expansion-plan.md` Phase 2.6.
 *
 * `BankReconciliationTest` owns the matching engine: the date window, the guards, what `complete()` refuses.
 * None of it is restated here.
 *
 * What this file asserts is the report's own four claims:
 *
 *  - **The identity holds**: bank balance, less unpresented cheques, plus deposits in transit, equals the
 *    books. Every test below builds a real position and checks the arithmetic against a hand-computed figure
 *    rather than against the report's own totals.
 *  - **The batched ledger figure equals `BankReconciliationService::ledgerBalance()`**, statement by
 *    statement. That is the specification, not a nicety: `ledgerBalance()` is what `complete()` checks
 *    against, so a report that computed the same figure a second way could tell a company its books agree
 *    while the workflow says they do not. Same protection the stocktake's batched valuation carries.
 *  - **A statement carrying unpresented items cannot be completed**, which is why this report is about open
 *    statements. Asserted here as the behaviour it is, because it is the reason the report exists in this
 *    shape and a silent change to it would make the note below a lie.
 *  - **The date picks a statement, not a cut-off**: the latest at or before it, per account.
 */
class BankReconciliationStatementReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-31';

    private BankReconciliationService $service;

    private JournalEntryService $entries;

    private Account $bank;

    private Account $revenue;

    private Account $expense;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Two users, deliberately. `JournalEntryService::approve()` refuses an entry approved by its own
         * creator — segregation of duties — so the fixture cannot post anything as a single user, and every
         * entry below is created by the administrator and approved by the manager.
         */
        $this->actingAs($this->makeUser('Administrator', 'bankreport@test.local'));
        $this->approver = $this->makeUser('Manager', 'bankapprover@test.local');
        $this->setCurrentTenant();

        $this->service = app(BankReconciliationService::class);
        $this->entries = app(JournalEntryService::class);
        $this->bank = Account::where('code', '1100')->firstOrFail();
        $this->revenue = Account::where('code', '4100')->firstOrFail();
        $this->expense = Account::where('code', '5100')->firstOrFail();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    /** Money in: bank debited. Returns the bank ledger line. */
    private function inflow(string $date, float $amount): JournalEntryLine
    {
        return $this->bankLine($this->postEntry($date, [
            ['account_id' => $this->bank->id, 'debit_amount' => $amount],
            ['account_id' => $this->revenue->id, 'credit_amount' => $amount],
        ]));
    }

    /** Money out: bank credited. Returns the bank ledger line. */
    private function outflow(string $date, float $amount): JournalEntryLine
    {
        return $this->bankLine($this->postEntry($date, [
            ['account_id' => $this->expense->id, 'debit_amount' => $amount],
            ['account_id' => $this->bank->id, 'credit_amount' => $amount],
        ]));
    }

    /** Create, approve and post. Returns the entry — not every fixture entry touches the bank account. */
    private function postEntry(string $date, array $lines): JournalEntry
    {
        $entry = $this->entries->create(['entry_date' => $date], $lines);
        $this->entries->submitForApproval($entry);
        $this->entries->approve($entry, $this->approver);
        $this->entries->post($entry);

        return $entry;
    }

    private function bankLine(JournalEntry $entry): JournalEntryLine
    {
        return $entry->lines()->where('account_id', $this->bank->id)->firstOrFail();
    }

    private function statement(float $closing, string $date = '2026-08-31', ?Account $account = null): BankStatement
    {
        return BankStatement::create([
            'account_id' => ($account ?? $this->bank)->id,
            'statement_date' => $date,
            'opening_balance' => 0,
            'closing_balance' => $closing,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('BankReconciliationStatement', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for BankReconciliationStatement');

        return $payload;
    }

    private function figure(string $cell): float
    {
        return (float) str_replace([',', '(', ')'], '', $cell);
    }

    // ───────────────────────────────────────────────────────────── the identity ──

    /**
     * Bank, less unpresented, plus in transit, equals the books — and the difference is nil.
     *
     * The textbook case: 500 banked and seen, a 120 cheque written and not yet paid, a 60 deposit banked on
     * the last day and not yet credited. The bank therefore says 500 while the books say 440.
     */
    public function test_it_reconciles_the_bank_to_the_books(): void
    {
        $seen = $this->inflow('2026-08-05', 500);
        $this->outflow('2026-08-20', 120);   // cheque, unpresented
        $this->inflow('2026-08-31', 60);     // deposit in transit

        $statement = $this->statement(500);
        $this->service->import([['transaction_date' => '2026-08-05', 'amount' => 500]], $statement);
        $this->service->autoMatch($statement);

        $this->assertTrue($seen->fresh()->isReconciled(), 'the matched line carries reconciled_at');

        $payload = $this->report();
        $row = $payload['rows'][0];

        $this->assertSame(500.0, $this->figure($row[2]), 'per bank');
        $this->assertSame(120.0, $this->figure($row[3]), 'unpresented cheque');
        $this->assertSame(60.0, $this->figure($row[4]), 'deposit in transit');
        $this->assertSame(440.0, $this->figure($row[5]), 'per books: 500 - 120 + 60');
        $this->assertSame('—', $row[6], '500 - 120 + 60 = 440, so nothing is left over');

        $this->assertSame(440.0, $payload['tiles'][0]['value'], 'per books');
        $this->assertSame(440.0, $payload['tiles'][1]['value'], 'per bank, adjusted');
        $this->assertStringContainsString('EVERY ACCOUNT RECONCILES TO THE BOOKS', $payload['note']);
    }

    /** A cheque the bank has not paid is a credit the ledger has and the statement does not. */
    public function test_an_unpresented_cheque_is_named_and_signed(): void
    {
        $this->inflow('2026-08-05', 500);
        $this->outflow('2026-08-20', 120);

        $statement = $this->statement(500);
        $this->service->import([['transaction_date' => '2026-08-05', 'amount' => 500]], $statement);
        $this->service->autoMatch($statement);

        $payload = $this->report();

        // Bracketed, because it comes off the bank's figure.
        $this->assertSame('(120)', $payload['rows'][0][3]);
        $this->assertSame(380.0, $this->figure($payload['rows'][0][5]));
        $this->assertStringContainsString('120 UNPRESENTED', $payload['note']);
    }

    /**
     * A charge the bank applied and nobody booked is the difference that survives both adjustments.
     *
     * It is counted in the note rather than added into the reconciliation: the amount is the bank's figure,
     * and folding it in would be asserting the journal entry it should have produced.
     */
    public function test_an_unbooked_statement_line_is_counted_not_valued(): void
    {
        $this->inflow('2026-08-05', 500);

        $statement = $this->statement(485);
        $this->service->import([
            ['transaction_date' => '2026-08-05', 'amount' => 500],
            ['transaction_date' => '2026-08-31', 'amount' => -15, 'description' => 'Service charge'],
        ], $statement);
        $this->service->autoMatch($statement);

        $payload = $this->report();

        $this->assertSame(485.0, $this->figure($payload['rows'][0][2]), 'the bank has taken its charge');
        $this->assertSame(500.0, $this->figure($payload['rows'][0][5]), 'the books have not');
        $this->assertSame(15.0, $this->figure($payload['rows'][0][6]), 'and that is the difference');
        $this->assertStringContainsString('1 OUT OF BALANCE', $payload['note']);
        $this->assertStringContainsString('1 STATEMENT LINES ARE MATCHED TO NOTHING', $payload['note']);
    }

    /** Excluding a line puts its ledger side back into unpresented, because nobody claims they tie. */
    public function test_excluding_a_match_returns_the_ledger_line_to_unpresented(): void
    {
        $this->inflow('2026-08-05', 500);

        $statement = $this->statement(500);
        $this->service->import([['transaction_date' => '2026-08-05', 'amount' => 500]], $statement);
        $this->service->autoMatch($statement);

        $this->assertSame('—', $this->report()['rows'][0][4], 'nothing in transit while it is matched');

        $this->service->exclude($statement->lines()->first());

        $this->assertSame(500.0, $this->figure($this->report()['rows'][0][4]), 'and back in transit after');
    }

    // ─────────────────────────────────────────────────────────── the equivalence ──

    /**
     * The batched ledger figure equals `ledgerBalance()`, statement by statement.
     *
     * The specification for computing it a second way. `ledgerBalance()` builds the account's whole ledger to
     * return one closing figure, which is right for one statement on screen and wrong for a report over every
     * bank account; this asserts the cheap version cannot disagree with the one `complete()` checks against.
     */
    public function test_the_batched_ledger_figure_agrees_with_the_service(): void
    {
        $second = Account::where('code', '1200')->firstOrFail();

        $this->inflow('2026-08-05', 500);
        $this->outflow('2026-08-20', 120);
        $this->postEntry('2026-08-10', [
            ['account_id' => $second->id, 'debit_amount' => 900],
            ['account_id' => $this->revenue->id, 'credit_amount' => 900],
        ]);

        $statements = [
            $this->statement(500),
            $this->statement(900, '2026-08-31', $second),
        ];

        $payload = $this->report();

        foreach ($statements as $statement) {
            $expected = $this->service->ledgerBalance($statement);
            $row = collect($payload['rows'])
                ->first(fn (array $row): bool => str_starts_with($row[0], $statement->account->code));

            $this->assertNotNull($row, "no row for {$statement->account->code}");
            $this->assertSame(
                $expected,
                $this->figure($row[5]),
                "the report and ledgerBalance() disagree for {$statement->account->code}",
            );
        }
    }

    /** Each account's figure is read at its own statement date, not at one shared cut-off. */
    public function test_each_account_is_read_at_its_own_statement_date(): void
    {
        $second = Account::where('code', '1200')->firstOrFail();

        $this->inflow('2026-07-10', 400);
        // After the July statement, so it must not be in that account's figure.
        $this->inflow('2026-08-15', 250);
        $this->postEntry('2026-08-15', [
            ['account_id' => $second->id, 'debit_amount' => 900],
            ['account_id' => $this->revenue->id, 'credit_amount' => 900],
        ]);

        $july = $this->statement(400, '2026-07-31');
        $this->statement(900, '2026-08-31', $second);

        $payload = $this->report();
        $row = collect($payload['rows'])->first(fn (array $r): bool => str_starts_with($r[0], $this->bank->code));

        $this->assertStringContainsString('2026-07-31', $row[1]);
        $this->assertSame(400.0, $this->figure($row[5]), 'August money is after the July statement');
        $this->assertSame($this->service->ledgerBalance($july), $this->figure($row[5]));
    }

    // ─────────────────────────────────────────────── which statement, and whose ──

    /** The latest statement at or before the date, per account. */
    public function test_it_uses_the_latest_statement_at_or_before_the_date(): void
    {
        $this->inflow('2026-07-10', 400);
        $this->inflow('2026-08-15', 250);

        $this->statement(400, '2026-07-31');
        $this->statement(650, '2026-08-31');

        // Both are drafts, so both carry the marker; what this asserts is *which* one is picked.
        $this->assertSame('2026-08-31 (open)', $this->report()['rows'][0][1]);
        $this->assertSame('2026-07-31 (open)', $this->report('2026-08-01')['rows'][0][1]);
        $this->assertCount(1, $this->report()['rows'], 'one row per account, not one per statement');
    }

    /** An account with no statement is absent, not a row of noughts. */
    public function test_an_account_with_no_statement_is_not_listed(): void
    {
        $this->inflow('2026-08-05', 500);
        $this->statement(500);

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString($this->bank->code, $payload['rows'][0][0]);
    }

    /** No statement anywhere reads as such rather than as a nil reconciliation. */
    public function test_no_statements_at_all_says_so(): void
    {
        $this->inflow('2026-08-05', 500);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertNull($payload['footer']);
        $this->assertSame('NO BANK STATEMENT AT OR BEFORE THIS DATE', $payload['note']);
    }

    /** A completed statement is marked as such; an open one says so on its row. */
    public function test_an_open_statement_is_marked(): void
    {
        $this->inflow('2026-08-05', 500);
        $statement = $this->statement(500);
        $this->service->import([['transaction_date' => '2026-08-05', 'amount' => 500]], $statement);
        $this->service->autoMatch($statement);

        $this->assertStringContainsString('(open)', $this->report()['rows'][0][1]);

        $this->service->complete($statement->fresh(), $this->approver);

        $this->assertSame('2026-08-31', $this->report()['rows'][0][1], 'completed, so no marker');
    }

    // ──────────────────────────────────────── the finding this report is shaped by ──

    /**
     * A statement carrying an unpresented cheque cannot be completed at all.
     *
     * Asserted as the behaviour it is, because it is *why* this report is about open statements: `complete()`
     * compares the statement's closing balance against the ledger and refuses anything else, so the
     * statements with something to reconcile are exactly the ones it will not close. If that rule is ever
     * relaxed, this test fails and the report's note stops being true — which is the point of pinning it.
     */
    public function test_a_statement_with_an_unpresented_cheque_cannot_be_completed(): void
    {
        $this->inflow('2026-08-05', 500);
        $this->outflow('2026-08-20', 120);

        $statement = $this->statement(500);
        $this->service->import([['transaction_date' => '2026-08-05', 'amount' => 500]], $statement);
        $this->service->autoMatch($statement);

        $this->assertTrue($statement->fresh()->isFullyMatched(), 'every statement line is matched');

        try {
            $this->service->complete($statement->fresh(), $this->approver);
            $this->fail('a statement with an unpresented cheque was completed');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not match ledger balance', $e->getMessage());
        }

        // And the report is what names the 120 the workflow rejects.
        $payload = $this->report();
        $this->assertSame(120.0, $this->figure($payload['rows'][0][3]));
        $this->assertStringContainsString('CANNOT BE COMPLETED AT ALL', $payload['note']);
    }

    // ─────────────────────────────────────────────────────── how it is stated ──

    /** The footer foots the columns it sits under. */
    public function test_the_footer_foots_the_columns(): void
    {
        $second = Account::where('code', '1200')->firstOrFail();

        $this->inflow('2026-08-05', 500);
        $this->outflow('2026-08-20', 120);
        $this->postEntry('2026-08-10', [
            ['account_id' => $second->id, 'debit_amount' => 900],
            ['account_id' => $this->revenue->id, 'credit_amount' => 900],
        ]);

        $this->statement(500);
        $this->statement(900, '2026-08-31', $second);

        $payload = $this->report();

        $this->assertSame(120.0, $this->figure($payload['footer'][3]), 'one unpresented cheque across both');
        $this->assertSame(
            array_sum(array_map(fn (array $row): float => $this->figure($row[5]), $payload['rows'])),
            $this->figure($payload['footer'][5]),
        );
    }

    /** A handful of queries whatever the account count, not a handful per account. */
    public function test_the_report_does_not_query_per_account(): void
    {
        foreach (['1100', '1200', '1300', '1400'] as $code) {
            $account = Account::where('code', $code)->firstOrFail();
            $this->postEntry('2026-08-05', [
                ['account_id' => $account->id, 'debit_amount' => 100],
                ['account_id' => $this->revenue->id, 'credit_amount' => 100],
            ]);
            $this->statement(100, '2026-08-31', $account);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(4, $payload['rows']);
        $this->assertLessThanOrEqual(
            8,
            $queries,
            "the report ran {$queries} queries for four accounts, which is per-account rather than aggregate",
        );
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->inflow('2026-08-05', 500);
        $this->statement(500);

        $onThePage = Livewire::test(BankReconciliationPage::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame(
            $onThePage,
            app(ReportPaneRenderer::class)->for('BankReconciliationStatement', self::AS_OF, false, []),
        );
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(BankReconciliationPage::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(User::factory()->create(['status' => 1]));

        $this->assertFalse(BankReconciliationPage::canAccess());
    }
}
