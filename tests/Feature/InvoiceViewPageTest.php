<?php

namespace Tests\Feature;

use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The invoice, read — the Material "Record detail (Invoice)" design.
 *
 * What the page promises: the number with a chip saying where the invoice stands (overdue by how much, paid,
 * a draft), its lines and what is left to pay, the actions that fit the invoice's state, a list that opens it,
 * a new invoice that lands on it, and an edit form that still saves after being grouped into sections.
 */
class InvoiceViewPageTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'invoice-view@test.local'));
        $this->setCurrentTenant();

        $this->customer = Contact::create([
            'name' => 'Indus Builders',
            'kind' => Contact::KIND_CUSTOMER,
            'email' => 'accounts@indusbuilders.test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Two lines on purpose: a list of one renders whether or not its parent is loaded row by row. */
    private function draftWithTwoLines(): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->getKey(),
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-10-01',
        ]);

        $invoice->lines()->create(['description' => 'Site supervision', 'quantity' => 1, 'unit_price' => 100_000, 'line_total' => 100_000]);
        $invoice->lines()->create(['description' => 'Concrete testing', 'quantity' => 2, 'unit_price' => 25_000, 'line_total' => 50_000]);

        $invoice->forceFill(['subtotal' => 150_000, 'tax_amount' => 0, 'total' => 150_000])->save();

        return $invoice->refresh();
    }

    private function issuedWithTwoLines(): Invoice
    {
        $invoice = $this->draftWithTwoLines();
        app(InvoiceService::class)->issue($invoice);

        return $invoice->refresh();
    }

    public function test_the_page_shows_the_number_an_overdue_chip_the_lines_and_the_balance(): void
    {
        $invoice = $this->issuedWithTwoLines();
        Carbon::setTestNow('2026-10-07 10:00:00');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful()
            ->assertSee($invoice->invoice_number)
            ->assertSee('Overdue 6 days')
            ->assertSee('Site supervision')
            ->assertSee('Concrete testing')
            ->assertSee('Indus Builders')
            ->assertSee('Balance due')
            ->assertSee('150,000.00');
    }

    public function test_the_chip_follows_the_invoice(): void
    {
        $draft = $this->draftWithTwoLines();
        $this->assertSame(['label' => 'Draft', 'tone' => 'neutral'], InvoiceInfolist::statusChip($draft));

        $issued = $this->issuedWithTwoLines();
        Carbon::setTestNow('2026-09-20');
        $this->assertSame('tonal', InvoiceInfolist::statusChip($issued)['tone'], 'Issued and not yet due is open.');

        Carbon::setTestNow('2026-10-02');
        $this->assertSame(['label' => 'Overdue 1 day', 'tone' => 'error'], InvoiceInfolist::statusChip($issued));

        $issued->forceFill(['status' => Invoice::STATUS_PAID, 'amount_paid' => 150_000])->save();
        $this->assertSame(['label' => 'Paid', 'tone' => 'success'], InvoiceInfolist::statusChip($issued->refresh()));
    }

    public function test_the_header_offers_what_fits_the_invoice(): void
    {
        $draft = $this->draftWithTwoLines();

        Livewire::test(ViewInvoice::class, ['record' => $draft->getKey()])
            ->assertActionVisible('pdf')
            ->assertActionVisible('issue')
            ->assertActionHidden('recordPayment')
            ->assertActionHidden('email');

        $issued = $this->issuedWithTwoLines();

        Livewire::test(ViewInvoice::class, ['record' => $issued->getKey()])
            ->assertActionVisible('pdf')
            ->assertActionVisible('email')
            ->assertActionVisible('recordPayment')
            ->assertActionHidden('issue');
    }

    public function test_the_list_opens_the_invoice_and_a_new_one_lands_on_it(): void
    {
        $invoice = $this->draftWithTwoLines();

        Livewire::test(ListInvoices::class)
            ->loadTable()
            ->assertSee(InvoiceResource::getUrl('view', ['record' => $invoice]), false);

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'kind' => Invoice::KIND_SALE,
                'contact_id' => $this->customer->getKey(),
                'invoice_date' => '2026-10-01',
                'subtotal' => 1_000,
                'tax_amount' => 0,
                'total' => 1_000,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => Invoice::query()->latest('id')->firstOrFail()]));
    }

    public function test_the_edit_form_still_saves_in_its_sections(): void
    {
        $draft = $this->draftWithTwoLines();

        Livewire::test(EditInvoice::class, ['record' => $draft->getKey()])
            ->assertSee('Customer and dates')
            ->fillForm(['memo' => 'Retention released with this one.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Retention released with this one.', $draft->refresh()->memo);
    }
}
