<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages\EditContract;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\RelationManagers\OrdersRelationManager;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaimLine;
use App\Modules\ConstructionContracts\Services\CertificateCommitmentService;
use App\Modules\ConstructionContracts\Services\CertificateInvoiceService;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\InvoiceAllocationService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Models\Contact;
use App\Support\ModuleMap;
use Database\Seeders\ConstructionAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The subcontract certificate relieving its commitment — `docs/construction-management-plan.md` §5, §12, Phase 6c.
 *
 * A subcontract is **two rows and one agreement** (§5's resolution): the contract carries the schedule, the
 * variations, the certificates and the retention; the commitment carries the money promised. Certifying work
 * downward has to discharge the promise, or the four-column report goes on reporting the whole subcontract as
 * committed for the life of the job — and a cost code whose committed money has already been certified and paid
 * reads as a code with no room left in it, which is the number §5 says a site manager actually manages by.
 *
 * Five properties carry this file.
 *
 *  - **The target is cumulative; the row written is the movement.** §8's rule, one module along. A certificate
 *    states a figure to date, so relief drives towards "what do the live certificates say has been certified",
 *    never "what did this certificate add".
 *  - **Which makes void arithmetic fall out rather than be written.** Voiding the latest certificate gives the
 *    commitment back; voiding an earlier one while a later one stands moves nothing — and that second case is the
 *    one an incremental "reverse what this certificate relieved" design gets backwards.
 *  - **Relief follows the cost code the schedule names**, because §8.2 calls `cost_code_id` on a contract item the
 *    join to job cost and the committed column is read per code. What no code can be found for is spread rather
 *    than dropped: leaving it out would keep commitment open against work that is finished and paid for.
 *  - **Relief happens once.** §5's double-relief hazard, from the certificate side: the purchase invoice raised
 *    off the certificate relieves only the unreceived balance, and after certification there is none.
 *  - **Guarded, not required.** Without `construction_costing` a certificate is issued exactly as it was before
 *    this phase, because a contractor certifying subcontractors while keeping cost control elsewhere has no
 *    commitment ledger to relieve (§18).
 */
class ConstructionSubcontractCommitmentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    /** Fabrication, 600,000 of the order and of the schedule. */
    private CostCode $fabrication;

    /** Erection, the other 400,000. */
    private CostCode $erection;

    private Contract $subcontract;

    private CertificationService $certification;

    private CommitmentService $commitments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'subcontract@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'construction_costing', 'accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        // The invoice hand-off of §10.4 needs the construction accounts, which ship with the construction profile
        // rather than with the base chart (§18.2).
        $this->seed(ConstructionAccountsSeeder::class);

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->fabrication = CostCode::create([
            'code' => '05.100', 'name' => 'Structural steel — fabrication', 'cost_type' => CostCode::TYPE_SUBCONTRACT, 'unit' => 't',
        ]);
        $this->erection = CostCode::create([
            'code' => '05.200', 'name' => 'Structural steel — erection', 'cost_type' => CostCode::TYPE_SUBCONTRACT, 'unit' => 't',
        ]);

        $contracts = app(ContractService::class);
        $this->subcontract = $contracts->create($this->job, [
            'side' => Contract::SIDE_PAYABLE,
            'title' => 'Structural steel',
            'contact_id' => Contact::create(['name' => 'Steelwork Ltd', 'type' => 'supplier'])->getKey(),
            'contract_sum' => 1_000_000,
            'retention_percent' => 5,
            'payment_terms_days' => 30,
        ]);

        $contracts->addItem($this->subcontract, [
            'item_no' => '1', 'description' => 'Fabricate', 'scheduled_value' => 600_000,
            'cost_code_id' => $this->fabrication->getKey(),
        ]);
        $contracts->addItem($this->subcontract, [
            'item_no' => '2', 'description' => 'Erect', 'scheduled_value' => 400_000,
            'cost_code_id' => $this->erection->getKey(),
        ]);

        $contracts->execute($this->subcontract);
        $this->subcontract->refresh();

        $this->certification = app(CertificationService::class);
        $this->commitments = app(CommitmentService::class);
    }

    /**
     * The order behind the subcontract: 600,000 of fabrication and 400,000 of erection, issued.
     *
     * Issued rather than approved, because only an issued order puts money on the committed column — an approved
     * order the subcontractor has not been sent can still be withdrawn with a phone call.
     */
    private function order(bool $issue = true, bool $linked = true): Commitment
    {
        $commitment = $this->commitments->create([
            'type' => Commitment::TYPE_SUBCONTRACT,
            'contract_id' => $linked ? $this->subcontract->getKey() : null,
        ]);

        $this->commitments->addLine($commitment, $this->job, $this->fabrication, [
            'description' => 'Fabricate 40 t', 'quantity' => 1, 'rate' => 600_000,
        ]);
        $this->commitments->addLine($commitment, $this->job, $this->erection, [
            'description' => 'Erect 40 t', 'quantity' => 1, 'rate' => 400_000,
        ]);

        if (! $issue) {
            return $commitment->refresh();
        }

        $this->commitments->approve($commitment->refresh());

        return $this->commitments->issue($commitment->refresh());
    }

    /**
     * Certify the schedule at the given cumulative percentages, one per item in schedule order, and issue.
     *
     * Percentages rather than values because that is what a surveyor measures with, and §10.2's rule is that the
     * value is authoritative while the percent is the input — so going in through the claim exercises the
     * resolution rather than assuming it.
     *
     * @param  array<int, float>  $percents
     */
    private function certify(
        array $percents,
        string $periodEnd = '2026-08-31',
        ?string $issuedOn = null,
    ): PaymentCertificate {
        $claim = $this->certification->openClaim($this->subcontract, $periodEnd);

        foreach ($claim->lines()->orderBy('id')->get()->values() as $index => $line) {
            $line->update([
                'measurement_input' => ProgressClaimLine::INPUT_PERCENT,
                'cumulative_percent' => $percents[$index] ?? 0,
            ]);
        }

        $certificate = $this->certification->prepare(
            $this->subcontract,
            $periodEnd,
            $this->certification->submitClaim($claim->refresh()),
        );

        return $this->certification->issue($certificate, $issuedOn);
    }

    private function line(Commitment $order, CostCode $code): CommitmentLine
    {
        return $order->lines()->where('cost_code_id', $code->getKey())->firstOrFail();
    }

    /** Every certificate relief on an order, which is the figure §5 says must be provable. */
    private function certifiedRelief(Commitment $order): float
    {
        return round((float) CommitmentRelief::query()
            ->whereIn('commitment_line_id', $order->lines()->select('id'))
            ->where('kind', CommitmentRelief::KIND_CERTIFICATE)
            ->sum('amount'), 2);
    }

    // ------------------------------------------------------------------ the relief itself

    /**
     * **The exit condition of this sub-phase.** Certify half the subcontract and half the order stops being committed.
     *
     * Gross of retention, and that is the decision rather than an oversight: retention is cash withheld against work
     * that has already been performed, so netting it off would leave a twentieth of every subcontract permanently
     * committed with nothing left able to relieve it, and the order would never close.
     */
    public function test_issuing_a_subcontract_certificate_relieves_the_commitment(): void
    {
        $order = $this->order();

        $this->assertSame(1_000_000.0, $order->openTotal());

        $certificate = $this->certify([50, 50]);

        $this->assertSame(500_000.0, (float) $certificate->gross_value_to_date);
        $this->assertSame(500_000.0, $this->certifiedRelief($order));
        $this->assertSame(500_000.0, $order->refresh()->openTotal());

        // Retention was deducted from the payment and changed nothing about the commitment.
        $this->assertSame(-25_000.0, $certificate->retentionThisPeriod());
    }

    /** The order moves off `issued` as reliefs land, derived from the rows rather than set by whoever wrote one. */
    public function test_a_relieved_order_reports_itself_partially_relieved(): void
    {
        $order = $this->order();

        $this->certify([50, 50]);

        $this->assertSame(Commitment::STATUS_PARTIALLY_RELIEVED, $order->refresh()->status);
    }

    /** Each relief names the certificate that caused it, through the morph alias rather than the class name. */
    public function test_every_relief_names_the_certificate_that_caused_it(): void
    {
        $order = $this->order();
        $certificate = $this->certify([50, 50], '2026-08-31', issuedOn: '2026-09-04');

        $reliefs = CommitmentRelief::query()
            ->whereIn('commitment_line_id', $order->lines()->select('id'))
            ->get();

        $this->assertCount(2, $reliefs);

        foreach ($reliefs as $relief) {
            $this->assertSame(CommitmentRelief::KIND_CERTIFICATE, $relief->kind);
            $this->assertSame($certificate->getKey(), (int) $relief->source_id);
            // The alias, not `App\Modules\...\PaymentCertificate` — §18.2's rule for plain-column morphs.
            $this->assertSame(ModuleMap::alias(PaymentCertificate::class), $relief->source_type);
        }
    }

    /**
     * Dated the day the certificate was **issued**, not the period it valued and not the day somebody typed it.
     *
     * Issue is when the money stops being merely promised: that is the event the certifier signs, and it is what
     * the committed column has to move on. Dating it from the valuation period would restate a month that has
     * already been reported on whenever a certificate is issued late, which is the ordinary case rather than the
     * exception — a certificate valuing August is signed in September more often than not.
     */
    public function test_the_relief_is_dated_from_the_certificate(): void
    {
        $order = $this->order();

        $this->certify([50, 50], periodEnd: '2026-07-31', issuedOn: '2026-08-06');

        $relief = CommitmentRelief::query()
            ->whereIn('commitment_line_id', $order->lines()->select('id'))
            ->firstOrFail();

        $this->assertSame('2026-08-06', $relief->relieved_on->toDateString());
    }

    // ------------------------------------------------------------------ where the money lands

    /**
     * **By cost code, never pro-rata.** All the fabrication certified and none of the erection.
     *
     * Pro-rata would have relieved 360,000 of fabrication and 240,000 of erection — leaving fabrication over-committed
     * by 240,000 and erection under-committed by the same, on one order, with both figures looking healthy. The
     * committed column is read per code, so that is a silent misstatement rather than an approximation.
     */
    public function test_relief_follows_the_cost_code_the_schedule_names(): void
    {
        $order = $this->order();

        $this->certify([100, 0]);

        $this->assertSame(0.0, $this->line($order, $this->fabrication)->openAmount());
        $this->assertSame(400_000.0, $this->line($order, $this->erection)->openAmount());
    }

    /**
     * Certified value the order carries no code for is **spread, not dropped**.
     *
     * A schedule line with no cost code is ordinary — the field is optional, and §8.2 says so while explaining why it
     * is worth filling in. Dropping what cannot be matched would leave commitment open against work that has been
     * certified and paid for, which is worse than approximating where on the order it sits.
     */
    public function test_certified_value_with_no_matching_code_is_spread_rather_than_dropped(): void
    {
        ContractItem::query()
            ->where('contract_id', $this->subcontract->getKey())
            ->where('item_no', '2')
            ->update(['cost_code_id' => null]);

        $order = $this->order();

        // Only the uncoded erection item is certified: 400,000, matched to nothing.
        $this->certify([0, 100]);

        $this->assertSame(400_000.0, $this->certifiedRelief($order));
        // Pro-rata by order value: 60% of it against fabrication, 40% against erection.
        $this->assertSame(240_000.0, $this->line($order, $this->fabrication)->relievedTotal());
        $this->assertSame(160_000.0, $this->line($order, $this->erection)->relievedTotal());
    }

    /**
     * The residue lands where the order still has **room**, which the mixed schedule is the case for.
     *
     * One coded item and one uncoded one, both fully certified. Spreading the uncoded 400,000 by order value would
     * push fabrication to 840,000 against a 600,000 line while leaving erection 240,000 open — an over-relief and an
     * open balance on an order that is in fact wholly certified. Room-based, each line lands on its own value.
     */
    public function test_the_unmatched_residue_lands_where_the_order_still_has_room(): void
    {
        ContractItem::query()
            ->where('contract_id', $this->subcontract->getKey())
            ->where('item_no', '2')
            ->update(['cost_code_id' => null]);

        $order = $this->order();

        $this->certify([100, 100]);

        $this->assertSame(1_000_000.0, $this->certifiedRelief($order));
        $this->assertSame(600_000.0, $this->line($order, $this->fabrication)->relievedTotal());
        $this->assertSame(400_000.0, $this->line($order, $this->erection)->relievedTotal());
        $this->assertFalse($order->refresh()->overRelieved());
    }

    /**
     * **Σ certificate reliefs equals what the certificate says, to the penny.**
     *
     * Phase 5's word is *provable* rather than about right, and pro-rata rounding is where that quietly stops being
     * true — so the crumbs are put back on the largest line. Thirds are the case that exposes it.
     */
    public function test_the_relief_totals_exactly_what_was_certified(): void
    {
        ContractItem::query()
            ->where('contract_id', $this->subcontract->getKey())
            ->update(['cost_code_id' => null]);

        $order = $this->order();
        $certificate = $this->certify([33.333, 33.333]);

        $this->assertSame((float) $certificate->gross_value_to_date, $this->certifiedRelief($order));
    }

    /**
     * **Nothing is capped at the order value.** An order priced below what has been certified over-relieves, visibly.
     *
     * Clamping would make the order read as complete while hiding that it was short — and `overRelieved()` exists
     * because §5 says that state is a question with an answer rather than something to hide.
     */
    public function test_an_order_priced_below_the_certified_value_over_relieves_visibly(): void
    {
        $order = $this->commitments->create([
            'type' => Commitment::TYPE_SUBCONTRACT,
            'contract_id' => $this->subcontract->getKey(),
        ]);
        $this->commitments->addLine($order, $this->job, $this->fabrication, [
            'description' => 'Fabricate — priced short', 'quantity' => 1, 'rate' => 300_000,
        ]);
        $this->commitments->approve($order->refresh());
        $this->commitments->issue($order->refresh());

        $this->certify([100, 0]);

        $this->assertTrue($order->refresh()->overRelieved());
        $this->assertSame(600_000.0, $this->certifiedRelief($order));
        $this->assertSame(0.0, $order->openTotal());
    }

    // ------------------------------------------------------------------ cumulative, and voiding

    /**
     * The second certificate relieves **the movement**, because the figure on it is cumulative.
     *
     * Two rows per line rather than one restated row: what was committed in August stays answerable, which is the
     * discipline the cost ledger keeps with reversals.
     */
    public function test_the_second_certificate_relieves_only_the_movement(): void
    {
        $order = $this->order();

        $this->certify([50, 50], '2026-08-31');
        $this->assertSame(500_000.0, $this->certifiedRelief($order));

        $this->certify([80, 80], '2026-09-30');

        $this->assertSame(800_000.0, $this->certifiedRelief($order));
        $this->assertSame(200_000.0, $order->refresh()->openTotal());
        $this->assertSame(4, CommitmentRelief::query()
            ->whereIn('commitment_line_id', $order->lines()->select('id'))
            ->count());
    }

    /** Voiding the latest certificate gives the commitment back, as a negative movement rather than a deletion. */
    public function test_voiding_the_latest_certificate_returns_the_commitment(): void
    {
        $order = $this->order();
        $certificate = $this->certify([50, 50]);

        $this->certification->void($certificate, 'Measured against the wrong drawing revision.');

        $this->assertSame(0.0, $this->certifiedRelief($order));
        $this->assertSame(1_000_000.0, $order->refresh()->openTotal());

        // Four rows, two of them give-backs. Nothing was deleted.
        $reliefs = CommitmentRelief::query()
            ->whereIn('commitment_line_id', $order->lines()->select('id'))
            ->get();

        $this->assertCount(4, $reliefs);
        $this->assertCount(2, $reliefs->filter(fn (CommitmentRelief $relief): bool => $relief->isReversal()));
    }

    /**
     * **Voiding an earlier certificate while a later one stands moves nothing**, and that is the case worth a test.
     *
     * A design that reversed what each certificate had relieved would give back August's money here, leaving the
     * order committed for work that September's certificate has already certified cumulatively. The cumulative
     * target makes the right answer the only answer.
     */
    public function test_voiding_an_earlier_certificate_leaves_the_commitment_alone(): void
    {
        $order = $this->order();

        $august = $this->certify([50, 50], '2026-08-31');
        $this->certify([80, 80], '2026-09-30');

        $this->certification->void($august, 'Superseded by a re-measure.');

        $this->assertSame(800_000.0, $this->certifiedRelief($order));
        $this->assertSame(200_000.0, $order->refresh()->openTotal());
    }

    /** Every certificate voided is a commitment wholly restored: there is nothing left saying the work was certified. */
    public function test_voiding_every_certificate_restores_the_whole_commitment(): void
    {
        $order = $this->order();

        $august = $this->certify([50, 50], '2026-08-31');
        $september = $this->certify([80, 80], '2026-09-30');

        $this->certification->void($september, 'Issued in error.');
        $this->certification->void($august, 'Issued in error.');

        $this->assertSame(0.0, $this->certifiedRelief($order));
        $this->assertSame(1_000_000.0, $order->refresh()->openTotal());
    }

    // ------------------------------------------------------------------ the committed column

    /** What the four-column report reads: committed per cost code, falling as work is certified. */
    public function test_the_committed_column_falls_as_work_is_certified(): void
    {
        $this->order();

        $this->assertSame(600_000.0, $this->commitments->openFor($this->job, $this->fabrication->getKey()));

        $this->certify([50, 25]);

        $this->assertSame(300_000.0, $this->commitments->openFor($this->job, $this->fabrication->getKey()));
        $this->assertSame(300_000.0, $this->commitments->openFor($this->job, $this->erection->getKey()));
    }

    // ------------------------------------------------------------------ relief happens once (§5)

    /**
     * **§5's double-relief hazard, from the certificate side.**
     *
     * The certificate relieved the order; the purchase invoice raised off that same certificate must not relieve it
     * again. Get it wrong and the committed column reads as though the subcontract had been done twice, which reads
     * as a cost code with room in it that has none.
     */
    public function test_the_invoice_raised_from_the_certificate_does_not_relieve_the_order_twice(): void
    {
        $order = $this->order();
        $certificate = $this->certify([50, 50]);

        $this->assertSame(500_000.0, $this->certifiedRelief($order));

        $invoice = app(CertificateInvoiceService::class)->raise($certificate->refresh());
        $grossLine = $invoice->lines->firstWhere('line_total', 500_000.0);

        $allocations = app(InvoiceAllocationService::class);
        $allocations->allocate($invoice, $this->job, $this->fabrication, 300_000, [
            'invoice_line_id' => $grossLine->getKey(),
            'commitment_line_id' => $this->line($order, $this->fabrication)->getKey(),
        ]);
        $allocations->allocate($invoice, $this->job, $this->erection, 200_000, [
            'invoice_line_id' => $grossLine->getKey(),
            'commitment_line_id' => $this->line($order, $this->erection)->getKey(),
        ]);

        // The cost landed on the job — that is what the invoice is for.
        $this->assertSame(500_000.0, app(CostLedger::class)->totalFor($this->job));

        // And the commitment moved not one rupee further.
        $this->assertSame(500_000.0, $this->certifiedRelief($order));
        $this->assertSame(500_000.0, $order->refresh()->relievedTotal());
        $this->assertSame(0, CommitmentRelief::query()
            ->whereIn('commitment_line_id', $order->lines()->select('id'))
            ->where('kind', CommitmentRelief::KIND_INVOICE)
            ->count());
    }

    // ------------------------------------------------------------------ what is deliberately not relieved

    /**
     * An order that was never issued is not relieved, and that is a real state rather than a miss.
     *
     * A draft or approved order has put nothing on the committed column, so there is nothing for a certificate to
     * discharge — the certified value arrives as actual cost against a code that was never showing it as promised.
     */
    public function test_an_order_that_was_never_issued_is_not_relieved(): void
    {
        $order = $this->order(issue: false);

        $this->certify([50, 50]);

        $this->assertSame(0.0, $this->certifiedRelief($order));
        $this->assertSame(Commitment::STATUS_DRAFT, $order->refresh()->status);
    }

    /**
     * A closed order is left alone, because closing already wrote off the balance with an author and a reason.
     *
     * Relieving it again would double the write-off, and the close-out row is the one that explains the figure.
     */
    public function test_a_closed_order_is_not_relieved_again(): void
    {
        $order = $this->order();
        $this->commitments->close($order->refresh(), 'The subcontractor left site and the balance will not be spent.');

        $this->certify([50, 50]);

        $this->assertSame(0.0, $this->certifiedRelief($order));
        $this->assertSame(1_000_000.0, $order->refresh()->relievedTotal());
    }

    /** A receivable certificate relieves nothing: money coming in from an employer was never committed to anybody. */
    public function test_a_receivable_certificate_relieves_nothing(): void
    {
        $contracts = app(ContractService::class);
        $head = $contracts->create($this->job, [
            'side' => Contract::SIDE_RECEIVABLE,
            'title' => 'Main works',
            'contract_sum' => 5_000_000,
        ]);
        $contracts->addItem($head, [
            'item_no' => '1', 'description' => 'The works', 'scheduled_value' => 5_000_000,
            'cost_code_id' => $this->fabrication->getKey(),
        ]);
        $contracts->execute($head);

        $order = $this->order();

        $this->assertSame([], app(CertificateCommitmentService::class)->syncFor($head->refresh()));
        $this->assertSame(0.0, $this->certifiedRelief($order));
    }

    // ------------------------------------------------------------------ guarded, not required (§18)

    /**
     * Without `construction_costing` a certificate is issued exactly as it was before this phase.
     *
     * §18 sells certification without cost control: a contractor running its commercial department here and its job
     * costing somewhere else has no commitment ledger for a certificate to relieve, and the certificate register is
     * still the whole deliverable.
     */
    public function test_certification_works_without_the_costing_module(): void
    {
        $order = $this->order();

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'construction_costing')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse(app(CertificateCommitmentService::class)->canRelieve());

        $certificate = $this->certify([50, 50]);

        $this->assertTrue($certificate->isIssued());
        $this->assertSame(500_000.0, (float) $certificate->gross_value_to_date);
        $this->assertSame(0.0, $this->certifiedRelief($order));
    }

    // ------------------------------------------------------------------ linking, from the contract's side

    /**
     * Linking an order to a subcontract that has already been certified relieves it **at once**.
     *
     * Not at the next certificate: an order linked half-way through a subcontract would otherwise read as wholly
     * open against work that is finished and paid for, and the next certificate would only relieve its own movement.
     */
    public function test_linking_an_order_relieves_it_to_what_has_already_been_certified(): void
    {
        $unlinked = $this->order(issue: true, linked: false);

        $this->certify([50, 50]);
        $this->assertSame(0.0, $this->certifiedRelief($unlinked));

        $unlinked->update(['contract_id' => $this->subcontract->getKey()]);
        app(CertificateCommitmentService::class)->syncFor($this->subcontract);

        $this->assertSame(500_000.0, $this->certifiedRelief($unlinked));
        $this->assertSame(500_000.0, $unlinked->refresh()->openTotal());
    }

    /** Unlinking gives the certified relief back, because the certificates no longer say anything about that order. */
    public function test_unlinking_an_order_gives_the_certified_relief_back(): void
    {
        $order = $this->order();
        $this->certify([50, 50]);

        $this->commitments->reverseCertificationRelief($order->refresh());
        $order->update(['contract_id' => null]);

        $this->assertSame(0.0, $this->certifiedRelief($order));
        $this->assertSame(1_000_000.0, $order->refresh()->openTotal());
        // Given back, not deleted: the register still says what happened.
        $this->assertSame(4, CommitmentRelief::query()
            ->whereIn('commitment_line_id', $order->lines()->select('id'))
            ->count());
    }

    // ------------------------------------------------------------------ the screen

    /** The tab lists the order with its three figures, which is the screen that answers "what is still promised". */
    public function test_the_orders_tab_shows_what_is_still_committed(): void
    {
        $order = $this->order();
        $this->certify([50, 50]);

        $this->ordersTab()
            ->assertCanSeeTableRecords([$order])
            ->assertSee($order->number);
    }

    private function ordersTab(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $this->subcontract->refresh(),
            'pageClass' => EditContract::class,
        ]);
    }

    /**
     * The link action attaches an order and relieves it in one act, from the contract's screen.
     *
     * The screen is where the coupling direction puts it, so it is worth driving through the action rather than only
     * through the service: this is the only place in the application where a subcontract and its order can be joined.
     */
    public function test_the_link_action_attaches_an_order_and_relieves_it(): void
    {
        $unlinked = $this->order(issue: true, linked: false);

        $this->certify([50, 50]);

        $this->ordersTab()->callAction(
            TestAction::make('link')->table(),
            ['commitment_id' => $unlinked->getKey()],
        );

        $this->assertSame($this->subcontract->getKey(), $unlinked->refresh()->contract_id);
        $this->assertSame(500_000.0, $this->certifiedRelief($unlinked));
    }

    /** And the unlink action gives it back, leaving the order committed again. */
    public function test_the_unlink_action_gives_the_relief_back(): void
    {
        $order = $this->order();
        $this->certify([50, 50]);

        $this->ordersTab()->callAction(TestAction::make('unlink')->table($order));

        $this->assertNull($order->refresh()->contract_id);
        $this->assertSame(0.0, $this->certifiedRelief($order));
        $this->assertSame(1_000_000.0, $order->openTotal());
    }

    /**
     * And it is absent on the receivable side, where there is nothing to show.
     *
     * A per-record decision rather than a per-resource one, which is why it lives in `canViewForRecord()`: one
     * register holds both sides (§8.1), so the same resource has to answer differently for two rows in it.
     */
    public function test_the_orders_tab_is_absent_on_a_head_contract(): void
    {
        $head = app(ContractService::class)->create($this->job, [
            'side' => Contract::SIDE_RECEIVABLE,
            'title' => 'Main works',
            'contract_sum' => 5_000_000,
        ]);

        $this->assertTrue(OrdersRelationManager::canViewForRecord($this->subcontract, EditContract::class));
        $this->assertFalse(OrdersRelationManager::canViewForRecord($head, EditContract::class));
    }

    /** And absent without the cost ledger, for the same reason the relief does nothing without it. */
    public function test_the_orders_tab_is_absent_without_the_costing_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'construction_costing')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse(OrdersRelationManager::canViewForRecord($this->subcontract, EditContract::class));
    }
}
