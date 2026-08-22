<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Pages\ThreeWayMatchReport;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use App\Modules\ConstructionCosting\Services\InvoiceAllocationService;
use App\Modules\ConstructionCosting\Services\ThreeWayMatch;
use App\Modules\ConstructionCosting\Support\MatchTolerances;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TenantSettings;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Ordered against received against invoiced — `docs/construction-management-plan.md` §5, Phase 5e.
 *
 * **The match is computed and only the acceptance is stored**, which §5 states as the design and this file asserts as
 * behaviour: every figure is read from the order, the receipts and the allocations each time, so there is no status to
 * go stale. What is stored is who accepted a variance and why, because "a decision with no record is not a control".
 *
 * Two properties are worth reading twice, because both are places where the obvious implementation is wrong:
 *
 *  - **The price is compared against what was received, not against the whole order.** An invoice for half an order is
 *    a part invoice, not a price variance; comparing it with the order would put every staged delivery on the report.
 *  - **An unstated invoice quantity is null, not zero.** Most supplier invoices state money and not tonnes; reading
 *    that silence as zero would report every one of them as a total short delivery — §14's lesson about a zero that
 *    means "unknown", one section along.
 *
 * And one correction this file forced, which is worth stating because the first implementation had it wrong:
 * **ordered-against-received is not a variance while the order is open.** Thirty-six tonnes against forty is a short
 * delivery *or* the first of two loads, and nothing in the documents says which — so flagging it made every
 * undelivered order read as a total short delivery and every staged one as a partial. What settles it is somebody
 * closing the order, which §5 already makes an act with an author and a reason; until then the difference is open
 * commitment, reported once by the register that owns it. The report says so in a note rather than silently.
 */
class ConstructionThreeWayMatchTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $material;

    private CommitmentService $commitments;

    private GoodsReceiptService $receipts;

    private InvoiceAllocationService $allocations;

    private ThreeWayMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'match@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->material = CostCode::create([
            'code' => '03.100', 'name' => 'Reinforcement', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);

        $this->commitments = app(CommitmentService::class);
        $this->receipts = app(GoodsReceiptService::class);
        $this->allocations = app(InvoiceAllocationService::class);
        $this->match = app(ThreeWayMatch::class);
    }

    /** An issued order: 40 t at 250,000 — 10,000,000. */
    private function order(float $quantity = 40, float $rate = 250_000): Commitment
    {
        $commitment = $this->commitments->create();

        $this->commitments->addLine($commitment, $this->job, $this->material, [
            'description' => 'Rebar, high tensile, 16mm',
            'quantity' => $quantity,
            'unit_of_measure' => 't',
            'rate' => $rate,
        ]);

        $this->commitments->approve($commitment->refresh());

        return $this->commitments->issue($commitment->refresh());
    }

    private function line(Commitment $commitment): CommitmentLine
    {
        return $commitment->lines()->orderBy('id')->firstOrFail();
    }

    private function receive(Commitment $commitment, float $quantity): void
    {
        $receipt = $this->receipts->create($commitment);
        $this->receipts->addLineFor($receipt, $this->line($commitment), $quantity);
        $this->receipts->post($receipt->refresh());
    }

    private function invoice(Commitment $commitment, float $amount, ?float $quantity = null): Invoice
    {
        $supplier = Contact::firstOrCreate(['name' => 'Steel Supplier Ltd'], ['type' => 'supplier']);

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'status' => Invoice::STATUS_DRAFT,
            'contact_id' => $supplier->getKey(),
            'invoice_date' => '2026-08-25',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create(['description' => 'Rebar', 'quantity' => $quantity ?? 1, 'unit_price' => $amount, 'line_total' => $amount]);

        $this->allocations->allocate($invoice->refresh(), $this->job, $this->material, $amount, [
            'commitment_line_id' => $this->line($commitment)->getKey(),
            'quantity' => $quantity,
        ]);

        return $invoice->refresh();
    }

    // ------------------------------------------------------------------ agreement

    /** All three agreeing is the ordinary case, and it says so rather than appearing on the report. */
    public function test_three_documents_that_agree_are_matched(): void
    {
        $order = $this->order();
        $this->receive($order, 40);
        $this->invoice($order, 10_000_000, 40);

        $match = $this->match->forLine($this->line($order));

        $this->assertSame(ThreeWayMatch::STATUS_MATCHED, $match['status']);
        $this->assertTrue($match['within_tolerance']);
        $this->assertSame(0.0, $match['quantity_variance']);
        $this->assertSame(0.0, $match['price_variance']);
        $this->assertCount(0, $this->match->variances());
    }

    /** An order with nothing against it yet is awaiting delivery, not a variance. */
    public function test_an_untouched_order_is_awaiting_delivery(): void
    {
        $match = $this->match->forLine($this->line($this->order()));

        $this->assertSame(ThreeWayMatch::STATUS_AWAITING, $match['status']);
        $this->assertTrue($match['within_tolerance']);
    }

    /** A part delivery, invoiced for what arrived, is not a variance — it is a staged order. */
    public function test_a_part_delivery_invoiced_for_what_arrived_is_not_a_variance(): void
    {
        $order = $this->order();
        $this->receive($order, 20);
        $this->invoice($order, 5_000_000, 20);

        $match = $this->match->forLine($this->line($order));

        $this->assertSame(0.0, $match['price_variance'], 'compared against what was received, not the whole order');
        $this->assertCount(0, $this->match->variances());
    }

    // ------------------------------------------------------------------ variances

    /**
     * **Billed for more than arrived** — the catch a three-way match exists for.
     *
     * 36 t were delivered and 40 t invoiced. That is not a judgement about whether the order is finished; it is the
     * supplier's own two documents disagreeing with each other.
     */
    public function test_being_billed_for_more_than_arrived_is_a_quantity_variance(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);

        $match = $this->match->forLine($this->line($order));

        $this->assertSame(4.0, $match['quantity_variance'], 'four tonnes billed that never arrived');
        $this->assertFalse($match['within_tolerance']);
        $this->assertCount(1, $this->match->variances());
    }

    /**
     * **A short delivery is not a match variance while the order is open** — and the report says why.
     *
     * The first implementation flagged it, which made every undelivered order read as a total short delivery. Thirty-six
     * tonnes against forty is a short delivery or the first of two loads, and only closing the order settles which.
     */
    public function test_a_short_delivery_reads_as_outstanding_rather_than_as_a_variance(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 9_000_000, 36);

        $match = $this->match->forLine($this->line($order));

        $this->assertSame(0.0, $match['quantity_variance'], 'billed exactly what arrived');
        $this->assertTrue($match['within_tolerance']);
        $this->assertStringContainsString('still outstanding', $match['note']);
        $this->assertStringContainsString('staged one look the same', $match['note']);
        $this->assertCount(0, $this->match->variances());

        // The four tonnes are open commitment, which is where that fact belongs.
        $this->assertSame(1_000_000.0, $this->line($order)->openAmount());
    }

    /**
     * A small over-billing inside tolerance is not worth anybody's time.
     *
     * The figures are chosen to clear **both** thresholds, and choosing them is the interesting part: an over-billing at
     * the agreed rate is the same money seen twice — 0.1 t billed that did not arrive is also 25,000 invoiced above what
     * was delivered. So a fixture that passes the quantity tolerance and fails the price one is not "inside tolerance",
     * it is a price variance, and an earlier version of this test asserted the wrong thing by picking 0.4 t.
     */
    public function test_a_small_over_billing_inside_both_tolerances_is_left_alone(): void
    {
        $order = $this->order(40);
        $this->receive($order, 39.9);
        // 0.1 t over — 25,000, which is 0.25% of the order and 0.25% of what arrived.
        $this->invoice($order, 10_000_000, 40);

        $match = $this->match->forLine($this->line($order));

        $this->assertTrue($match['within_tolerance']);
        $this->assertCount(0, $this->match->variances());
    }

    /**
     * And the same over-billing at a larger size appears — as a price variance too, which is honest about the overlap.
     *
     * Billing for goods that did not arrive is one fact that shows up on both legs: more tonnes than were delivered, and
     * more money than the delivery was worth. The report does not pretend those are separate problems.
     */
    public function test_a_larger_over_billing_shows_on_both_legs(): void
    {
        $order = $this->order(40);
        $this->receive($order, 38);
        // 2 t over — 500,000, past both thresholds.
        $this->invoice($order, 10_000_000, 40);

        $match = $this->match->forLine($this->line($order));

        $this->assertSame(2.0, $match['quantity_variance']);
        $this->assertSame(500_000.0, $match['price_variance']);
        $this->assertSame(ThreeWayMatch::STATUS_BOTH_VARIANCE, $match['status']);
    }

    /** An invoice above what the delivery was ordered at is a price variance. */
    public function test_an_invoice_above_the_order_rate_is_a_price_variance(): void
    {
        $order = $this->order(40, 250_000);
        $this->receive($order, 40);
        // Billed at 260,000 a tonne rather than 250,000.
        $this->invoice($order, 10_400_000, 40);

        $match = $this->match->forLine($this->line($order));

        $this->assertSame(ThreeWayMatch::STATUS_PRICE_VARIANCE, $match['status']);
        $this->assertSame(400_000.0, $match['price_variance']);
        $this->assertFalse($match['within_tolerance']);
    }

    /** Both at once: more tonnes billed than arrived, and at a rate nobody agreed. */
    public function test_a_substitution_shows_as_both_variances(): void
    {
        $order = $this->order(40, 250_000);
        $this->receive($order, 30);
        // 30 t arrived; 34 t billed at 300,000 a tonne.
        $this->invoice($order, 10_200_000, 34);

        $match = $this->match->forLine($this->line($order));

        $this->assertSame(ThreeWayMatch::STATUS_BOTH_VARIANCE, $match['status']);
        $this->assertSame(4.0, $match['quantity_variance']);
        $this->assertSame(2_700_000.0, $match['price_variance'], 'against the 7,500,000 that arrived');
    }

    /**
     * The absolute floor: a percentage alone would flag trivial money on small orders.
     *
     * A control that fires on a 10 difference is one people learn to click through, which costs more than the 10.
     */
    public function test_a_trivial_variance_is_below_the_floor_whatever_the_percentage_says(): void
    {
        // A 500 order: 4 units arrived, 5 billed — 100 of value, under the 1,000 floor.
        $order = $this->order(5, 100);
        $this->receive($order, 4);
        $this->invoice($order, 500, 5);

        $this->assertTrue($this->match->forLine($this->line($order))['within_tolerance']);
        $this->assertCount(0, $this->match->variances());
    }

    // ------------------------------------------------- what is not known

    /**
     * **An unstated invoice quantity is null, not zero.**
     *
     * Most supplier invoices state money and not tonnes. Reading that silence as zero would report every one of them
     * as a total short delivery, and a report where everything is wrong is a report nobody reads.
     */
    public function test_an_invoice_with_no_quantity_says_so_rather_than_reading_as_zero(): void
    {
        $order = $this->order(40);
        $this->receive($order, 40);
        $this->invoice($order, 10_000_000, null);

        $match = $this->match->forLine($this->line($order));

        $this->assertNull($match['invoiced_quantity']);
        $this->assertStringContainsString('no quantity', $match['note']);
        // And the price is still compared, which is the half that can be.
        $this->assertSame(0.0, $match['price_variance']);
        $this->assertTrue($match['within_tolerance']);
    }

    /** A lump-sum order line has no quantity to compare, and the note says that rather than showing zero. */
    public function test_a_lump_sum_line_has_no_quantity_variance(): void
    {
        $commitment = $this->commitments->create();
        $this->commitments->addLine($commitment, $this->job, $this->material, [
            'description' => 'Scaffolding, lump sum',
            'amount' => 2_000_000,
        ]);
        $this->commitments->approve($commitment->refresh());
        $this->commitments->issue($commitment->refresh());

        $match = $this->match->forLine($this->line($commitment));

        $this->assertNull($match['ordered_quantity']);
        $this->assertNull($match['quantity_variance']);
        $this->assertStringContainsString('lump sum', $match['note']);
    }

    // ------------------------------------------------------------------ tolerances

    /** The company's own thresholds win over the shipped defaults. */
    public function test_a_company_can_set_its_own_tolerances(): void
    {
        $this->assertSame(2.0, MatchTolerances::quantityPercent());

        app(TenantSettings::class)->set('construction.match', [
            'quantity_percent' => 10,
            'price_percent' => '',
            'minimum_amount' => 0,
        ]);

        $this->assertSame(10.0, MatchTolerances::quantityPercent());
        // A blank line falls back to the shipped default rather than being read as "tolerate nothing".
        $this->assertSame(1.0, MatchTolerances::pricePercent());
        // A literal zero is honoured: it is a company saying it wants to see everything.
        $this->assertSame(0.0, MatchTolerances::minimumAmount());
    }

    /** With a wider tolerance the same delivery stops being a variance, which is the point of the setting. */
    public function test_a_wider_tolerance_takes_a_line_off_the_report(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);

        $this->assertCount(1, $this->match->variances());

        // Both percentages, because an over-billing at the agreed rate shows on both legs — the same overlap the
        // over-billing tests above make explicit. Widening only the quantity one would leave it on the report as a
        // price variance, which would be the right answer to a different question.
        app(TenantSettings::class)->set('construction.match', [
            'quantity_percent' => 15,
            'price_percent' => 15,
        ]);

        $this->assertCount(0, $this->match->variances());
    }

    // ------------------------------------------------------------------ accepting

    /**
     * **The one thing the match stores**, and the reason is mandatory.
     *
     * §5: "accepting a variance is a human decision, so `match_status = 'accepted'`, who accepted it and the reason
     * *are* stored. A decision with no record is not a control."
     */
    public function test_accepting_a_variance_records_who_and_why(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);

        $line = $this->match->accept(
            $this->line($order),
            'Supplier billed the full load; 4 t went to J-2 and was signed for there on the 14th.',
        );

        $this->assertNotNull($line->variance_accepted_at);
        $this->assertSame(auth()->id(), $line->variance_accepted_by);
        $this->assertStringContainsString('14th', $line->variance_reason);
        $this->assertTrue($line->varianceAccepted());

        // The status is still derived: the acceptance turns whatever the figures say into `accepted`.
        $this->assertSame(ThreeWayMatch::STATUS_ACCEPTED, $this->match->forLine($line)['status']);
        $this->assertCount(0, $this->match->variances(), 'and it leaves the report');
    }

    public function test_accepting_needs_a_reason(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a control');

        $this->match->accept($this->line($order), '   ');
    }

    /** A line inside tolerance has nothing to accept, and saying so keeps the register readable. */
    public function test_a_line_inside_tolerance_cannot_be_accepted(): void
    {
        $order = $this->order();
        $this->receive($order, 40);
        $this->invoice($order, 10_000_000, 40);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already inside tolerance');

        $this->match->accept($this->line($order), 'Looks fine.');
    }

    /** An acceptance can be withdrawn, because one made on the wrong information is a real thing. */
    public function test_an_acceptance_can_be_withdrawn(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);
        $this->match->accept($this->line($order), 'Agreed with the buyer.');

        $line = $this->match->withdrawAcceptance($this->line($order));

        $this->assertNull($line->variance_accepted_at);
        $this->assertNull($line->variance_reason);
        $this->assertCount(1, $this->match->variances(), 'back on the report');
    }

    // ------------------------------------------------------------------ the report

    /** Biggest money first: the question is never "what does PO-142 say", it is "what is worth an argument". */
    public function test_the_report_is_ordered_by_the_money_at_stake(): void
    {
        $small = $this->order(10, 100_000);
        $this->receive($small, 9);
        $this->invoice($small, 1_000_000, 10);

        $large = $this->order(100, 250_000);
        $this->receive($large, 80);
        $this->invoice($large, 25_000_000, 100);

        $variances = $this->match->variances();

        $this->assertCount(2, $variances);
        $this->assertSame(
            $large->refresh()->number,
            $variances->first()['line']->commitment->number,
            'the five-million variance before the hundred-thousand one',
        );
    }

    /** A closed order is off the report: its variances were settled when somebody closed it with a reason. */
    public function test_a_closed_order_is_not_on_the_report(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);

        $this->assertCount(1, $this->match->variances());

        $this->commitments->close($order->refresh(), 'Delivered short and agreed to leave it.');

        $this->assertCount(0, $this->match->variances());
    }

    public function test_the_report_can_be_filtered_to_one_job(): void
    {
        $other = Job::create(['code' => 'J-2', 'name' => 'Annexe']);

        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);

        $this->assertCount(1, $this->match->variances($this->job));
        $this->assertCount(0, $this->match->variances($other));
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_screen_lists_variances_with_the_thresholds_on_it(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);

        Livewire::test(ThreeWayMatchReport::class)
            ->assertSuccessful()
            ->assertSee($order->number)
            // The thresholds are printed: a report whose threshold is invisible is one people argue with.
            ->assertSee('2%')
            ->assertSee('1,000.00');
    }

    /** Empty is a result: ordered, received and invoiced agreeing is the whole point of the control. */
    public function test_the_screen_says_so_when_everything_agrees(): void
    {
        Livewire::test(ThreeWayMatchReport::class)
            ->assertSuccessful()
            ->assertSee('Everything agrees');
    }

    public function test_the_screen_accepts_a_variance(): void
    {
        $order = $this->order(40);
        $this->receive($order, 36);
        $this->invoice($order, 10_000_000, 40);
        $line = $this->line($order);

        Livewire::test(ThreeWayMatchReport::class)
            ->callAction(
                TestAction::make('accept')->arguments(['line' => $line->getKey()]),
                ['reason' => 'Balance cancelled with the buyer.'],
            )
            ->assertHasNoActionErrors();

        $this->assertTrue($line->refresh()->varianceAccepted());
    }
}
