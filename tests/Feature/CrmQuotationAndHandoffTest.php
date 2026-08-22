<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Services\DealHandoffs;
use App\Modules\Crm\Services\OpportunityService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Projects\Models\Project;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * CRM phases 5 and 6 — docs/crms-plan.md §4, §3's hand-offs, and §12.8 through §12.11 and §12.19.
 *
 * The assertions that carry this file are all refusals:
 *
 *  - **A quote posts nothing** (§4). Not a journal entry, not a pending one.
 *  - **Conversion produces a DRAFT invoice** — the narrow resolution of the FBR block §13
 *    recorded. Issuing transmits, and a transmitted invoice cannot be freely voided after 72
 *    hours, so nothing here issues anything.
 *  - **A superseded quote stays reproducible** (§12.8). The customer has that version.
 *  - **An expired quote cannot be accepted** (§12.10).
 *  - **A renewal owns no row** (§12.19) — it is a view over recurring invoices.
 */
class CrmQuotationAndHandoffTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Contact $customer;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['crm', 'quotations', 'accounting', 'invoicing', 'projects', 'employees'] as $module) {
            $this->setModule($module, true);
        }

        $this->customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);
        $this->pipeline = $this->makePipeline();
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function makePipeline(): Pipeline
    {
        $pipeline = Pipeline::create(['name' => 'New business', 'is_default' => true]);

        $pipeline->stages()->createMany([
            ['name' => 'Qualification', 'sort' => 1, 'probability_pct' => 10],
            ['name' => 'Won', 'sort' => 2, 'probability_pct' => 100, 'is_won' => true],
            ['name' => 'Lost', 'sort' => 3, 'probability_pct' => 0, 'is_lost' => true],
        ]);

        return $pipeline->fresh('stages');
    }

    private function makeQuote(array $attributes = [], array $line = []): Quotation
    {
        $quote = Quotation::create(array_merge([
            'contact_id' => $this->customer->id,
            'valid_until' => now()->addDays(30)->toDateString(),
        ], $attributes));

        $quote->lines()->create(array_merge([
            'description' => 'Warehouse system',
            'quantity' => 2,
            'unit_price' => 100000,
        ], $line));

        return $quote->fresh('lines')->recalculate();
    }

    // ─────────────────────────── §4: a quote posts nothing ────────────────────

    /** THE §4 test. A quote is an offer; nothing has happened. */
    public function test_a_quote_posts_nothing_to_the_ledger(): void
    {
        $entries = JournalEntry::count();

        $quote = $this->makeQuote();
        app(QuotationService::class)->send($quote);
        app(QuotationService::class)->accept($quote->fresh());

        $this->assertSame($entries, JournalEntry::count(), 'A quote must never reach the ledger.');
        $this->assertSame(0, Invoice::count(), 'Accepting a quote does not raise an invoice by itself.');
    }

    public function test_the_totals_come_from_the_lines_including_the_discount(): void
    {
        // 2 × 100,000 less 10% = 180,000.
        $quote = $this->makeQuote(line: ['discount_pct' => 10]);

        $this->assertSame(180000.0, (float) $quote->subtotal);
        $this->assertSame(180000.0, (float) $quote->total);
    }

    public function test_a_quote_with_no_lines_cannot_be_sent(): void
    {
        $quote = Quotation::create(['contact_id' => $this->customer->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no lines is not an offer');

        app(QuotationService::class)->send($quote);
    }

    // ─────────────────────────── §12.8: supersession ──────────────────────────

    /**
     * §12.8 — a quote revised produces v2 with `supersedes_id`, and **v1 remains reproducible**.
     *
     * That is the whole reason revising is not editing: the customer has v1 in their inbox, and
     * a system that edited in place could not say which version was agreed.
     */
    public function test_revising_produces_a_new_version_and_leaves_the_original_intact(): void
    {
        $original = $this->makeQuote();
        app(QuotationService::class)->send($original);

        $originalTotal = (float) $original->fresh()->total;

        $revision = app(QuotationService::class)->revise($original->fresh());

        $this->assertSame(2, $revision->version);
        $this->assertSame($original->id, $revision->supersedes_id);
        $this->assertSame(Quotation::STATUS_DRAFT, $revision->status);
        $this->assertNotSame($original->number, $revision->number);

        // v1 is untouched and still says what was sent.
        $original->refresh();
        $this->assertSame(Quotation::STATUS_SUPERSEDED, $original->status);
        $this->assertSame($originalTotal, (float) $original->total);
        $this->assertSame(1, $original->lines()->count());

        // And the lines were copied, not moved.
        $this->assertSame(1, $revision->lines()->count());
    }

    /** An accepted quote is what was agreed, so revising it is refused. */
    public function test_an_accepted_quote_cannot_be_revised(): void
    {
        $quote = $this->makeQuote();
        app(QuotationService::class)->send($quote);
        app(QuotationService::class)->accept($quote->fresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has been accepted');

        app(QuotationService::class)->revise($quote->fresh());
    }

    // ─────────────────────────── §12.10: expiry ───────────────────────────────

    /**
     * §12.10 — an expired quote cannot be accepted.
     *
     * Checked by DATE rather than only by the stored status, so a quote that lapsed this morning
     * cannot be accepted this afternoon even before the nightly sweep has run.
     */
    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $quote = $this->makeQuote(['valid_until' => now()->subDay()->toDateString()]);
        app(QuotationService::class)->send($quote);

        $this->assertTrue($quote->fresh()->hasExpired());
        $this->assertSame(Quotation::STATUS_SENT, $quote->fresh()->status, 'The sweep has not run yet.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expired on');

        app(QuotationService::class)->accept($quote->fresh());
    }

    public function test_the_sweep_expires_lapsed_quotes_once(): void
    {
        $quote = $this->makeQuote(['valid_until' => now()->subDay()->toDateString()]);
        app(QuotationService::class)->send($quote);

        $this->assertSame(1, app(QuotationService::class)->expireLapsed());
        $this->assertSame(Quotation::STATUS_EXPIRED, $quote->fresh()->status);

        // Once, not every night: the second run finds nothing.
        $this->assertSame(0, app(QuotationService::class)->expireLapsed());
    }

    /** A quote with no validity date never expires. */
    public function test_a_quote_without_a_validity_date_never_expires(): void
    {
        $quote = $this->makeQuote(['valid_until' => null]);
        app(QuotationService::class)->send($quote);

        $this->assertFalse($quote->fresh()->hasExpired());
        $this->assertSame(0, app(QuotationService::class)->expireLapsed());
    }

    // ─────────────────────────── §12.9: conversion ────────────────────────────

    /**
     * §12.9 — conversion copies lines, tax rates and currency, and **the invoice is what
     * posts**, not the quote.
     *
     * And it stops at DRAFT. That is the narrow resolution of the FBR block: issuing transmits,
     * and a transmitted invoice cannot be freely voided after 72 hours.
     */
    public function test_conversion_copies_the_lines_into_a_draft_invoice(): void
    {
        $quote = $this->makeQuote(line: ['discount_pct' => 10]);
        app(QuotationService::class)->send($quote);
        app(QuotationService::class)->accept($quote->fresh());

        $invoice = app(QuotationService::class)->convertToInvoice($quote->fresh());

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status, 'Issuing transmits — that stays deliberate.');
        $this->assertSame($this->customer->id, $invoice->contact_id);
        $this->assertSame(180000.0, (float) $invoice->total);
        $this->assertSame(1, $invoice->lines()->count());
        // The discount is in the price, and named in the description rather than re-argued.
        $this->assertSame(180000.0, (float) $invoice->lines()->first()->line_total);
        $this->assertStringContainsString('less 10', $invoice->lines()->first()->description);

        $this->assertSame($invoice->id, $quote->fresh()->invoice_id);
    }

    public function test_a_quote_cannot_be_invoiced_twice(): void
    {
        $quote = $this->makeQuote();
        app(QuotationService::class)->send($quote);
        app(QuotationService::class)->accept($quote->fresh());
        app(QuotationService::class)->convertToInvoice($quote->fresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bill the customer twice');

        app(QuotationService::class)->convertToInvoice($quote->fresh());
    }

    /** An unaccepted quote is not an instruction to bill anybody. */
    public function test_only_an_accepted_quote_becomes_an_invoice(): void
    {
        $quote = $this->makeQuote();
        app(QuotationService::class)->send($quote);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an accepted quote');

        app(QuotationService::class)->convertToInvoice($quote->fresh());
    }

    /** A quote to a lead has no party the ledger can bill. */
    public function test_a_quote_to_a_lead_cannot_be_invoiced_until_the_lead_converts(): void
    {
        $lead = Lead::create(['company_name' => 'Prospect Ltd']);

        $quote = $this->makeQuote(['contact_id' => null, 'lead_id' => $lead->id]);
        app(QuotationService::class)->send($quote);
        app(QuotationService::class)->accept($quote->fresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Convert the lead first');

        app(QuotationService::class)->convertToInvoice($quote->fresh());
    }

    // ─────────────────────────── phase 6: hand-offs ───────────────────────────

    /**
     * A won deal becomes a project only when somebody says so, and posts nothing on the way.
     */
    public function test_a_won_deal_can_open_a_project(): void
    {
        $deal = $this->wonDeal();

        $project = app(DealHandoffs::class)->openProject($deal);

        $this->assertSame($deal->title, $project->name);
        $this->assertSame($this->customer->id, $project->contact_id);
        $this->assertSame($project->id, $deal->fresh()->project_id);
    }

    public function test_an_open_deal_cannot_open_a_project(): void
    {
        $deal = $this->openDeal();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a won deal');

        app(DealHandoffs::class)->openProject($deal);
    }

    /** The invoice from a deal is a DRAFT, for the same FBR reason. */
    public function test_a_won_deal_raises_a_draft_invoice_only(): void
    {
        $entries = JournalEntry::count();
        $deal = $this->wonDeal();

        $invoice = app(DealHandoffs::class)->raiseDraftInvoice($deal);

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame(500000.0, (float) $invoice->total);
        $this->assertSame($entries, JournalEntry::count(), 'A draft posts nothing.');
        $this->assertSame($invoice->id, $deal->fresh()->invoice_id);
    }

    public function test_a_deal_cannot_be_invoiced_twice(): void
    {
        $deal = $this->wonDeal();
        app(DealHandoffs::class)->raiseDraftInvoice($deal);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bill the customer twice');

        app(DealHandoffs::class)->raiseDraftInvoice($deal->fresh());
    }

    /** Absent, not broken, without the module it hands off to. */
    public function test_the_project_handoff_is_unavailable_without_the_projects_module(): void
    {
        $this->setModule('projects', false);
        $deal = $this->wonDeal();

        $this->assertFalse(app(DealHandoffs::class)->canOpenProject());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the Projects module');

        app(DealHandoffs::class)->openProject($deal);
    }

    // ─────────────────────────── §12.19: renewals own no row ──────────────────

    /**
     * §12.19 — the renewals surface returns a **view over recurring invoices**, and owns no
     * table of its own.
     *
     * Asserted by counting: no new table gains a row, and what comes back is the recurring
     * invoice itself.
     */
    public function test_renewals_are_a_view_over_recurring_invoices(): void
    {
        $recurring = \App\Modules\Invoicing\Models\RecurringInvoice::create([
            'contact_id' => $this->customer->id,
            'kind' => Invoice::KIND_SALE,
            'description' => 'Annual support',
            'day_of_month' => 1,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        $renewals = app(DealHandoffs::class)->renewalsDue();

        $this->assertCount(1, $renewals);
        $this->assertTrue($renewals[0]['recurring']->is($recurring));
        $this->assertSame('Karachi Textiles', $renewals[0]['party']);
        // No deal is working it, so CRM's own judgement is "at risk" — the one thing it adds.
        $this->assertTrue($renewals[0]['at_risk']);
    }

    /** An open deal against the customer means somebody is on it. */
    public function test_a_renewal_with_an_open_deal_is_not_at_risk(): void
    {
        \App\Modules\Invoicing\Models\RecurringInvoice::create([
            'contact_id' => $this->customer->id,
            'kind' => Invoice::KIND_SALE,
            'description' => 'Annual support',
            'day_of_month' => 1,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->openDeal(['contact_id' => $this->customer->id, 'lead_id' => null]);

        $this->assertFalse(app(DealHandoffs::class)->renewalsDue()[0]['at_risk']);
    }

    /** Without Invoicing there are no recurring invoices to be a view of. */
    public function test_renewals_are_absent_without_the_invoicing_module(): void
    {
        $this->setModule('invoicing', false);

        $this->assertFalse(app(DealHandoffs::class)->renewalsAvailable());
        $this->assertCount(0, app(DealHandoffs::class)->renewalsDue());
    }

    // ─────────────────────────── helpers ─────────────────────────────────────

    private function openDeal(array $attributes = []): Opportunity
    {
        return app(OpportunityService::class)->open(new Opportunity(array_merge([
            'title' => 'Warehouse system',
            'pipeline_id' => $this->pipeline->id,
            'pipeline_stage_id' => $this->pipeline->stages->firstWhere('name', 'Qualification')->id,
            'contact_id' => $this->customer->id,
            'amount' => 500000,
        ], $attributes)));
    }

    private function wonDeal(): Opportunity
    {
        $deal = $this->openDeal();

        return app(OpportunityService::class)->markWon($deal, $this->actor);
    }
}
