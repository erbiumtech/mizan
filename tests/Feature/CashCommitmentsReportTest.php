<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\CashCommitments as CashCommitmentsPage;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\ScheduledTransactionService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Support\CashCommitments;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Cash Commitments — `docs/reports-expansion-plan.md` Phase 1.7.
 *
 * The plan's note is "nothing in the application answers this today", and three separate runners were the
 * reason: each of scheduled entries, beneficiary subscriptions and recurring invoices had something that
 * *raised* them and nothing that listed what was coming.
 *
 * Three claims carry this file, and each is a way the report could be plausibly wrong:
 *
 *  - **It looks forward, uncapped.** `outstandingFor()` answers a posting run — outstanding only, and at
 *    most `MAX_PER_RUN` of it. Both limits are right for a run and wrong for a ninety-day view, and neither
 *    would show up as an error: the report would simply be short.
 *  - **It knows what is already raised.** A commitment and a payable are different things, and a list that
 *    conflated them would double-count everything the runner had already done.
 *  - **It asks the modules rather than importing them.** A company with no invoicing gets a shorter list,
 *    not an error — and the report never names a module the company has not bought.
 */
class CashCommitmentsReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'commitments@test.local'));
        $this->setCurrentTenant();
    }

    /**
     * Put the registry back, because this file is the only thing in the suite that empties it.
     *
     * `CashCommitments` is static, so a flush outlives the test that did it. In practice every following
     * test boots a fresh application and both providers register again — but that is a property of how the
     * suite happens to run, not a promise, and it would stop being true the day registration moved into
     * `register()` or behind a singleton. Restoring here costs nothing and means the test cannot be the
     * reason something unrelated fails three hundred cases later.
     */
    protected function tearDown(): void
    {
        CashCommitments::flush();

        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** A monthly scheduled entry of a fixed amount, running from before the window. */
    private function schedule(array $attributes = [], float $amount = 25_000): ScheduledTransaction
    {
        $schedule = ScheduledTransaction::create($attributes + [
            'name' => 'Office rent',
            'entry_type' => 'general',
            'interval_months' => 1,
            'day_of_month' => 5,
            'starts_on' => '2026-01-05',
            'is_active' => true,
        ]);

        $schedule->lines()->createMany([
            ['account_id' => $this->account('5100')->id, 'debit_amount' => $amount, 'sort' => 1],
            ['account_id' => $this->account('1100')->id, 'credit_amount' => $amount, 'sort' => 2],
        ]);

        return $schedule->fresh('lines');
    }

    private function subscription(float $amount = 9_000, int $dueDay = 12): BeneficiarySubscription
    {
        $beneficiary = Beneficiary::create(['name' => 'Cloud host']);

        return BeneficiarySubscription::create([
            'beneficiary_id' => $beneficiary->id,
            'transaction_type_id' => TransactionType::query()->value('id'),
            'description' => 'Hosting',
            'amount' => $amount,
            'due_day' => $dueDay,
            'starts_on' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    private function recurringInvoice(float $amount = 40_000, int $dayOfMonth = 1): RecurringInvoice
    {
        $contact = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);

        $agreement = RecurringInvoice::create([
            'contact_id' => $contact->id,
            'description' => 'Retainer',
            'day_of_month' => $dayOfMonth,
            'due_days' => 14,
            'starts_on' => '2026-01-01',
            'is_active' => true,
        ]);

        $agreement->lines()->create([
            'description' => 'Monthly retainer',
            'quantity' => 1,
            'unit_price' => $amount,
        ]);

        return $agreement->fresh('lines');
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('CashCommitments', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for CashCommitments');

        return $payload;
    }

    private function cells(array $payload): string
    {
        return collect($payload['rows'])->flatten()->implode(' | ');
    }

    // ────────────────────────────────────────────────────── looking forward ──

    /**
     * Occurrences that have not come round yet are on the report.
     *
     * `outstandingFor()` would have given only what is due *now*, which for a forward-looking report is the
     * one population that does not matter — everything already overdue is a thing that has happened.
     */
    public function test_it_lists_occurrences_that_are_not_yet_due(): void
    {
        $this->schedule();

        $payload = $this->report();
        $dates = array_column($payload['rows'], 0);

        // Ninety days from 20 August reaches 18 November, so September, October and November's 5th.
        $this->assertContains('2026-09-05', $dates);
        $this->assertContains('2026-10-05', $dates);
        $this->assertContains('2026-11-05', $dates);
    }

    /** And nothing beyond the horizon: a commitment in five months is not this report's business. */
    public function test_it_stops_at_the_ninety_day_horizon(): void
    {
        $this->schedule();

        $dates = array_column($this->report()['rows'], 0);

        $this->assertNotContains('2026-12-05', $dates);
        // Nor anything before the date it is read on.
        $this->assertNotContains('2026-08-05', $dates);
    }

    /**
     * The posting run's cap does not shorten the report.
     *
     * `MAX_PER_RUN` is 24 and exists so that a run cannot raise two hundred back-dated entries nobody can
     * review. A report that inherited it would be quietly short, which is the failure this asserts against:
     * a weekly-ish schedule over ninety days is more occurrences than a run would take at once.
     */
    public function test_the_posting_runs_cap_does_not_apply(): void
    {
        // Monthly from 2020: five and a half years of occurrences, far past the run cap of 24.
        $this->schedule(['starts_on' => '2020-01-05']);

        $rows = app(ScheduledTransactionService::class)->occurrencesBetween('2020-01-01', self::AS_OF);

        $this->assertGreaterThan(
            ScheduledTransactionService::MAX_PER_RUN,
            count($rows),
            'the report must not inherit the posting run\'s cap',
        );
    }

    // ───────────────────────────────────────────────────── raised or not ──

    /** An occurrence already posted is marked as raised rather than dropped. */
    public function test_it_says_which_commitments_are_already_raised(): void
    {
        $schedule = $this->schedule();

        // Raise everything up to 5 August, which covers that occurrence and leaves the later ones open.
        app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2026-08-05'));

        $payload = $this->report('2026-08-01');
        $raisedColumn = array_column($payload['rows'], 5);

        // August's 5th is inside the window read from 1 August and has been raised.
        $august = collect($payload['rows'])->firstWhere(0, '2026-08-05');
        $this->assertNotNull($august, 'August\'s occurrence should be listed');
        $this->assertSame('Yes', $august[5]);

        // And a later one has not.
        $this->assertContains('Not yet', $raisedColumn);
    }

    /** The note counts what is still open, which is the number somebody acts on. */
    public function test_the_note_counts_what_is_not_yet_raised(): void
    {
        $this->schedule();

        $payload = $this->report();
        $unraised = count(array_filter(array_column($payload['rows'], 5), fn (string $v): bool => $v === 'Not yet'));

        $this->assertStringContainsString($unraised.' NOT YET RAISED', $payload['note']);
    }

    // ─────────────────────────────────────────────── the three sources ──

    /** All three kinds reach one timeline, sorted together rather than concatenated. */
    public function test_all_three_sources_appear_on_one_timeline(): void
    {
        $this->schedule();
        $this->subscription();
        $this->recurringInvoice();

        $payload = $this->report();
        $kinds = array_unique(array_column($payload['rows'], 2));

        $this->assertContains('Scheduled entry', $kinds);
        $this->assertContains('Subscription', $kinds);
        $this->assertContains('Recurring invoice', $kinds);

        // One timeline: the dates are ascending across the whole table, not per source.
        $dates = array_column($payload['rows'], 0);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'three sources concatenated is three reports on one page');
    }

    /**
     * Money in and money out are kept apart, and both are stated.
     *
     * The question is what the bank balance does, and one direction answers half of it. Recurring invoices
     * are money arriving; the other two are money leaving.
     */
    public function test_it_separates_money_leaving_from_money_arriving(): void
    {
        $this->schedule(amount: 25_000);
        $this->recurringInvoice(amount: 40_000);

        $payload = $this->report();

        $this->assertGreaterThan(0.0, $payload['tiles'][0]['value'], 'leaving');
        $this->assertGreaterThan(0.0, $payload['tiles'][1]['value'], 'arriving');
        $this->assertContains('Out', array_column($payload['rows'], 3));
        $this->assertContains('In', array_column($payload['rows'], 3));

        // The record row states the net, because a column mixing directions has no meaningful sum.
        $net = $payload['tiles'][1]['value'] - $payload['tiles'][0]['value'];
        $this->assertSame(number_format($net, 0), $payload['footer'][4]);
    }

    /**
     * Directions are words, not signs: a column of mixed signs gets added up wrongly by hand.
     *
     * Asserted on the **amount column alone**. The first version of this checked the whole table for a
     * hyphen and failed on the dates, which is the shape of assertion that passes for the wrong reason as
     * often as it fails for one.
     */
    public function test_directions_are_stated_in_words_rather_than_as_negative_amounts(): void
    {
        $this->schedule();
        $this->recurringInvoice();

        $payload = $this->report();

        foreach (array_column($payload['rows'], 4) as $amount) {
            $this->assertStringNotContainsString('-', $amount, 'amounts carry no sign; the direction is its own column');
        }

        $this->assertSame(['In', 'Out'], collect($payload['rows'])->pluck(3)->unique()->sort()->values()->all());
    }

    /**
     * A source that is not registered contributes nothing, and nothing breaks.
     *
     * Which is the whole reason the report asks a registry rather than importing three services: a company
     * without invoicing has no recurring invoices and should get a shorter list, not an error — and
     * Accounting should not have to name Invoicing to find that out.
     */
    public function test_an_unregistered_source_simply_contributes_nothing(): void
    {
        $this->schedule();
        $this->recurringInvoice();

        $this->assertContains('Recurring invoice', array_column($this->report()['rows'], 2));

        // As though Invoicing were not installed.
        CashCommitments::flush();
        \App\Modules\Accounting\Support\CashCommitmentReports::registerSources();

        $payload = $this->report();

        $this->assertNotContains('Recurring invoice', array_column($payload['rows'], 2));
        $this->assertContains('Scheduled entry', array_column($payload['rows'], 2));
        $this->assertSame(0.0, $payload['tiles'][1]['value'], 'nothing arriving without invoicing');
    }

    /** An inactive schedule is not a commitment. */
    public function test_an_inactive_schedule_is_not_committed(): void
    {
        $this->schedule(['is_active' => false]);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertStringContainsString('NOTHING IS COMMITTED', $payload['note']);
    }

    /** A schedule that ends inside the window stops there. */
    public function test_a_schedule_that_ends_inside_the_window_stops(): void
    {
        $this->schedule(['ends_on' => '2026-09-30']);

        $dates = array_column($this->report()['rows'], 0);

        $this->assertContains('2026-09-05', $dates);
        $this->assertNotContains('2026-10-05', $dates);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->schedule();

        $onThePage = Livewire::test(CashCommitmentsPage::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('CashCommitments', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(CashCommitmentsPage::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));

        $this->assertFalse(CashCommitmentsPage::canAccess());
    }
}
