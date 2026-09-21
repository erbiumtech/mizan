<?php

namespace Tests\Feature;

use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\TenantSettings;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Credit control — `docs/erpnext-gap-plan.md` §4 item 5.
 *
 * Off for every existing customer by construction: no limit on the contact and the overdue setting at zero
 * means `issue()` behaves exactly as before. Each test below turns one rule on and shows the refusal, and
 * the last shows the permission that walks past both.
 */
class CreditLimitTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private InvoiceService $service;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Accountant: may issue, may not override — which is the role the guard exists for.
        $this->actingAs($this->makeUser('Accountant', 'billing@test.local'));
        $this->setCurrentTenant();

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create(['name' => 'Tight Customer', 'kind' => 'customer']);
    }

    private function draft(float $amount, string $date = '2026-08-10', ?string $due = '2026-09-09'): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->getKey(),
            'invoice_date' => $date,
            'due_date' => $due,
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create(['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount, 'line_total' => $amount]);

        return $invoice;
    }

    public function test_with_no_limit_and_no_overdue_rule_nothing_changes(): void
    {
        $this->service->issue($this->draft(1_000_000));

        $this->assertSame(Invoice::STATUS_ISSUED, Invoice::first()->status);
    }

    public function test_an_invoice_past_the_limit_is_refused_and_says_by_how_much(): void
    {
        $this->customer->update(['credit_limit' => 150_000]);
        $this->service->issue($this->draft(100_000));

        try {
            $this->service->issue($this->draft(60_000));
            $this->fail('Expected the credit limit to refuse the second invoice.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already owes 100,000.00', $e->getMessage());
            $this->assertStringContainsString('160,000.00', $e->getMessage());
            $this->assertStringContainsString('credit limit of 150,000.00', $e->getMessage());
        }

        $this->assertSame(Invoice::STATUS_DRAFT, Invoice::orderByDesc('id')->first()->status, 'the refused invoice stays a draft');
    }

    public function test_a_receipt_frees_the_limit_again(): void
    {
        $this->customer->update(['credit_limit' => 150_000]);
        $first = $this->service->issue($this->draft(100_000));
        $this->service->recordPayment($first, 60_000, '2026-08-15');

        // Owes 40,000 now; 60,000 more is 100,000, inside the limit.
        $second = $this->service->issue($this->draft(60_000));

        $this->assertSame(Invoice::STATUS_ISSUED, $second->fresh()->status);
    }

    public function test_a_stale_overdue_invoice_holds_new_billing(): void
    {
        app(TenantSettings::class)->set('invoicing.credit_block_overdue_days', 30);

        // Due 9 September, and the new invoice is dated 20 October: 41 days late.
        $this->service->issue($this->draft(10_000));

        try {
            $this->service->issue($this->draft(5_000, '2026-10-20', '2026-11-19'));
            $this->fail('Expected the overdue rule to refuse.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('more than 30 days overdue', $e->getMessage());
            $this->assertStringContainsString('10,000.00 outstanding', $e->getMessage());
        }

        // Ten days late is not thirty: the same customer can still be billed inside the window.
        $this->service->issue($this->draft(5_000, '2026-09-19', '2026-10-19'));
    }

    public function test_the_override_permission_walks_past_both_rules(): void
    {
        $this->customer->update(['credit_limit' => 1_000]);
        app(TenantSettings::class)->set('invoicing.credit_block_overdue_days', 1);
        $this->service->issue($this->draft(900));

        // Manager holds InvoiceOverrideCreditLimit through the module's role grants.
        $this->actingAs($this->makeUser('Manager', 'credit@test.local'));

        $this->service->issue($this->draft(50_000, '2026-12-01', '2026-12-31'));

        $this->assertSame(2, Invoice::where('status', Invoice::STATUS_ISSUED)->count());
    }
}
