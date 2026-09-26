<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Fbr\FbrDriver;
use App\Modules\Invoicing\Fbr\FbrResponse;
use App\Modules\Invoicing\Jobs\SubmitInvoiceToFbr;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\FbrSubmission;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\FbrReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\AccountingTestCase;

/**
 * FBR digital invoicing, phases 1 and 5: the driver layer and the queued
 * submission that rides on issue.
 *
 * The queue is sync in tests, so `issue()` runs the job inline — which is the
 * round trip these tests want: issue → job → driver → submission row → the
 * invoice's fbr_* columns → the QR on the PDF, with nothing stubbed but the
 * integrator itself (the null driver, which is the point of having one).
 *
 * See docs/fbr-digital-invoicing-plan.md §3 and phase 5.
 */
class FbrSubmissionPipelineTest extends AccountingTestCase
{
    private InvoiceService $service;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create([
            'name' => 'FBR Pipeline Customer',
            'kind' => 'customer',
            'ntn' => '1234567-8',
        ]);
    }

    private function enableReporting(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);
    }

    private function issuedInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total' => 1000,
        ]);

        $invoice->lines()->create([
            'description' => 'Consultancy',
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
        ]);

        $this->service->issue($invoice);

        return $invoice->refresh();
    }

    /** A driver that never hears back — the outcome that retries. */
    private function timingOutDriver(): FbrDriver
    {
        return new class implements FbrDriver
        {
            public function name(): string
            {
                return 'timing-out';
            }

            public function submit(array $payload, string $idempotencyKey): FbrResponse
            {
                return FbrResponse::error('integrator timed out', 504);
            }
        };
    }

    // ------------------------------------------------------------- dispatch

    public function test_issue_does_not_dispatch_while_reporting_is_off(): void
    {
        // The default for every company today. Issue must stay exactly the
        // local operation it was — no job, no fbr state change.
        Queue::fake();

        $invoice = $this->issuedInvoice();

        Queue::assertNotPushed(SubmitInvoiceToFbr::class);
        $this->assertSame(Invoice::FBR_NOT_REQUIRED, $invoice->fbr_status);
    }

    public function test_issue_dispatches_the_job_when_reporting_is_on(): void
    {
        $this->enableReporting();
        Queue::fake();

        $invoice = $this->issuedInvoice();

        Queue::assertPushed(SubmitInvoiceToFbr::class, fn (SubmitInvoiceToFbr $job): bool => $job->invoiceId === $invoice->getKey());

        // Pending until the worker picks it up, so a queue that never runs it
        // ages into the reconciliation report instead of looking untouched.
        $this->assertSame(Invoice::FBR_PENDING, $invoice->refresh()->fbr_status);
    }

    public function test_a_purchase_bill_is_never_dispatched(): void
    {
        // The supplier's to report, not this company's.
        $this->enableReporting();
        Queue::fake();

        $supplier = Contact::create(['name' => 'FBR Pipeline Supplier', 'kind' => 'supplier']);

        $bill = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'subtotal' => 700,
            'tax_amount' => 0,
            'total' => 700,
        ]);

        $bill->lines()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 700,
            'line_total' => 700,
            'account_id' => Account::where('type', 'expense')->get()
                ->first(fn (Account $a): bool => $a->canAcceptEntries())
                ->id,
        ]);

        $this->service->issue($bill);

        Queue::assertNotPushed(SubmitInvoiceToFbr::class);
    }

    // ---------------------------------------------------- null driver round trip

    public function test_the_null_driver_round_trip_records_a_submission_and_accepts_the_invoice(): void
    {
        // fbr.driver defaults to 'null'; the sync queue runs the job inside
        // issue(). This is the rehearsal a company runs before its integrator
        // registration is complete — the whole pipeline, nothing transmitted.
        $this->enableReporting();

        $invoice = $this->issuedInvoice();

        $this->assertSame(Invoice::FBR_ACCEPTED, $invoice->fbr_status);
        $this->assertStringStartsWith('NULL-', $invoice->fbr_irn, 'A dry-run IRN must be unmistakably synthetic.');
        $this->assertNotNull($invoice->fbr_reported_at);
        $this->assertNotNull($invoice->fbr_qr_payload);

        $submission = FbrSubmission::where('invoice_id', $invoice->getKey())->sole();

        $this->assertSame(FbrSubmission::OUTCOME_ACCEPTED, $submission->outcome);
        $this->assertSame('null', $submission->driver);
        $this->assertNotEmpty($submission->idempotency_key);
        // What would have been sent, recorded — the reason the null driver exists.
        $this->assertSame($invoice->invoice_number, $submission->request_payload['invoice_number']);
        $this->assertSame('1234567-8', $submission->request_payload['buyer']['ntn']);
        // Whole amounts round-trip the json column as integers; compare numerically.
        $this->assertEquals(1000, $submission->request_payload['total']);

        // Accepted means no reconciliation finding of any kind.
        $this->assertSame(0, app(FbrReconciliation::class)->total());
    }

    // ------------------------------------------------- errors, retries, giving up

    public function test_a_transport_error_records_the_attempt_and_throws_for_the_retry(): void
    {
        $this->enableReporting();
        Queue::fake();
        $invoice = $this->issuedInvoice();

        $job = new SubmitInvoiceToFbr($invoice->getKey());

        try {
            $job->handle($this->timingOutDriver());
            $this->fail('A never-heard-back submission did not throw, so the queue would never retry it.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no answer', $e->getMessage());
        }

        // The attempt is on the log even though nothing answered.
        $submission = FbrSubmission::where('invoice_id', $invoice->getKey())->sole();
        $this->assertSame(FbrSubmission::OUTCOME_ERROR, $submission->outcome);
        $this->assertSame(504, $submission->http_status);

        // In flight from every other screen's point of view.
        $this->assertSame(Invoice::FBR_SUBMITTED, $invoice->refresh()->fbr_status);

        // A retry reuses the idempotency key, so it updates the row rather than
        // inserting a sibling the integrator would read as a second invoice.
        try {
            $job->handle($this->timingOutDriver());
            $this->fail('The retry did not throw.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, FbrSubmission::where('invoice_id', $invoice->getKey())->count());

        // Backoff is declared, ascending, and finite — the queue's contract.
        $this->assertGreaterThan(1, $job->tries);
        $backoff = $job->backoff();
        $this->assertNotEmpty($backoff);
        $this->assertSame($backoff, collect($backoff)->sort()->values()->all());
    }

    public function test_a_permanent_failure_lands_where_unreported_surfaces_it(): void
    {
        $this->enableReporting();
        Queue::fake();
        $invoice = $this->issuedInvoice();

        $job = new SubmitInvoiceToFbr($invoice->getKey());

        try {
            $job->handle($this->timingOutDriver());
        } catch (RuntimeException $e) {
            // What the queue does once the tries are spent.
            $job->failed($e);
        }

        $this->assertSame(Invoice::FBR_NOT_REQUIRED, $invoice->refresh()->fbr_status);

        $reconciliation = app(FbrReconciliation::class);
        $this->assertTrue(
            $reconciliation->unreported()->contains('id', $invoice->getKey()),
            'A submission that spent its retries must surface as the compliance gap it is.',
        );
        $this->assertArrayHasKey('unreported', $reconciliation->findings());
    }

    // ------------------------------------------------------------ credit notes

    public function test_a_credit_note_is_refused_with_a_reconciliation_trail(): void
    {
        // Reportedly a credit note must be transmitted too (plan §9.7), and no
        // driver defines how. The refusal must leave a trail, not a silent hole.
        $this->enableReporting();

        $invoice = $this->issuedInvoice();
        $note = $this->service->creditNote($invoice, 'Damaged consignment');
        $this->service->issue($note);
        $note->refresh();

        // Where unreported() lists it — the trail's headline.
        $this->assertSame(Invoice::FBR_NOT_REQUIRED, $note->fbr_status);
        $this->assertTrue(app(FbrReconciliation::class)->unreported()->contains('id', $note->getKey()));

        // And the row that says why, rather than leaving the gap unexplained.
        $submission = FbrSubmission::where('invoice_id', $note->getKey())->sole();
        $this->assertSame(FbrSubmission::OUTCOME_ERROR, $submission->outcome);
        $this->assertSame('credit_note_unsupported', $submission->error_code);
        $this->assertStringContainsString('Credit notes cannot be transmitted', $submission->error_message);
    }

    // -------------------------------------------------------------------- PDF

    public function test_the_pdf_carries_the_fbr_box_only_for_a_reported_invoice(): void
    {
        $this->enableReporting();

        $reported = $this->issuedInvoice();
        $html = view('pdfs.invoice', ['invoice' => $reported->load(['contact', 'lines.product', 'creditedInvoice'])])->render();

        $this->assertStringContainsString("FBR IRN: {$reported->fbr_irn}", $html);

        if (extension_loaded('gd')) {
            // The QR is a server-generated PNG data URI — Dompdf runs no JS, so
            // a JS QR library was never an option. Without GD the box degrades
            // to the IRN as text, which the assertion above already covers.
            $this->assertStringContainsString('data:image/png;base64,', $html);
            $this->assertStringContainsString('FBR verification QR', $html);
        }
    }

    public function test_the_pdf_carries_no_fbr_box_for_an_unreported_invoice(): void
    {
        // Reporting off: the invoice issues as before, and a QR claiming a
        // verification that does not exist must not appear.
        $invoice = $this->issuedInvoice();
        $html = view('pdfs.invoice', ['invoice' => $invoice->load(['contact', 'lines.product', 'creditedInvoice'])])->render();

        $this->assertStringNotContainsString('FBR IRN', $html);
        $this->assertStringNotContainsString('data:image/png', $html);
    }
}
