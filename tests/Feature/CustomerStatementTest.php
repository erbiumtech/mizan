<?php

namespace Tests\Feature;

use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\CustomerStatement;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Notifications\CustomerStatementIssued;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A customer's statement of account — `docs/erpnext-gap-plan.md` §4 item 1.
 *
 * Opening balance from everything before the period, every document and receipt inside it with a running
 * balance, the closing figure, and how late what is still owed is. Receipts are dated when the money arrived,
 * because that is the date the customer reconciles against.
 */
class CustomerStatementTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private InvoiceService $service;

    private CustomerStatement $statements;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');

        // Single-database suite: drop the DB-switch task, as LedgerDimensionsTest does. The statements
        // command is TenantAware, so it makes each company current.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
        ]]);

        $this->actingAs($this->makeUser('Administrator', 'statements@test.local'));
        $this->setCurrentTenant();

        Notification::fake();

        $this->service = app(InvoiceService::class);
        $this->statements = app(CustomerStatement::class);
        $this->customer = Contact::create([
            'name' => 'Acme Retail',
            'kind' => 'customer',
            'email' => 'accounts@acme.test',
            'payment_terms_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(Contact $contact, float $amount, string $date): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $contact->getKey(),
            'invoice_date' => $date,
            'due_date' => Carbon::parse($date)->addDays(30)->toDateString(),
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create(['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount, 'line_total' => $amount]);

        return $this->service->issue($invoice);
    }

    /** July: a 10,000 invoice part-paid 4,000. August: a 5,000 invoice, and the July one paid off. */
    private function history(): array
    {
        $july = $this->invoice($this->customer, 10_000, '2026-07-10');
        $this->service->recordPayment($july, 4_000, '2026-07-20');

        $august = $this->invoice($this->customer, 5_000, '2026-08-05');
        $this->service->recordPayment($july, 6_000, '2026-08-15', null, null, null, 0, null);

        return [$july, $august];
    }

    public function test_the_period_opens_on_what_came_before_and_closes_on_what_is_left(): void
    {
        [$july, $august] = $this->history();

        $statement = $this->statements->for($this->customer, '2026-08-01', '2026-08-31');

        $this->assertSame(6_000.0, $statement['opening'], '10,000 invoiced less 4,000 received, before August');

        $this->assertSame(
            [
                ['2026-08-05', 'Invoice', $august->invoice_number, 5_000.0, 0.0, 11_000.0],
                ['2026-08-15', 'Receipt', 6_000.0, 5_000.0],
            ],
            [
                [$statement['lines'][0]['date'], $statement['lines'][0]['type'], $statement['lines'][0]['reference'], $statement['lines'][0]['debit'], $statement['lines'][0]['credit'], $statement['lines'][0]['balance']],
                [$statement['lines'][1]['date'], $statement['lines'][1]['type'], $statement['lines'][1]['credit'], $statement['lines'][1]['balance']],
            ],
        );

        // The receipt is dated when the money arrived, and names the invoice it settled.
        $this->assertStringContainsString($july->invoice_number, $statement['lines'][1]['detail']);

        $this->assertSame(5_000.0, $statement['closing']);
        $this->assertSame(5_000.0, $statement['total_due']);
        // Due 4 September, so on 31 August it is not yet late.
        $this->assertSame(5_000.0, $statement['ageing']['current']);
    }

    public function test_the_ageing_is_as_at_the_statement_date(): void
    {
        $this->history();

        // On 30 September the August invoice (due 4 September) is 26 days late: still "current" by the
        // 30-day bucket. On 15 October it is 41 days late.
        $this->assertSame(5_000.0, $this->statements->for($this->customer, '2026-09-01', '2026-09-30')['ageing']['current']);
        $this->assertSame(5_000.0, $this->statements->for($this->customer, '2026-10-01', '2026-10-15')['ageing']['31-60']);
    }

    public function test_the_pdf_states_the_figures(): void
    {
        [, $august] = $this->history();

        $html = $this->statements->renderPdf($this->customer, '2026-08-01', '2026-08-31')->html();

        foreach (['Statement of Account', 'Acme Retail', 'Opening balance', '6,000.00', $august->invoice_number, 'Receipt', 'Closing balance', 'Amount due: 5,000.00'] as $expected) {
            $this->assertStringContainsString($expected, $html, "the statement must state: {$expected}");
        }
    }

    public function test_only_customers_with_a_movement_or_a_balance_are_due_one(): void
    {
        $this->history();

        // Paid up before the period and quiet during it: nothing to say, so nothing is sent.
        $quiet = Contact::create(['name' => 'Quiet Co', 'kind' => 'customer', 'email' => 'ap@quiet.test']);
        $settled = $this->invoice($quiet, 1_000, '2026-06-01');
        $this->service->recordPayment($settled, 1_000, '2026-06-10');

        $due = $this->statements->due('2026-08-01', '2026-08-31');

        $this->assertSame([$this->customer->getKey()], $due->keys()->all());
    }

    public function test_nothing_is_sent_while_statements_are_off(): void
    {
        $this->history();

        $this->artisan('invoicing:send-statements', ['--from' => '2026-08-01', '--to' => '2026-08-31', '--tenant' => [$this->tenant->getKey()]])
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_switched_on_each_customer_with_activity_is_emailed_theirs(): void
    {
        $this->history();
        app(TenantSettings::class)->set('invoicing.statements_enabled', true);

        $this->artisan('invoicing:send-statements', ['--from' => '2026-08-01', '--to' => '2026-08-31', '--tenant' => [$this->tenant->getKey()]])
            ->assertSuccessful();

        Notification::assertSentOnDemand(
            CustomerStatementIssued::class,
            fn (CustomerStatementIssued $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'accounts@acme.test'
                && $notification->from === '2026-08-01'
                && $notification->to === '2026-08-31',
        );
        Notification::assertSentOnDemandTimes(CustomerStatementIssued::class, 1);
    }

    public function test_a_dry_run_lists_and_sends_nothing(): void
    {
        $this->history();
        app(TenantSettings::class)->set('invoicing.statements_enabled', true);

        $this->artisan('invoicing:send-statements', ['--from' => '2026-08-01', '--to' => '2026-08-31', '--dry-run' => true, '--tenant' => [$this->tenant->getKey()]])
            ->expectsOutputToContain('Acme Retail')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }
}
