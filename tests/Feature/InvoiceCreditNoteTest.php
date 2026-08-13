<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Services\FbrReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;

/**
 * Credit notes — the third row of the correction table in
 * docs/fbr-digital-invoicing-plan.md §2, and the reason phase 5 of the CRM plan was blocked.
 *
 * `void()` has three cases and, until credit notes existed, only two answers. An invoice
 * reported to FBR more than 72 hours ago cannot be cancelled, locally or there; the refusal
 * told people to raise a credit note, and there was nothing to raise. The first test below is
 * the one that closes that: the refusal still refuses, and the remedy it names now exists.
 *
 * The rest are about the two ways a credit note can be wrong rather than merely absent:
 *
 *  - **It does not reverse what it credits.** A correction whose figures differ from the
 *    posting it corrects leaves the tax account, the revenue account or the receivable out by
 *    the difference, permanently, and reconciles to nothing. The mirror-image assertions below
 *    are all forms of this.
 *  - **It is counted as money owed.** A credit note added to receivables instead of subtracted
 *    overstates every figure in the application by the credits outstanding — which is the
 *    reading somebody chases a customer over.
 */
class InvoiceCreditNoteTest extends AccountingTestCase
{
    private InvoiceService $service;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create(['name' => 'Credit Note Customer', 'kind' => 'customer']);
    }

    /**
     * @param  array<int, array{0: float, 1?: int|null}>  $lines  [amount, tax rate id]
     */
    private function invoice(array $lines = [[1000.0]], array $attributes = []): Invoice
    {
        // $attributes first: with `+` the left operand wins, so defaults on the left would
        // make `kind` unoverridable and quietly issue a sale invoice in the purchase test.
        $invoice = Invoice::create($attributes + [
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
        ]);

        foreach ($lines as $index => $line) {
            $invoice->lines()->create([
                'description' => 'Consultancy '.($index + 1),
                'quantity' => 1,
                'unit_price' => $line[0],
                'line_total' => $line[0],
                'tax_rate_id' => $line[1] ?? null,
            ]);
        }

        // Through applyTaxes where there are rates, so a taxed fixture's header is derived by
        // the same code a real invoice's is rather than by arithmetic done here. It returns
        // early — by design — when no line carries a rate, so the untaxed case is totalled
        // from the lines.
        $this->service->applyTaxes($invoice);
        $invoice->refresh();

        if ((float) $invoice->total === 0.0) {
            $sum = round((float) $invoice->lines()->sum('line_total'), 2);
            $invoice->forceFill(['subtotal' => $sum, 'tax_amount' => 0, 'total' => $sum])->save();
        }

        return $invoice->refresh();
    }

    private function issued(array $lines = [[1000.0]], array $attributes = []): Invoice
    {
        $invoice = $this->invoice($lines, $attributes);
        $this->service->issue($invoice);

        return $invoice->refresh();
    }

    private function reportedAt(Invoice $invoice, Carbon $when): Invoice
    {
        $invoice->update([
            'fbr_status' => Invoice::FBR_ACCEPTED,
            'fbr_irn' => 'IRN-'.$invoice->getKey(),
            'fbr_reported_at' => $when,
        ]);

        return $invoice->refresh();
    }

    /** Net movement on an account across an entry: debits less credits. */
    private function movement(Invoice $invoice, string $code): float
    {
        $accountId = Account::where('code', $code)->firstOrFail()->id;
        $lines = $invoice->journalEntry->lines()->where('account_id', $accountId);

        return round((float) $lines->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }

    // ------------------------------------------------------- the blocked row

    /**
     * The closure. The refusal is unchanged — that is the compliance rule and it must not
     * soften — and the document it points at can now be raised.
     */
    public function test_a_reported_invoice_past_the_window_refuses_the_void_and_the_credit_works(): void
    {
        $invoice = $this->reportedAt($this->issued(), now()->subHours(100));

        try {
            $this->service->void($invoice);
            $this->fail('A reported invoice past the correction window was voided.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('correction window has closed', $e->getMessage());
            $this->assertStringContainsString('Credit', $e->getMessage());
        }

        // The remedy the refusal names, actually available.
        $note = $this->service->creditNote($invoice, 'Reported in error, past the amendment window');

        $this->assertSame(Invoice::KIND_CREDIT_NOTE, $note->kind);
        $this->assertSame($invoice->getKey(), $note->credits_invoice_id);
        $this->assertSame('1000.00', $note->total);

        // And the invoice it corrects is untouched — still issued, still reported. That is
        // the difference between a correction and a cover-up: FBR holds a record of this
        // invoice, so the invoice has to keep existing.
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->refresh()->status);
        $this->assertSame(Invoice::FBR_ACCEPTED, $invoice->fbr_status);
    }

    /** Within the window nothing changes: cancel at FBR first, as before. */
    public function test_the_within_window_refusal_still_points_at_fbr_rather_than_a_credit_note(): void
    {
        $invoice = $this->reportedAt($this->issued(), now()->subHour());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/FBR system first/');

        $this->service->void($invoice);
    }

    // ------------------------------------------------------------ the mirror

    /**
     * The property the whole feature rests on: invoice plus credit note equals nothing.
     *
     * Asserted account by account rather than on the totals, because a pair of entries can
     * balance individually and still leave revenue and tax swapped between them.
     */
    public function test_a_full_credit_note_reverses_the_sale_account_by_account(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);
        $invoice = $this->issued([[10000.0, $rate->id]]);

        $this->assertSame('11800.00', $invoice->total);

        $note = $this->service->creditNote($invoice, 'Billed twice');
        $this->service->issue($note);
        $note->refresh();

        foreach (['1250', '4300', TaxRate::DEFAULT_ACCOUNT_CODE] as $code) {
            $this->assertSame(
                0.0,
                round($this->movement($invoice, $code) + $this->movement($note, $code), 2),
                "Account {$code} does not net to zero across the invoice and its credit note."
            );
        }

        // And each side is the shape it should be, so "nets to zero" cannot be satisfied by
        // both being zero.
        $this->assertSame(11800.0, $this->movement($invoice, '1250'));
        $this->assertSame(-11800.0, $this->movement($note, '1250'));
        $this->assertSame(-10000.0, $this->movement($invoice, '4300'));
        $this->assertSame(10000.0, $this->movement($note, '4300'));
        $this->assertSame(-1800.0, $this->movement($invoice, TaxRate::DEFAULT_ACCOUNT_CODE));
        $this->assertSame(1800.0, $this->movement($note, TaxRate::DEFAULT_ACCOUNT_CODE));
    }

    public function test_it_reverses_an_inclusive_invoice_without_booking_the_tax_as_revenue(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);
        $invoice = $this->issued([[11800.0, $rate->id]], ['tax_inclusive' => true]);

        $this->assertSame('10000.00', $invoice->subtotal);
        $this->assertSame('1800.00', $invoice->tax_amount);

        $note = $this->service->creditNote($invoice, 'Cancelled order');
        $this->service->issue($note);

        // The trap: debiting the gross 11,800 to revenue would take the tax out of income
        // and leave the tax account untouched. Both figures are checked, so it cannot pass
        // by being wrong in both places.
        $this->assertSame(10000.0, $this->movement($note->refresh(), '4300'));
        $this->assertSame(1800.0, $this->movement($note, TaxRate::DEFAULT_ACCOUNT_CODE));
    }

    /**
     * The rate-drift guard, and the reason `issue()` does not run `applyTaxes()` on a credit
     * note.
     *
     * The invoice charged 18%. The rate row is then edited to 5% — which this application
     * permits — and the credit note must still reverse the 1,800 that was actually posted. A
     * credit note re-derived from the rate table would reverse 500 and leave the tax account
     * permanently 1,300 to the good, with both documents looking internally consistent.
     */
    public function test_a_credit_note_reverses_the_tax_charged_not_the_rate_in_force_today(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);
        $invoice = $this->issued([[10000.0, $rate->id]]);

        $rate->update(['rate' => 5]);

        $note = $this->service->creditNote($invoice, 'Priced wrong');
        $this->service->issue($note);
        $note->refresh();

        $this->assertSame('1800.00', $note->tax_amount, 'The credit note re-derived tax from the edited rate.');
        $this->assertSame('11800.00', $note->total);
        $this->assertSame(1800.0, $this->movement($note, TaxRate::DEFAULT_ACCOUNT_CODE));
        $this->assertSame(0.0, round($this->movement($invoice, '1250') + $this->movement($note, '1250'), 2));
    }

    /**
     * A foreign invoice is credited at the rate it was booked at, not today's.
     *
     * Crediting at a different rate would clear a different base amount than was booked,
     * leaving a stub in A/R that looks like an uncollectable balance and recognising an
     * exchange gain nobody earned.
     */
    public function test_a_credit_note_inherits_the_invoices_exchange_rate(): void
    {
        $invoice = $this->issued([[1000.0]], [
            'currency_code' => 'USD',
            'exchange_rate' => 280,
        ]);

        $note = $this->service->creditNote($invoice, 'Order cancelled');

        $this->assertSame('USD', $note->currency_code);
        $this->assertSame($invoice->exchange_rate, $note->exchange_rate);

        $this->service->issue($note);

        // Both control legs are the same base figure, so A/R nets to zero in the base
        // currency as well as the billed one.
        $this->assertSame(
            0.0,
            round($this->movement($invoice, '1250') + $this->movement($note->refresh(), '1250'), 2)
        );
    }

    // ----------------------------------------------------------- the drafting

    public function test_a_credit_note_is_a_draft_and_posts_nothing_until_issued(): void
    {
        $note = $this->service->creditNote($this->issued(), 'Goods returned');

        $this->assertSame(Invoice::STATUS_DRAFT, $note->status);
        $this->assertNull($note->journal_entry_id);
    }

    public function test_credit_notes_are_numbered_in_their_own_series(): void
    {
        $first = $this->service->creditNote($this->issued(), 'One');
        $second = $this->service->creditNote($this->issued(), 'Two');

        $year = now()->format('Y');

        $this->assertSame("CN-{$year}-000001", $first->invoice_number);
        $this->assertSame("CN-{$year}-000002", $second->invoice_number);

        // And the invoice series is undisturbed — no gap for somebody to go looking for.
        $this->assertStringStartsWith("INV-{$year}-", $this->issued()->invoice_number);
    }

    public function test_the_invoices_own_history_records_that_it_was_credited(): void
    {
        $invoice = $this->issued();
        $note = $this->service->creditNote($invoice, 'Damaged in transit');

        $event = $invoice->events()->where('event', InvoiceEvent::CREDITED)->firstOrFail();

        $this->assertStringContainsString($note->invoice_number, $event->description);
        $this->assertStringContainsString('Damaged in transit', $event->description);
        $this->assertSame('1000.00', $event->amount);
    }

    /**
     * The draft is editable, and issuing re-totals it from whatever it ended up containing.
     *
     * This is how a partial credit is made through the interface: credit in full, delete the
     * lines that were right. Without the re-total the header would still claim the full amount
     * and `validateTotals()` would refuse to issue it, complaining about arithmetic nobody
     * performed.
     */
    public function test_issuing_a_trimmed_draft_credit_note_retotals_it_from_its_lines(): void
    {
        $invoice = $this->issued([[600.0], [400.0]]);
        $note = $this->service->creditNote($invoice, 'Only the second line was wrong');

        $this->assertSame('1000.00', $note->total);

        $note->lines()->where('description', 'Consultancy 1')->delete();

        $this->service->issue($note);

        $this->assertSame('400.00', $note->refresh()->total);
        $this->assertSame(-400.0, $this->movement($note, '1250'));
    }

    public function test_a_partial_credit_can_be_built_from_explicit_lines(): void
    {
        $invoice = $this->issued([[1000.0]]);

        $note = $this->service->creditNote($invoice, 'Half the work was not done', [
            ['description' => 'Partial credit', 'quantity' => 1, 'unit_price' => 400],
        ]);

        $this->assertSame('400.00', $note->total);
        $this->assertSame(600.0, $invoice->refresh()->creditableAmount());
        $this->assertFalse($invoice->isFullyCredited());
    }

    // -------------------------------------------------------------- the guards

    public function test_it_refuses_to_credit_more_than_is_left(): void
    {
        $invoice = $this->issued([[1000.0]]);

        $this->service->creditNote($invoice, 'First', [
            ['description' => 'Part', 'quantity' => 1, 'unit_price' => 700],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only 300 of invoice/');

        $this->service->creditNote($invoice->refresh(), 'Second', [
            ['description' => 'Part', 'quantity' => 1, 'unit_price' => 400],
        ]);
    }

    /**
     * Drafts count towards the credited total, which is the half of the over-credit guard
     * that is easy to leave out. A draft has posted nothing — but somebody has already
     * decided to give this money back, and letting a second person credit the full amount
     * while the first sits unissued is how an invoice ends up credited twice.
     */
    public function test_an_unissued_credit_note_still_blocks_a_second_full_one(): void
    {
        $invoice = $this->issued();

        $draft = $this->service->creditNote($invoice, 'First');
        $this->assertSame(Invoice::STATUS_DRAFT, $draft->status);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already been credited in full/');

        $this->service->creditNote($invoice->refresh(), 'Second');
    }

    /** A voided credit note releases the invoice — the abandoned attempt must not block it. */
    public function test_voiding_a_credit_note_frees_the_invoice_to_be_credited_again(): void
    {
        $invoice = $this->issued();

        $note = $this->service->creditNote($invoice, 'Raised against the wrong invoice');
        $this->service->issue($note);
        $this->service->void($note->refresh());

        $this->assertSame(0.0, $invoice->refresh()->creditedTotal());

        $replacement = $this->service->creditNote($invoice, 'The right one this time');
        $this->assertSame('1000.00', $replacement->total);
    }

    /**
     * Both reverse the same posting, so doing both takes the revenue out twice and leaves
     * the receivable negative by the invoice total.
     */
    public function test_a_credited_invoice_cannot_also_be_voided(): void
    {
        $invoice = $this->issued();
        $this->service->creditNote($invoice, 'Credited');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reverse the same posting twice/');

        $this->service->void($invoice->refresh());
    }

    public function test_it_refuses_to_credit_a_draft(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/still a draft/');

        $this->service->creditNote($this->invoice(), 'Nothing to reverse');
    }

    public function test_it_refuses_to_credit_a_voided_invoice(): void
    {
        $invoice = $this->issued();
        $this->service->void($invoice);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already reversed/');

        $this->service->creditNote($invoice->refresh(), 'Again');
    }

    public function test_it_refuses_to_credit_a_purchase_bill(): void
    {
        $bill = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-08-10',
        ]);
        // A non-product purchase line needs somewhere to expense to.
        $bill->lines()->create([
            'description' => 'Subcontractor',
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'account_id' => Account::where('code', '5100')->firstOrFail()->id,
        ]);
        $bill->forceFill(['subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000])->save();
        $this->service->issue($bill);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/supplier, who issues the credit note to you/');

        $this->service->creditNote($bill, 'Overbilled us');
    }

    public function test_it_refuses_to_credit_a_credit_note(): void
    {
        $note = $this->service->creditNote($this->issued(), 'First');
        $this->service->issue($note);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be credited/');

        $this->service->creditNote($note->refresh(), 'Turtles all the way down');
    }

    public function test_it_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a reason/');

        $this->service->creditNote($this->issued(), '   ');
    }

    public function test_a_credit_note_is_not_paid(): void
    {
        $note = $this->service->creditNote($this->issued(), 'Goods returned');
        $this->service->issue($note);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not paid/');

        $this->service->recordPayment($note->refresh(), 500, '2026-08-14');
    }

    /**
     * No stock movement, and that is a decision. Crediting a customer and taking goods back
     * are different events: assuming the goods returned would put quantity into inventory
     * that is not there and hand the valuation engine lots at a made-up cost.
     */
    public function test_a_credit_note_moves_no_stock_and_does_not_reverse_cogs(): void
    {
        $product = Product::create([
            'sku' => 'CN-WIDGET',
            'name' => 'Widget',
            'unit_price' => 500,
            'is_active' => true,
        ]);

        // Stock arrives the way it does in production: an issued purchase bill, which creates
        // the lot the sale below consumes.
        $bill = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-08-01',
        ]);
        $bill->lines()->create([
            'product_id' => $product->id,
            'description' => 'Restock',
            'quantity' => 4,
            'unit_price' => 300,
            'line_total' => 1200,
        ]);
        $bill->forceFill(['subtotal' => 1200, 'tax_amount' => 0, 'total' => 1200])->save();
        $this->service->issue($bill);

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-08-10',
        ]);
        $invoice->lines()->create([
            'product_id' => $product->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
        ]);
        $invoice->forceFill(['subtotal' => 500, 'tax_amount' => 0, 'total' => 500])->save();
        $this->service->issue($invoice);

        $movementsBefore = $product->movements()->count();

        $note = $this->service->creditNote($invoice->refresh(), 'Faulty, scrapped rather than returned');
        $this->service->issue($note);

        $this->assertSame($movementsBefore, $product->movements()->count(), 'The credit note moved stock.');
        $this->assertSame(0.0, $this->movement($note->refresh(), '5050'), 'The credit note reversed COGS.');

        // The receivable and the revenue still net out — only the cost stays charged.
        $this->assertSame(0.0, round($this->movement($invoice, '1250') + $this->movement($note, '1250'), 2));
    }

    // ------------------------------------------------------------- the reading

    /**
     * Aged receivables subtract a credit note. Leaving it out would overstate what every
     * customer owes by the credits outstanding.
     */
    public function test_aged_receivables_subtract_an_outstanding_credit_note(): void
    {
        $invoice = $this->issued([[1000.0]]);

        $before = $this->service->outstandingReceivables();
        $this->assertSame(1000.0, $before['total']);

        $note = $this->service->creditNote($invoice, 'Credited in full');
        $this->service->issue($note);

        $after = $this->service->outstandingReceivables();

        $this->assertSame(0.0, $after['total'], 'A fully credited invoice still shows as owed.');

        $line = collect($after['invoices'])->firstWhere('invoice_number', $note->refresh()->invoice_number);
        $this->assertSame(-1000.0, $line['outstanding']);
    }

    /** Payables are untouched: a credit note here is always ours against a customer. */
    public function test_aged_payables_do_not_pick_up_credit_notes(): void
    {
        $invoice = $this->issued([[1000.0]]);
        $this->service->issue($this->service->creditNote($invoice, 'Credited'));

        $this->assertSame(0.0, $this->service->outstandingPayables()['total']);
        $this->assertSame([], $this->service->outstandingPayables()['invoices']);
    }

    // ---------------------------------------------- the rule 22 window (§9.7)

    /**
     * The 180-day adjustment window, and the distinction it turns on.
     *
     * Plan open question 7 asked whether a credit note is sufficient after 72 hours or whether
     * Commissioner approval is needed even for that. The question conflated two rules:
     *
     *  - **72 hours**, STGO 01 of 2026, about amending or cancelling the *e-invoice*. Refused
     *    here, always — see the first test in this file.
     *  - **180 days**, section 9 with rules 20–22, about the *credit note*, which is not an
     *    amendment but a second document adjusting the tax.
     *
     * So a credit note needs no approval — until day 180. These tests are that boundary.
     */
    public function test_a_credit_note_inside_the_adjustment_window_needs_no_approval(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        // Reported, and well past the 72 hours: the case the whole feature exists for.
        $invoice = $this->reportedAt($this->issued(), now()->subHours(100));

        $note = $this->service->creditNote($invoice, 'Goods returned');

        $this->assertSame('1000.00', $note->total);
        $this->assertNull($note->commissioner_approval_ref, 'An approval was recorded where none was needed.');
    }

    public function test_past_180_days_it_refuses_without_a_commissioner_extension(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $invoice = $this->issued([[1000.0]], ['invoice_date' => now()->subDays(200)->toDateString()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no longer adjusts output tax/');

        $this->service->creditNote($invoice, 'Discovered late');
    }

    /** With the extension recorded, the credit note is raised — and carries the evidence. */
    public function test_a_recorded_commissioner_extension_allows_it_and_is_stamped_on_the_note(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $invoice = $this->issued([[1000.0]], ['invoice_date' => now()->subDays(200)->toDateString()]);

        $note = $this->service->creditNote($invoice, 'Discovered late', null, [
            'ref' => 'CIR/EXT/2026/4471',
            'granted_on' => now()->subDays(3)->toDateString(),
        ]);

        $this->assertSame('CIR/EXT/2026/4471', $note->commissioner_approval_ref);
        $this->assertSame(now()->subDays(3)->toDateString(), $note->commissioner_approved_on->toDateString());
        $this->assertTrue($note->hasCommissionerApproval());
    }

    /**
     * Rule 22's proviso allows one further period, not a renewable one. Past that, accepting a
     * reference would be storing evidence for an inadmissible adjustment — which is worse than
     * refusing, because it looks like it was checked.
     */
    public function test_past_the_extended_period_even_an_extension_is_refused(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $invoice = $this->issued([[1000.0]], ['invoice_date' => now()->subDays(400)->toDateString()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/past even the extended adjustment period/');

        $this->service->creditNote($invoice, 'Very late', null, ['ref' => 'CIR/EXT/2026/9999']);
    }

    /**
     * The compatibility guarantee, and the reason the check is gated at all.
     *
     * Rule 22 binds a sales-tax-registered person making taxable supplies. A company below the
     * threshold, not reporting, must not be refused an ordinary correction by a rule that does
     * not reach it — the same reasoning as `FbrReconciliation::unreported()` returning nothing
     * when reporting is off.
     */
    public function test_a_company_that_does_not_report_is_not_held_to_the_180_days(): void
    {
        // fbr.enabled left off, which is the default.
        $invoice = $this->issued([[1000.0]], ['invoice_date' => now()->subDays(400)->toDateString()]);

        $note = $this->service->creditNote($invoice, 'Two years of tidying up');

        $this->assertSame('1000.00', $note->total);
    }

    /**
     * Counted from the supply, not from when it was reported.
     *
     * Rule 22 is about the supply. Running the clock from `fbr_reported_at` would quietly give
     * more time to exactly the companies that were slow to report.
     */
    public function test_the_window_runs_from_the_invoice_date_not_the_reporting_date(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $invoice = $this->issued([[1000.0]], ['invoice_date' => now()->subDays(200)->toDateString()]);
        // Reported only yesterday — late, which must not buy more time.
        $this->reportedAt($invoice, now()->subDay());

        $this->assertFalse($invoice->creditNoteWindowOpen());
        $this->assertSame(
            now()->subDays(200)->addDays(180)->toDateString(),
            $invoice->creditNoteDeadline()->toDateString()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->service->creditNote($invoice, 'Late report, still late');
    }

    /** The window is a setting, because rule 22's figure is set by notification. */
    public function test_the_adjustment_period_is_configurable(): void
    {
        $settings = app(TenantSettings::class);
        $settings->set('fbr.enabled', true);
        $settings->set('fbr.credit_note_days', 30);

        $invoice = $this->issued([[1000.0]], ['invoice_date' => now()->subDays(45)->toDateString()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/more than 30 days ago/');

        $this->service->creditNote($invoice, 'Past the shortened window');
    }

    /**
     * The printed document says what it is.
     *
     * A credit note titled "Invoice" is a document that lies about itself: the customer files
     * it as a bill and pays it. Rendered through the real Blade view rather than asserted on
     * the model, because the title is a template decision and nothing else would catch it.
     */
    public function test_the_printed_credit_note_is_titled_as_one_and_asks_for_no_money(): void
    {
        $invoice = $this->issued();
        $note = $this->service->creditNote($invoice, 'Billed twice');
        $this->service->issue($note);

        $html = view('pdfs.invoice', [
            'invoice' => $note->refresh()->load(['contact', 'lines.product', 'creditedInvoice']),
        ])->render();

        $this->assertStringContainsString('Credit Note '.$note->invoice_number, $html);
        $this->assertStringNotContainsString('Invoice '.$note->invoice_number, $html);

        // Where it came from, and why.
        $this->assertStringContainsString($invoice->invoice_number, $html);
        $this->assertStringContainsString('Billed twice', $html);

        // "Outstanding 1,000.00" on a credit note reads as a demand for money on a document
        // that is the opposite of one.
        $this->assertStringNotContainsString('Outstanding', $html);
        $this->assertStringContainsString('Total credited', $html);

        // And an ordinary invoice is unchanged by all of that.
        $invoiceHtml = view('pdfs.invoice', [
            'invoice' => $invoice->load(['contact', 'lines.product', 'creditedInvoice']),
        ])->render();

        $this->assertStringContainsString('Invoice '.$invoice->invoice_number, $invoiceHtml);
        $this->assertStringContainsString('Outstanding', $invoiceHtml);
    }

    /**
     * An issued credit note that has not been transmitted is a compliance gap of exactly the
     * kind this list exists to surface: FBR still holds the original figure, so their record
     * and the ledger disagree and the return will not tie out.
     */
    public function test_fbr_reconciliation_lists_an_unreported_credit_note(): void
    {
        app(TenantSettings::class)->set('fbr.enabled', true);

        $invoice = $this->reportedAt($this->issued(), now()->subHours(100));

        $note = $this->service->creditNote($invoice, 'Past the window');
        $this->service->issue($note);

        $unreported = app(FbrReconciliation::class)->unreported();

        $this->assertTrue(
            $unreported->contains(fn (Invoice $i): bool => $i->getKey() === $note->getKey()),
            'An issued but unreported credit note is not listed as a gap.'
        );

        // The invoice itself is reported, so it is not in the list — otherwise the credit
        // note could appear merely because everything does.
        $this->assertFalse($unreported->contains(fn (Invoice $i): bool => $i->getKey() === $invoice->getKey()));
    }
}
