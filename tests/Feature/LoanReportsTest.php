<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\LoansOutstanding;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\LoanService;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Loans Outstanding — `docs/reports-expansion-plan.md` Phase 1.6.
 *
 * `LoanTest` owns the amortisation: the level instalment, the falling interest, the table closing to
 * exactly nought. None of that is re-asserted here.
 *
 * **The assertion this file exists for is the reconciliation**, and Phase 2's rule is the one it follows
 * even though this is a Phase 1 report: "post entries, run the report, assert the record row equals the
 * ledger balance for the accounts behind it. A row-count assertion proves nothing here." The schedule and
 * the liability account are two independent statements of the same debt, and the report's job is to say
 * whether they agree — so the tests below make them agree, then make them disagree on purpose and check
 * that the report notices.
 */
class LoanReportsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'loanreport@test.local'));
        $this->setCurrentTenant();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** A twelve-month loan with its schedule built, and nothing recorded against it yet. */
    private function loan(array $attributes = []): Loan
    {
        $loan = Loan::create($attributes + [
            'name' => 'Vehicle finance',
            'lender' => 'Meezan',
            'liability_account_id' => $this->account('2100')->id,
            'interest_account_id' => $this->account('5900')->id,
            'payment_account_id' => $this->account('1100')->id,
            'principal' => 1_200_000,
            'annual_rate' => 12,
            'term_months' => 12,
            'starts_on' => '2026-07-05',
        ]);

        app(LoanService::class)->generateSchedule($loan);

        return $loan->fresh('instalments');
    }

    /**
     * The drawdown, posted by hand.
     *
     * Nothing in this application posts it — a loan is created with a schedule and the money arriving is a
     * journal entry somebody writes. Which is exactly why the report compares the two: the ledger side of a
     * loan is maintained separately from the schedule side, and separately maintained figures drift.
     */
    private function postDrawdown(Loan $loan, float $amount, string $date = '2026-07-01'): void
    {
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Loan drawdown'],
            [
                ['account_id' => $loan->payment_account_id, 'debit_amount' => $amount],
                ['account_id' => $loan->liability_account_id, 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry->fresh());
    }

    /**
     * Record instalments *and* post their entries.
     *
     * `recordInstalment()` posts only where a second approver is not required, and in this environment one
     * is — so left alone it stamps the instalment and leaves a draft, which moves no balance. That is the
     * application working correctly and it would have made every reconciliation assertion below vacuous:
     * the schedule would have advanced while the ledger stood still, and the report would have been right
     * to say they disagreed.
     */
    private function recordInstalments(Loan $loan, int $count): void
    {
        foreach ($loan->instalments()->orderBy('number')->take($count)->get() as $instalment) {
            $entry = app(LoanService::class)->recordInstalment($instalment);

            if (! $entry->is_posted) {
                $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
                app(JournalEntryService::class)->post($entry->fresh());
            }
        }
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('LoansOutstanding', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for LoansOutstanding');

        return $payload;
    }

    // ─────────────────────────────────────────────────── the reconciliation ──

    /**
     * The schedules and the liability accounts agree, and the report says so.
     *
     * Two instalments recorded: the account has fallen by exactly their principal, and the schedule's
     * closing balance says the same thing by a completely different route. This is the claim — not that
     * two rows rendered.
     */
    public function test_the_outstanding_total_equals_the_liability_account_balance(): void
    {
        $loan = $this->loan();
        $this->postDrawdown($loan, 1_200_000);
        $this->recordInstalments($loan, 2);

        $payload = $this->report();

        $scheduled = $payload['tiles'][0]['value'];
        $ledger = $payload['tiles'][1]['value'];

        $this->assertSame($scheduled, $ledger, 'the schedule and the liability account disagree');
        // And it is the schedule's own figure, not nought or the full principal.
        $this->assertSame(
            round((float) $loan->fresh()->scheduledOutstanding(), 2),
            $scheduled,
        );
        $this->assertStringContainsString('THE SCHEDULES AGREE WITH THE LIABILITY ACCOUNTS', $payload['note']);
    }

    /**
     * And when they do not agree, it names the difference rather than picking a side.
     *
     * An instalment paid outside the application is the everyday version of this: the bank statement moved
     * and the schedule did not. The report cannot know which figure is right, so it states both and the gap.
     */
    public function test_it_names_the_difference_when_the_accounts_disagree(): void
    {
        $loan = $this->loan();
        $this->postDrawdown($loan, 1_200_000);
        $this->recordInstalments($loan, 2);

        // Somebody pays 50,000 off the loan through the bank, by hand, without touching the schedule.
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-08-10', 'entry_type' => 'general', 'memo' => 'Ad-hoc loan repayment'],
            [
                ['account_id' => $loan->liability_account_id, 'debit_amount' => 50_000],
                ['account_id' => $loan->payment_account_id, 'credit_amount' => 50_000],
            ],
        );
        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry->fresh());

        $payload = $this->report();

        $this->assertSame(50_000.0, round($payload['tiles'][0]['value'] - $payload['tiles'][1]['value'], 2));
        $this->assertStringContainsString('DIFFER BY 50,000.00', $payload['note']);
        $this->assertStringContainsString('OUTSIDE THE APPLICATION', $payload['note']);
    }

    /** Unposted entries move nothing, here as everywhere else in this application. */
    public function test_an_unposted_entry_does_not_move_the_ledger_figure(): void
    {
        $loan = $this->loan();
        $this->postDrawdown($loan, 1_200_000);

        // Created and left as a draft: no approval, no posting.
        app(JournalEntryService::class)->create(
            ['entry_date' => '2026-08-10', 'entry_type' => 'general', 'memo' => 'Draft repayment'],
            [
                ['account_id' => $loan->liability_account_id, 'debit_amount' => 400_000],
                ['account_id' => $loan->payment_account_id, 'credit_amount' => 400_000],
            ],
        );

        $payload = $this->report();

        $this->assertSame(1_200_000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('AGREE', $payload['note']);
    }

    /** Two loans sharing a liability account count its balance once, not twice. */
    public function test_a_shared_liability_account_is_counted_once(): void
    {
        $first = $this->loan(['name' => 'Vehicle finance']);
        $second = $this->loan(['name' => 'Plant finance']);

        $this->postDrawdown($first, 1_200_000);
        $this->postDrawdown($second, 1_200_000, '2026-07-02');

        $payload = $this->report();

        // Both schedules untouched, so 2.4m outstanding — and one account holding 2.4m, counted once.
        $this->assertSame(2_400_000.0, $payload['tiles'][0]['value']);
        $this->assertSame(2_400_000.0, $payload['tiles'][1]['value']);
    }

    // ──────────────────────────────────────────────────────── the schedule ──

    /** A loan with nothing recorded owes its principal, not nought. */
    public function test_a_loan_with_nothing_recorded_shows_its_full_principal(): void
    {
        $this->loan();

        $payload = $this->report();

        $this->assertSame('1,200,000', $payload['rows'][0][1]);
        $this->assertSame('0 of 12', $payload['rows'][0][4]);
    }

    /** Interest to come excludes interest already paid: that is in the profit and loss. */
    public function test_interest_to_come_excludes_what_has_been_recorded(): void
    {
        $loan = $this->loan();
        $before = (float) str_replace(',', '', $this->report()['rows'][0][2]);

        $this->recordInstalments($loan, 3);
        $after = (float) str_replace(',', '', $this->report()['rows'][0][2]);

        $this->assertGreaterThan($after, $before, 'recording instalments should reduce the interest still to come');
        // And what is left equals the unrecorded rows' own interest.
        $this->assertSame(
            round((float) $loan->instalments()->whereNull('journal_entry_id')->sum('interest'), 0),
            round($after, 0),
        );
    }

    /**
     * The twelve-month window is the current portion, and it excludes what falls after it.
     *
     * A five-year loan's whole schedule in a "due in 12 months" column is the figure a balance sheet note
     * would then state wrongly.
     */
    public function test_due_in_twelve_months_excludes_instalments_beyond_the_window(): void
    {
        $loan = $this->loan(['term_months' => 36]);

        $payload = $this->report();
        $due = (float) str_replace(',', '', $payload['rows'][0][3]);
        $everything = (float) $loan->instalments()->sum('payment');

        $this->assertGreaterThan(0.0, $due);
        $this->assertLessThan($everything, $due, 'a 36-month schedule cannot all be due inside 12 months');
    }

    /** Next due is the first unrecorded instalment, and "Settled" once there is none. */
    public function test_next_due_reads_settled_once_every_instalment_is_recorded(): void
    {
        $loan = $this->loan();

        $this->assertSame('2026-07-05', $this->report()['rows'][0][5]);

        $this->recordInstalments($loan, 12);

        $payload = $this->report();
        $this->assertSame('Settled', $payload['rows'][0][5]);
        $this->assertSame('12 of 12', $payload['rows'][0][4]);
        // A fully repaid loan owes nothing — the bug Loan::scheduledOutstanding()'s own comment records is
        // reading instalment 1 instead of the last, which would report the opening balance here.
        $this->assertSame('0', $payload['rows'][0][1]);
    }

    /** A loan whose schedule was never generated says so rather than reading "0 of 0". */
    public function test_a_loan_with_no_schedule_says_so(): void
    {
        Loan::create([
            'name' => 'Unscheduled',
            'liability_account_id' => $this->account('2100')->id,
            'interest_account_id' => $this->account('5900')->id,
            'payment_account_id' => $this->account('1100')->id,
            'principal' => 500_000,
            'annual_rate' => 10,
            'term_months' => 24,
            'starts_on' => '2026-07-05',
        ]);

        $this->assertSame('No schedule', $this->report()['rows'][0][4]);
    }

    /** An inactive loan is off the report, and so is its account balance. */
    public function test_an_inactive_loan_is_not_listed(): void
    {
        $loan = $this->loan();
        $this->postDrawdown($loan, 1_200_000);
        $loan->update(['is_active' => false]);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO ACTIVE LOAN', $payload['note']);
    }

    /** The lender is named beside the loan: two "Vehicle finance" loans are otherwise one row twice. */
    public function test_the_lender_is_named_beside_the_loan(): void
    {
        $this->loan();

        $this->assertSame('Vehicle finance · Meezan', $this->report()['rows'][0][0]);
    }

    /** The record row foots the rows above it. */
    public function test_the_record_row_foots_the_rows(): void
    {
        $this->loan(['name' => 'Vehicle finance']);
        $this->loan(['name' => 'Plant finance']);

        $payload = $this->report();

        foreach ([1, 2, 3] as $column) {
            $rows = array_sum(array_map(
                fn (array $row): float => (float) str_replace(',', '', $row[$column]),
                $payload['rows'],
            ));

            // Within a rupee per row, not exactly. Each cell is rounded for display and the record row
            // states the true total — a footer that instead added up the *rounded* cells would agree with
            // the screen and disagree with the ledger, which is the wrong one of the two to be right about.
            $this->assertLessThanOrEqual(
                count($payload['rows']),
                abs((float) str_replace(',', '', $payload['footer'][$column]) - $rows),
                "column {$column} does not add up the rows above it",
            );
        }
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $loan = $this->loan();
        $this->postDrawdown($loan, 1_200_000);

        $onThePage = Livewire::test(LoansOutstanding::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('LoansOutstanding', self::AS_OF, false, []));
    }

    /**
     * And on `ReportView`, like every report page here.
     *
     * As somebody else: this class acts as an Administrator throughout, and an Administrator has
     * `ReportView` — asserting the gate as them would assert that the gate is open, which it should be.
     */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(LoansOutstanding::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));

        $this->assertFalse(LoansOutstanding::canAccess());
    }
}
