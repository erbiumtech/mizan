<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Accounting\Services\SecondApproverRule;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Services\InvoiceService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The deferral generator from an invoice line — `docs/erpnext-gap-plan.md` §4 item 3's remaining half.
 *
 * Phase 5 built `DeferralService` and left this until service dates existed on the line. They do now, and an
 * issued invoice can be spread over them in one act — with the figures taken from the lines rather than typed.
 */
class InvoiceDeferralTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private InvoiceService $service;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-13 10:00:00');

        $this->actingAs($this->makeUser('Administrator', 'deferral@test.local'));
        $this->setCurrentTenant();
        app(SecondApproverRule::class)->set(false);

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create(['name' => 'Acme', 'kind' => 'customer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        $sums = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true))
            ->selectRaw('COALESCE(SUM(debit_amount),0) as d, COALESCE(SUM(credit_amount),0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function issued(array $lines): Invoice
    {
        $total = round(array_sum(array_column($lines, 'line_total')), 2);

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->getKey(),
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
        ]);

        foreach ($lines as $line) {
            $invoice->lines()->create($line + ['quantity' => 1, 'unit_price' => $line['line_total']]);
        }

        return $this->service->issue($invoice);
    }

    public function test_a_dated_line_is_spread_over_its_months_and_an_undated_one_is_left_alone(): void
    {
        $invoice = $this->issued([
            ['description' => 'Annual licence', 'line_total' => 12_000, 'service_from' => '2026-09-01', 'service_to' => '2027-08-31'],
            ['description' => 'Setup', 'line_total' => 3_000],
        ]);

        // Issued: all 15,000 is in income (credit-normal, so the balance reads negative).
        $this->assertSame(-15_000.0, $this->balance('4300'));

        $result = $this->service->deferOverServicePeriod($invoice);

        $this->assertSame(['deferred' => 12_000.0, 'schedules' => 1], $result);

        // The licence has moved out of income into deferred revenue; the setup fee stays where it was.
        $this->assertSame(-3_000.0, $this->balance('4300'));
        $this->assertSame(-12_000.0, $this->balance('2500'));

        // One schedule, twelve month-ends, a thousand each.
        $schedule = ScheduledTransaction::query()->where('name', 'like', 'Recognise: '.$invoice->invoice_number.'%')->firstOrFail();
        $this->assertSame('2026-09-01', $schedule->starts_on->toDateString());
        $this->assertSame('2027-08-31', $schedule->ends_on->toDateString());
        $this->assertSame(1_000.0, (float) $schedule->lines()->sum('debit_amount'));

        $event = $invoice->events()->where('event', InvoiceEvent::DEFERRED)->firstOrFail();
        $this->assertSame(12_000.0, (float) $event->amount);
    }

    public function test_it_happens_once(): void
    {
        $invoice = $this->issued([
            ['description' => 'Support', 'line_total' => 6_000, 'service_from' => '2026-09-01', 'service_to' => '2027-02-28'],
        ]);

        $this->service->deferOverServicePeriod($invoice);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been deferred');

        $this->service->deferOverServicePeriod($invoice);
    }

    public function test_an_invoice_with_no_dated_line_says_what_to_add(): void
    {
        $invoice = $this->issued([['description' => 'Hardware', 'line_total' => 5_000]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No line on this invoice carries a service period');

        $this->service->deferOverServicePeriod($invoice);
    }

    public function test_months_are_calendar_months_touched(): void
    {
        $invoice = $this->issued([
            ['description' => 'Mid-month start', 'line_total' => 2_000, 'service_from' => '2026-09-15', 'service_to' => '2026-10-14'],
        ]);

        // 15 Sep to 14 Oct touches two months, so two postings of 1,000 — not one of 2,000 on an arbitrary day.
        $this->assertSame(2, $invoice->lines()->first()->serviceMonths());

        $this->service->deferOverServicePeriod($invoice);

        $schedule = ScheduledTransaction::query()->where('name', 'like', 'Recognise: %')->firstOrFail();
        $this->assertSame('2026-10-31', $schedule->ends_on->toDateString());
    }
}
