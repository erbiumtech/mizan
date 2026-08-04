<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Services\RecurringInvoiceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An invoice raised every month without anybody typing it.
 *
 * The same shape as BeneficiarySubscription, which already does this for money going
 * out: a standing agreement, and a link from each document back to the agreement and
 * the month it covers with a unique key on the pair. The scheduler is a cron job, so
 * the case that matters is the second run.
 */
class RecurringInvoiceTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'recurring@test.local'));
        $this->setCurrentTenant();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_CUSTOMER,
            'is_active' => true,
        ]);
    }

    private function agreement(array $attributes = [], array $lines = [[10000, null]]): RecurringInvoice
    {
        $agreement = RecurringInvoice::create(array_merge([
            'contact_id' => $this->client->id,
            'description' => 'Monthly retainer',
            'day_of_month' => 1,
            'due_days' => 15,
            'starts_on' => '2026-07-01',
        ], $attributes));

        foreach ($lines as [$amount, $rateId]) {
            $agreement->lines()->create([
                'description' => 'Services',
                'quantity' => 1,
                'unit_price' => $amount,
                'account_id' => Account::where('code', '4100')->firstOrFail()->id,
                'tax_rate_id' => $rateId,
            ]);
        }

        return $agreement->refresh();
    }

    private function raise(string $period = '2026-08-15')
    {
        return app(RecurringInvoiceService::class)->generateFor(Carbon::parse($period));
    }

    public function test_a_running_agreement_raises_a_draft(): void
    {
        $agreement = $this->agreement();

        $raised = $this->raise();

        $this->assertCount(1, $raised);

        $invoice = $raised->first();
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame('10000.00', $invoice->total);
        $this->assertSame($agreement->id, $invoice->recurring_invoice_id);
        $this->assertSame('2026-08-01', $invoice->period->toDateString());
    }

    /** The whole point of a cron job that may run more than once. */
    public function test_running_it_twice_does_not_invoice_the_client_twice(): void
    {
        $this->agreement();

        $this->raise();
        $second = $this->raise();

        $this->assertCount(0, $second);
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_database_refuses_a_duplicate_even_so(): void
    {
        // Two workers, two transactions, neither seeing the other's row.
        $agreement = $this->agreement();
        $this->raise();

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-08-01',
            'recurring_invoice_id' => $agreement->id,
            'period' => '2026-08-01',
            'subtotal' => 0, 'tax_amount' => 0, 'total' => 0,
        ]);
    }

    public function test_each_month_is_raised_separately(): void
    {
        $this->agreement();

        $this->raise('2026-08-15');
        $this->raise('2026-09-15');

        $this->assertSame(2, Invoice::count());
        $this->assertSame(
            ['2026-08-01', '2026-09-01'],
            Invoice::orderBy('period')->pluck('period')->map(fn ($d): string => $d->toDateString())->all(),
        );
    }

    public function test_the_lines_are_copied(): void
    {
        $this->agreement(lines: [[10000, null], [2500, null]]);

        $invoice = $this->raise()->first();

        $this->assertSame(2, $invoice->lines()->count());
        $this->assertSame('12500.00', $invoice->total);
    }

    public function test_tax_comes_from_the_lines_rates(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);

        $this->agreement(lines: [[10000, $rate->id]]);

        $invoice = $this->raise()->first();

        $this->assertSame('10000.00', $invoice->subtotal);
        $this->assertSame('1800.00', $invoice->tax_amount);
        $this->assertSame('11800.00', $invoice->total);
    }

    public function test_an_inclusive_agreement_takes_the_tax_out_of_the_price(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);

        $this->agreement(['tax_inclusive' => true], lines: [[11800, $rate->id]]);

        $invoice = $this->raise()->first();

        $this->assertSame('10000.00', $invoice->subtotal);
        $this->assertSame('1800.00', $invoice->tax_amount);
        $this->assertSame('11800.00', $invoice->total);
    }

    public function test_the_due_date_follows_the_terms(): void
    {
        $this->agreement(['day_of_month' => 5, 'due_days' => 30]);

        $invoice = $this->raise()->first();

        $this->assertSame('2026-08-05', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-09-04', $invoice->due_date->toDateString());
    }

    public function test_a_day_after_the_end_of_a_short_month(): void
    {
        // The 31st of February is the 28th.
        $this->agreement(['day_of_month' => 31]);

        $invoice = $this->raise('2027-02-10')->first();

        $this->assertSame('2027-02-28', $invoice->invoice_date->toDateString());
    }

    public function test_an_agreement_that_has_not_started_raises_nothing(): void
    {
        $this->agreement(['starts_on' => '2026-10-01']);

        $this->assertCount(0, $this->raise());
    }

    public function test_a_finished_agreement_raises_nothing(): void
    {
        $this->agreement(['ends_on' => '2026-07-31']);

        $this->assertCount(0, $this->raise());
    }

    public function test_its_last_month_is_billed_in_full(): void
    {
        $this->agreement(['ends_on' => '2026-08-14']);

        $this->assertCount(1, $this->raise());
    }

    public function test_switching_one_off_stops_it(): void
    {
        $this->agreement(['is_active' => false]);

        $this->assertCount(0, $this->raise());
    }

    public function test_an_agreement_with_no_lines_raises_nothing(): void
    {
        // An invoice with no lines cannot be issued, so raising one would only leave
        // somebody a draft to delete.
        $this->agreement(lines: []);

        $this->assertCount(0, $this->raise());
        $this->assertSame(0, Invoice::count());
    }

    /** It is an ordinary invoice: everything downstream is unchanged. */
    public function test_what_it_raises_can_be_issued_and_posted(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);
        $this->agreement(lines: [[10000, $rate->id]]);

        $invoice = app(InvoiceService::class)->issue($this->raise()->first());

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertTrue(
            app(FinancialReportService::class)
                ->trialBalance('2026-08-31')['balanced'],
        );
    }

    public function test_it_records_being_raised_in_the_invoices_history(): void
    {
        $this->agreement();

        $invoice = $this->raise()->first();

        $this->assertStringContainsString('draft', $invoice->events()->first()->description);
    }

    /**
     * Asserted against the service above rather than by running the command: it is
     * wrapped in Spatie's TenantAware, which iterates real per-tenant database
     * connections and cannot run in this single-database suite — the same reason
     * PayrollAccountCheckTest tests its audit rather than its command.
     */
    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('invoicing:raise-recurring', Artisan::all());
    }

    public function test_it_runs_on_the_first_of_each_month(): void
    {
        $expression = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'invoicing:raise-recurring'))
            ?->expression;

        $this->assertSame('0 3 1 * *', $expression);
    }
}
