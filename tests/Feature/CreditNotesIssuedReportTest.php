<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Filament\Pages\CreditNotesIssued;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Credit notes issued — `docs/reports-expansion-plan.md` Phase 3.5.
 *
 * This is a compliance report, so the tests are about the four states a credit note can be in and about
 * refusing to guess in the company's favour on any of them:
 *
 *  - inside the window, which is nothing to do;
 *  - outside it with an approval reference, which is a reference somebody must be able to produce;
 *  - outside it with nothing, which is the exposure the report exists to surface;
 *  - and naming no invoice, where the window cannot be computed — **not** treated as compliant, because
 *    calling it so would be a guess on a tax question.
 *
 * The window is read from the company's own setting, and there is a test for that too: judging a company by
 * the default when it has chosen otherwise would report an exposure that is not one.
 */
class CreditNotesIssuedReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** February of the 2026-2027 fiscal year, so the calendar year differs from the financial one. */
    private const AS_OF = '2027-02-20';

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['invoicing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );

        $this->customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function invoice(string $date, float $total = 100_000): Invoice
    {
        return Invoice::create([
            'contact_id' => $this->customer->id,
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_ISSUED,
            'invoice_date' => $date,
            'total' => $total,
            'subtotal' => $total,
        ]);
    }

    private function creditNote(string $date, float $total, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'contact_id' => $this->customer->id,
            'kind' => Invoice::KIND_CREDIT_NOTE,
            'status' => Invoice::STATUS_ISSUED,
            'invoice_date' => $date,
            'total' => $total,
            'subtotal' => $total,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('CreditNotesIssued', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for CreditNotesIssued');

        return $payload;
    }

    private function standing(array $payload, int $row = 0): string
    {
        $index = array_search('Standing', $payload['columns'], true);
        $this->assertNotFalse($index);

        return $payload['rows'][$row][$index];
    }

    // ───────────────────────────────────────────────── inside the window ──

    /** A credit note raised promptly is nothing to do, and says so. */
    public function test_a_credit_note_inside_the_window_is_clear(): void
    {
        $invoice = $this->invoice('2026-08-01');
        $this->creditNote('2026-09-01', 20_000, ['credits_invoice_id' => $invoice->id]);

        $payload = $this->report();

        $this->assertSame('Within 180 days', $this->standing($payload));
        $this->assertSame(0.0, $payload['tiles'][1]['value'], 'no exposure');
        $this->assertStringContainsString('EVERY CREDIT NOTE IS INSIDE ITS WINDOW OR APPROVED', $payload['note']);
    }

    /** And the days after the invoice are stated, so the reader can check the judgement. */
    public function test_the_days_after_the_invoice_are_stated(): void
    {
        $invoice = $this->invoice('2026-08-01');
        $this->creditNote('2026-09-01', 20_000, ['credits_invoice_id' => $invoice->id]);

        $payload = $this->report();
        $index = array_search('Days after', $payload['columns'], true);

        $this->assertSame('31', $payload['rows'][0][$index]);
    }

    // ──────────────────────────────────────────────── outside the window ──

    /**
     * Outside the window with nothing recorded is the exposure, stated as money.
     *
     * The report's reason for existing. Nothing in the application refuses this credit note, so if the report
     * did not surface it nothing would.
     */
    public function test_a_credit_note_outside_the_window_with_no_approval_is_an_exposure(): void
    {
        // Invoiced in July, credited the following February: well past 180 days.
        $invoice = $this->invoice('2026-07-01');
        $this->creditNote('2027-02-01', 35_000, ['credits_invoice_id' => $invoice->id]);

        $payload = $this->report();

        $this->assertSame('No approval recorded', $this->standing($payload));
        $this->assertSame(35_000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString(
            '35,000 REVERSED OUTSIDE THE 180-DAY WINDOW WITH NO APPROVAL RECORDED',
            $payload['note'],
        );
    }

    /** With an approval recorded it is not an exposure, and the reference is on the row. */
    public function test_an_approved_credit_note_outside_the_window_is_not_an_exposure(): void
    {
        $invoice = $this->invoice('2026-07-01');
        $this->creditNote('2027-02-01', 35_000, [
            'credits_invoice_id' => $invoice->id,
            'commissioner_approval_ref' => 'CIR-2027-118',
            'commissioner_approved_on' => '2027-01-20',
        ]);

        $payload = $this->report();

        $this->assertSame('Approved · CIR-2027-118', $this->standing($payload));
        $this->assertSame(0.0, $payload['tiles'][1]['value']);
    }

    /**
     * The window comes from the company's setting, not from a number in this report.
     *
     * A company on a different regime has a different window, and judging it by the default would report an
     * exposure that is not one — which on a tax report is the worse of the two errors.
     */
    public function test_the_window_follows_the_companys_setting(): void
    {
        $invoice = $this->invoice('2026-08-01');
        // 120 days later: outside a 90-day window, inside the 180-day default.
        $this->creditNote('2026-11-29', 15_000, ['credits_invoice_id' => $invoice->id]);

        $this->assertSame('Within 180 days', $this->standing($this->report()));

        app(TenantSettings::class)->set('fbr.credit_note_days', 90);

        $payload = $this->report();
        $this->assertSame('No approval recorded', $this->standing($payload));
        $this->assertSame(15_000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('90-DAY WINDOW', $payload['note']);
    }

    // ──────────────────────────────────────────────── nothing to judge ──

    /**
     * A credit note naming no invoice is not called compliant.
     *
     * The window cannot be computed without the invoice, and "within the window" would be a guess in the
     * company's favour. It is named, counted, and left for somebody to look at.
     */
    public function test_a_credit_note_naming_no_invoice_is_not_called_compliant(): void
    {
        $this->creditNote('2026-09-01', 12_000, ['credits_invoice_id' => null]);

        $payload = $this->report();

        $this->assertSame('No invoice named', $this->standing($payload));
        $this->assertStringNotContainsString('WITHIN', $this->standing($payload));
        $this->assertSame(0.0, $payload['tiles'][1]['value'], 'unjudgeable is not the same as exposed');
        $this->assertStringContainsString(
            '1 NAMES NO INVOICE, SO THE WINDOW CANNOT BE JUDGED',
            $payload['note'],
        );
    }

    /** With an approval but no invoice, the approval is still shown — it is evidence either way. */
    public function test_an_approval_is_shown_even_with_no_invoice_named(): void
    {
        $this->creditNote('2026-09-01', 12_000, [
            'credits_invoice_id' => null,
            'commissioner_approval_ref' => 'CIR-2026-004',
        ]);

        $this->assertSame('Approved · no invoice', $this->standing($this->report()));
    }

    // ───────────────────────────────────────────── what counts at all ──

    /** A draft credit note has reversed nothing. */
    public function test_a_draft_credit_note_is_not_listed(): void
    {
        $invoice = $this->invoice('2026-08-01');
        $this->creditNote('2026-09-01', 20_000, [
            'credits_invoice_id' => $invoice->id,
            'status' => Invoice::STATUS_DRAFT,
        ]);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO CREDIT NOTE WAS ISSUED IN THIS PERIOD', $payload['note']);
    }

    /** Nor has a void one. */
    public function test_a_void_credit_note_is_not_listed(): void
    {
        $invoice = $this->invoice('2026-08-01');
        $this->creditNote('2026-09-01', 20_000, [
            'credits_invoice_id' => $invoice->id,
            'status' => Invoice::STATUS_VOID,
        ]);

        $this->assertSame([], $this->report()['rows']);
    }

    /** A sale is not a credit note, obviously — but the filter is one word and worth pinning. */
    public function test_a_sale_is_not_listed(): void
    {
        $this->invoice('2026-08-01');

        $this->assertSame([], $this->report()['rows']);
    }

    /**
     * The credited invoice may be outside the report's own period, and must still be found.
     *
     * A credit note raised late is exactly the case this report is for, so the invoice it credits is usually
     * *older* than the window being reported — and often older than the fiscal year.
     */
    public function test_the_credited_invoice_is_found_even_from_a_previous_year(): void
    {
        $invoice = $this->invoice('2026-03-01');
        $this->creditNote('2026-09-01', 20_000, ['credits_invoice_id' => $invoice->id]);

        $payload = $this->report();

        $this->assertSame('No approval recorded', $this->standing($payload), 'March to September is past 180 days');
        $this->assertSame(20_000.0, $payload['tiles'][1]['value']);
    }

    /** The record row totals what was credited. */
    public function test_the_record_row_totals_what_was_credited(): void
    {
        $invoice = $this->invoice('2026-08-01');
        $this->creditNote('2026-09-01', 20_000, ['credits_invoice_id' => $invoice->id]);
        $this->creditNote('2026-09-05', 5_000, ['credits_invoice_id' => $invoice->id]);

        $payload = $this->report();
        $index = array_search('Amount', $payload['columns'], true);

        $this->assertSame('25,000', $payload['footer'][$index]);
        $this->assertSame(25_000.0, $payload['tiles'][0]['value']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $invoice = $this->invoice('2026-08-01');
        $this->creditNote('2026-09-01', 20_000, ['credits_invoice_id' => $invoice->id]);

        $onThePage = Livewire::test(CreditNotesIssued::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('CreditNotesIssued', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(CreditNotesIssued::canAccess());
    }
}
