<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Currency;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\CompanyCurrency;
use App\Support\Pdf\Pdf;
use Closure;
use Database\Seeders\CurrencySeeder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Amounts print in this company's currency, not in a literal "PKR".
 *
 * The base currency is chosen on Company Settings and kept as a row in Accounting's currencies table;
 * `CompanyCurrency` is how every other module asks for it without importing Accounting. Two of the old
 * literals read a `currency_code` the company row never had, and an invoice PDF labelled a USD total "PKR".
 */
class CompanyCurrencyDisplayTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'currencydisplay@test.local'));
        $this->setCurrentTenant();

        $this->seed(CurrencySeeder::class);
    }

    public function test_it_is_the_company_base_currency(): void
    {
        $this->assertSame(Currency::base()->code, CompanyCurrency::code());

        Currency::where('code', 'EUR')->firstOrFail()->update(['is_base' => true]);

        $this->assertSame('EUR', CompanyCurrency::code());
    }

    /** Nothing registered — a module set without Accounting — falls back to the installation's home currency. */
    public function test_without_a_resolver_it_is_the_home_currency(): void
    {
        $resolver = Closure::bind(fn () => CompanyCurrency::$resolver, null, CompanyCurrency::class)();

        try {
            CompanyCurrency::resolveUsing(null);
            config(['saas.home_currency' => 'chf']);

            $this->assertSame('CHF', CompanyCurrency::code());
        } finally {
            CompanyCurrency::resolveUsing($resolver instanceof Closure ? $resolver : null);
        }
    }

    public function test_an_invoice_pdf_totals_in_the_invoice_currency(): void
    {
        $contact = Contact::create(['name' => 'Acme Exports', 'kind' => 'customer']);

        $usd = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $contact->id,
            'invoice_date' => '2026-10-01',
            'currency_code' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'amount_paid' => 0,
        ]);

        $html = Pdf::view('pdfs.invoice', ['invoice' => $usd->load('contact', 'lines.product', 'creditedInvoice')])->html();

        $this->assertStringContainsString('Total (USD)', $html);
        $this->assertStringNotContainsString('Total (PKR)', $html);
    }
}
