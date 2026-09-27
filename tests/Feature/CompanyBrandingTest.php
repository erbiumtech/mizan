<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\CompanyLetterhead;
use App\Support\Pdf\Pdf;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Storage;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The uploaded branding reaches the documents — and its absence changes nothing.
 *
 * Company Settings → Letterhead stores a logo and a signature as paths on the tenant-scoped `public`
 * disk; `CompanyLetterhead::logoDataUri()`/`signatureDataUri()` turn them into data URIs so both PDF
 * engines embed them (Dompdf fetches no URLs — the same reason `Invoice::fbrQrDataUri()` is a data
 * URI). The other half of the contract is pinned here too: no upload, or a path whose file is gone,
 * prints the header exactly as it printed before — never an exception, never a broken image tag.
 */
class CompanyBrandingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /**
     * Starts with the PNG signature, so finfo calls it image/png — which is all the seam
     * reads from it. Trailing bytes only make the two fixtures' base64 distinguishable.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Payslip $payslip;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'branding@test.local'));
        $this->setCurrentTenant();

        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'paid@test.local')->id,
            'employee_id' => 'EMP-BR',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);

        $this->payslip = Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);

        $contact = Contact::create(['name' => 'Branding Customer', 'kind' => 'customer']);

        $this->invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $contact->id,
            'invoice_date' => '2026-08-10',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'amount_paid' => 0,
        ]);
    }

    private function payslipHtml(): string
    {
        return app(PayslipService::class)->renderPdf($this->payslip)->html();
    }

    private function invoiceHtml(): string
    {
        return Pdf::view('pdfs.invoice', [
            'invoice' => $this->invoice->load('contact', 'lines.product', 'creditedInvoice'),
        ])->html();
    }

    /** Stores an image on the (faked) tenant public disk and points the setting at it. */
    private function upload(string $key, string $path, string $bytes): string
    {
        Storage::disk('public')->put($path, $bytes);
        app(TenantSettings::class)->set($key, $path);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function test_the_uploaded_logo_and_signature_reach_the_payslip_and_the_invoice(): void
    {
        Storage::fake('public');

        $logoUri = $this->upload('company.logo_path', 'branding/logo.png', base64_decode(self::PNG));
        $sigUri = $this->upload('company.signature_path', 'branding/signature.png', base64_decode(self::PNG).'sig');

        $payslip = $this->payslipHtml();
        $this->assertStringContainsString($logoUri, $payslip);
        $this->assertStringContainsString($sigUri, $payslip);

        $this->assertStringContainsString($logoUri, $this->invoiceHtml());
    }

    /** No upload prints the header exactly as it always has — the bars, the name, no embedded image. */
    public function test_without_uploads_the_documents_read_exactly_as_before(): void
    {
        $payslip = $this->payslipHtml();

        $this->assertStringNotContainsString('data:image', $payslip);
        $this->assertStringContainsString('<div class="bars">', $payslip);
        $this->assertStringContainsString($this->tenant->name, $payslip);

        $this->assertStringNotContainsString('data:image', $this->invoiceHtml());
    }

    /** A stored path whose file has been deleted degrades to the text fallback, never an exception. */
    public function test_a_missing_file_degrades_to_the_fallback(): void
    {
        Storage::fake('public');
        app(TenantSettings::class)->set('company.logo_path', 'branding/gone.png');

        $this->assertNull(CompanyLetterhead::logoDataUri());
        $this->assertNull(CompanyLetterhead::logoUrl());

        $payslip = $this->payslipHtml();
        $this->assertStringNotContainsString('data:image', $payslip);
        $this->assertStringContainsString('<div class="bars">', $payslip);
    }
}
