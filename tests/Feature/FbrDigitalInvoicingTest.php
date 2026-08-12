<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\FbrReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;

/**
 * FBR digital invoicing: the reporting axis, and what it takes away.
 *
 * Two halves, and the second is the one that matters. The first is bookkeeping —
 * the new columns default to "nothing to do" so no existing invoice changes
 * behaviour. The second is `void()`, which used to be an unconditional local
 * operation and is now refused for an invoice FBR is holding.
 *
 * That refusal is the whole point. A void producing a locally-voided invoice
 * that FBR still considers live leaves the books and the tax authority
 * permanently disagreeing, with nothing reporting the divergence — so every
 * branch of it is asserted, including the two that still allow the void.
 *
 * See docs/fbr-digital-invoicing-plan.md §2.
 */
class FbrDigitalInvoicingTest extends AccountingTestCase
{
    private InvoiceService $service;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create(['name' => 'FBR Test Customer', 'kind' => 'customer']);
    }

    private function issuedInvoice(array $attributes = []): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total' => 1000,
        ] + $attributes);

        $invoice->lines()->create([
            'description' => 'Consultancy',
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
        ]);

        $this->service->issue($invoice);

        return $invoice->refresh();
    }

    private function reportedAt(Invoice $invoice, Carbon $when, string $status = Invoice::FBR_ACCEPTED): Invoice
    {
        $invoice->update([
            'fbr_status' => $status,
            'fbr_irn' => 'IRN-TEST-'.$invoice->getKey(),
            'fbr_reported_at' => $when,
        ]);

        return $invoice->refresh();
    }

    // --------------------------------------------------------------- defaults

    public function test_a_new_invoice_is_not_required_to_be_reported(): void
    {
        // The compatibility guarantee. Every invoice that already exists, and
        // every invoice at a company that is not integrated, must read as
        // "nothing to do" — otherwise this feature changes behaviour for
        // everybody the day it migrates.
        $invoice = $this->issuedInvoice();

        $this->assertSame(Invoice::FBR_NOT_REQUIRED, $invoice->fbr_status);
        $this->assertNull($invoice->fbr_irn);
        $this->assertNull($invoice->fbr_reported_at);
        $this->assertFalse($invoice->isFbrLive());
        $this->assertNull($invoice->fbrCorrectionWindowClosesAt());
        $this->assertFalse($invoice->fbrCorrectionWindowOpen());
    }

    public function test_the_correction_window_runs_from_acceptance(): void
    {
        $invoice = $this->reportedAt($this->issuedInvoice(), Carbon::now()->subHours(2));

        $this->assertTrue($invoice->isFbrLive());
        $this->assertTrue($invoice->fbrCorrectionWindowOpen());

        // 72 hours from acceptance, per config/fbr.php.
        $this->assertSame(
            Carbon::now()->subHours(2)->addHours(72)->toDateTimeString(),
            $invoice->fbrCorrectionWindowClosesAt()->toDateTimeString(),
        );
    }

    public function test_the_correction_window_is_configurable(): void
    {
        // A notification changing 72 to something else must be a settings change,
        // not a deploy — the same argument statutory tax rates already win.
        app(TenantSettings::class)->set('fbr.correction_window_hours', 24);

        $invoice = $this->reportedAt($this->issuedInvoice(), Carbon::now()->subHours(30));

        $this->assertFalse(
            $invoice->fbrCorrectionWindowOpen(),
            'A 30-hour-old invoice is still correctable under a 24-hour window.',
        );
    }

    // ------------------------------------------------------------------ void

    public function test_an_unreported_invoice_still_voids_exactly_as_before(): void
    {
        // The behaviour that must not change: reporting is off for everybody
        // today, so voiding is still the purely local operation it always was.
        $invoice = $this->issuedInvoice();

        $this->service->void($invoice);

        $this->assertSame(Invoice::STATUS_VOID, $invoice->refresh()->status);
    }

    public function test_an_invoice_fbr_refused_still_voids(): void
    {
        // FBR said no, so there is nothing on their side to withdraw and the
        // local record is the only record.
        $invoice = $this->issuedInvoice();
        $invoice->update(['fbr_status' => Invoice::FBR_REJECTED]);

        $this->service->void($invoice->refresh());

        $this->assertSame(Invoice::STATUS_VOID, $invoice->refresh()->status);
    }

    public function test_an_invoice_already_cancelled_at_fbr_voids(): void
    {
        // The second half of a within-window cancellation: cancel there, then
        // void here. Without this the books can never catch up with FBR.
        $invoice = $this->reportedAt(
            $this->issuedInvoice(),
            Carbon::now()->subHour(),
            Invoice::FBR_CANCELLED,
        );

        $this->service->void($invoice);

        $this->assertSame(Invoice::STATUS_VOID, $invoice->refresh()->status);
    }

    public function test_a_reported_invoice_inside_the_window_is_refused_and_says_where_to_go(): void
    {
        $invoice = $this->reportedAt($this->issuedInvoice(), Carbon::now()->subHour());

        try {
            $this->service->void($invoice);
            $this->fail('A reported invoice was voided locally while FBR still considers it live.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cancelled in the FBR system first', $e->getMessage());
        }

        $this->assertSame(
            Invoice::STATUS_ISSUED,
            $invoice->refresh()->status,
            'The refusal did not leave the invoice alone.',
        );
    }

    public function test_a_reported_invoice_past_the_window_is_refused_and_names_the_credit_note(): void
    {
        // The worst case, and the reason any of this exists: after the window a
        // correction needs the Commissioner, which this application cannot do.
        $invoice = $this->reportedAt($this->issuedInvoice(), Carbon::now()->subDays(5));

        $this->assertFalse($invoice->fbrCorrectionWindowOpen());

        try {
            $this->service->void($invoice);
            $this->fail('An invoice past the FBR correction window was voided.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Commissioner Inland Revenue', $e->getMessage());
            $this->assertStringContainsString('credit note', $e->getMessage());
        }

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->refresh()->status);
    }

    public function test_an_in_flight_submission_blocks_the_void(): void
    {
        // Voiding something whose fate is unknown is how you end up cancelling
        // an invoice FBR is about to accept.
        $invoice = $this->issuedInvoice();
        $invoice->update(['fbr_status' => Invoice::FBR_SUBMITTED]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/being reported to FBR/');

        $this->service->void($invoice->refresh());
    }

    public function test_the_existing_paid_invoice_rule_still_wins(): void
    {
        // Ordering: an invoice with payments is refused for the old reason, not
        // the new one. The FBR check must not have moved in front of it.
        $invoice = $this->issuedInvoice();
        $this->service->recordPayment($invoice, 100, '2026-08-15');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/recorded payments/');

        $this->service->void($invoice->refresh());
    }

    // -------------------------------------------------------- reconciliation

    public function test_reporting_is_off_by_default(): void
    {
        $this->assertFalse(app(FbrReconciliation::class)->enabled());
    }

    public function test_nothing_is_a_gap_while_reporting_is_off(): void
    {
        // A company below the threshold must not be told its whole invoice
        // history is a compliance breach.
        $this->issuedInvoice();

        $reconciliation = app(FbrReconciliation::class);

        $this->assertCount(0, $reconciliation->unreported());
        $this->assertSame([], $reconciliation->findings());
        $this->assertSame(0, $reconciliation->total());
    }

    public function test_an_issued_invoice_is_a_gap_once_reporting_is_on(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $invoice = $this->issuedInvoice();

        $reconciliation = app(FbrReconciliation::class);

        $this->assertTrue($reconciliation->enabled());
        $this->assertTrue($reconciliation->unreported()->contains('id', $invoice->getKey()));
        $this->assertArrayHasKey('unreported', $reconciliation->findings());
    }

    public function test_a_reported_invoice_is_not_a_gap(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $this->reportedAt($this->issuedInvoice(), Carbon::now()->subHour());

        $this->assertCount(0, app(FbrReconciliation::class)->unreported());
    }

    public function test_a_refused_invoice_is_reported_whether_or_not_reporting_is_on(): void
    {
        // Switching reporting off does not make a rejection go away — the
        // invoice was sent and refused, and that divergence is still real.
        $invoice = $this->issuedInvoice();
        $invoice->update(['fbr_status' => Invoice::FBR_REJECTED]);

        $this->assertTrue(
            app(FbrReconciliation::class)->rejected()->contains('id', $invoice->getKey()),
        );
    }

    public function test_accepted_without_a_reference_number_is_reported(): void
    {
        // "Accepted" with no IRN cannot prove its own compliance, so it counts
        // as unreported however it is labelled.
        $invoice = $this->issuedInvoice();
        $invoice->update(['fbr_status' => Invoice::FBR_ACCEPTED, 'fbr_reported_at' => Carbon::now()]);

        $this->assertTrue(
            app(FbrReconciliation::class)->acceptedWithoutReference()->contains('id', $invoice->getKey()),
        );
    }

    public function test_a_submission_in_flight_too_long_is_reported_as_stuck(): void
    {
        $invoice = $this->issuedInvoice();
        $invoice->update(['fbr_status' => Invoice::FBR_SUBMITTED]);
        $invoice->forceFill(['updated_at' => Carbon::now()->subDays(3)])->saveQuietly();

        $this->assertTrue(
            app(FbrReconciliation::class)->stuck()->contains('id', $invoice->getKey()),
        );
    }

    public function test_drafts_and_voids_are_never_findings(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        // A draft was never issued and a void has been withdrawn. Neither is
        // something FBR is owed.
        Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-08-10',
            'subtotal' => 500,
            'tax_amount' => 0,
            'total' => 500,
        ]);

        $voided = $this->issuedInvoice();
        $this->service->void($voided);

        $this->assertSame(0, app(FbrReconciliation::class)->total());
    }

    public function test_a_purchase_bill_is_not_this_companys_to_report(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $supplier = Contact::create(['name' => 'FBR Test Supplier', 'kind' => 'supplier']);

        $bill = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $supplier->id,
            'invoice_date' => '2026-08-10',
            'subtotal' => 700,
            'tax_amount' => 0,
            'total' => 700,
        ]);

        $bill->lines()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 700,
            'line_total' => 700,
            // A non-product purchase line has to name the expense account it
            // posts to; InvoiceService refuses it otherwise. A leaf account —
            // the header ones cannot accept entries.
            'account_id' => Account::where('type', 'expense')->get()
                ->first(fn (Account $a): bool => $a->canAcceptEntries())
                ->id,
        ]);

        $this->service->issue($bill);

        $this->assertCount(
            0,
            app(FbrReconciliation::class)->unreported(),
            'A purchase bill was listed as this company\'s reporting gap.',
        );
    }
}
