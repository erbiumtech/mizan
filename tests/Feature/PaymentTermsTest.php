<?php

namespace Tests\Feature;

use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Net-30 on a contact, driving the invoice due date and therefore the ageing
 * buckets that were already built.
 *
 * The distinction the whole feature turns on is between **no terms agreed** and
 * **due on receipt**. Collapsing them — treating null as zero — silently puts
 * every contact nobody has thought about into the overdue bucket the day their
 * invoice is raised.
 */
class PaymentTermsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'terms@test.local'));
        $this->setCurrentTenant();
    }

    private function contact(?int $terms, string $name = 'Acme'): Contact
    {
        return Contact::create([
            'name' => $name,
            'kind' => Contact::KIND_CUSTOMER,
            'payment_terms_days' => $terms,
            'is_active' => true,
        ]);
    }

    public function test_terms_give_a_due_date(): void
    {
        $this->assertSame(
            '2026-08-09',
            $this->contact(30)->dueDateFor('2026-07-10')->toDateString(),
        );
    }

    public function test_due_on_receipt_is_the_invoice_date_itself(): void
    {
        $this->assertSame(
            '2026-07-10',
            $this->contact(0)->dueDateFor('2026-07-10')->toDateString(),
        );
    }

    public function test_no_terms_agreed_gives_no_due_date_at_all(): void
    {
        // Not zero. A contact nobody has set terms for must not be reported as
        // demanding payment the same day.
        $this->assertNull($this->contact(null)->dueDateFor('2026-07-10'));
    }

    public function test_the_two_read_differently_on_screen(): void
    {
        $this->assertSame('None agreed', $this->contact(null)->paymentTermsLabel());
        $this->assertSame('Due on receipt', $this->contact(0, 'B')->paymentTermsLabel());
        $this->assertSame('Net 30 days', $this->contact(30, 'C')->paymentTermsLabel());
        $this->assertSame('Net 21 days', $this->contact(21, 'D')->paymentTermsLabel());
    }

    public function test_picking_a_contact_fills_the_due_date(): void
    {
        $contact = $this->contact(30);

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'kind' => Invoice::KIND_SALE,
                'invoice_date' => '2026-07-10',
            ])
            ->set('data.contact_id', $contact->getKey())
            ->assertFormSet(['due_date' => '2026-08-09']);
    }

    public function test_a_due_date_somebody_typed_is_not_overwritten(): void
    {
        // The one field on this form that may have been negotiated. Correcting a
        // typo in the invoice date must not silently move an agreed date.
        $contact = $this->contact(30);

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'kind' => Invoice::KIND_SALE,
                'invoice_date' => '2026-07-10',
                'due_date' => '2026-12-25',
            ])
            ->set('data.contact_id', $contact->getKey())
            ->set('data.invoice_date', '2026-07-11')
            ->assertFormSet(['due_date' => '2026-12-25']);
    }

    public function test_a_contact_with_no_terms_leaves_the_due_date_alone(): void
    {
        $contact = $this->contact(null);

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'kind' => Invoice::KIND_SALE,
                'invoice_date' => '2026-07-10',
            ])
            ->set('data.contact_id', $contact->getKey())
            ->assertFormSet(['due_date' => null]);
    }

    public function test_terms_reach_the_ageing_buckets(): void
    {
        // The point of the feature. Two invoices raised the same day, one on
        // net-60 and one due on receipt, are not equally overdue 45 days later.
        $onTime = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->contact(60, 'Patient')->getKey(),
            'invoice_date' => '2026-07-01',
            'due_date' => $this->contact(60, 'x')->dueDateFor('2026-07-01'),
            'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000,
            'status' => Invoice::STATUS_ISSUED,
        ]);

        $late = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->contact(0, 'Prompt')->getKey(),
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-01',
            'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000,
            'status' => Invoice::STATUS_ISSUED,
        ]);

        $aged = app(\App\Modules\Invoicing\Services\InvoiceService::class)
            ->outstandingReceivables('2026-08-15');

        $rows = collect($aged['invoices'])->keyBy('invoice_number');

        $this->assertSame(0, $rows[$onTime->invoice_number]['days_overdue'], 'Net 60 is not overdue after 45 days.');
        $this->assertSame(45, $rows[$late->invoice_number]['days_overdue']);
    }

    public function test_the_contact_form_offers_none_agreed_as_a_choice(): void
    {
        // Not just an empty select: "none agreed" has to be pickable, because it
        // is a real answer and the one somebody may need to go back to.
        $this->assertArrayNotHasKey(null, Contact::TERMS);
        $this->assertSame('Due on receipt', Contact::TERMS[0]);
    }
}
