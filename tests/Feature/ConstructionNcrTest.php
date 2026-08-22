<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionContracts\Services\NcrDeductionOffer;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Pages\ListNcrs;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\ConstructionQhse\Services\InspectionService;
use App\Modules\ConstructionQhse\Services\ItpService;
use App\Modules\ConstructionQhse\Services\NcrService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Non-conformance, CAPA and close-out — §17.2, Phase 10b.
 *
 * **The property this file exists to prove is a negative one: an NCR never deducts.** §17.2 spends a paragraph on it —
 * "it *proposes*; the certification service **offers** the deduction as a row on the certificate that a human confirms
 * and signs for. FIDIC 14.6 permits the Engineer to withhold; it does not require it. A deduction appearing on a
 * certificate that nobody decided on is the fastest available route to a dispute, and it will be the contractor's
 * dispute, because the client's copy has already left the building."
 *
 * So the tests below assert that proposing writes no deduction row anywhere, that the row appears only when somebody
 * takes the offer up, that it is marked `is_automatic = false` with their name on it, and that
 * `deduction_certificate_id` — the column recording a human decided — is written on the *certification* side and never
 * by the quality module.
 *
 * Three more properties:
 *
 *  - **A disposition is chosen, never defaulted**, because it is "the field that decides whether money changes hands".
 *  - **A closure needs a re-inspection that passed, and not the one that failed.** Close-out points at the
 *    re-inspection, which is what makes a closure evidence rather than an assertion.
 *  - **CAPA is two pairs**, and the register can say when the pour was fixed and the reason it happened was not.
 */
class ConstructionNcrTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private NcrService $ncrs;

    private InspectionService $inspections;

    private ItpService $plans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'ncr@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_qhse', 'construction_contracts', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->ncrs = app(NcrService::class);
        $this->inspections = app(InspectionService::class);
        $this->plans = app(ItpService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function ncr(array $attributes = []): Ncr
    {
        return $this->ncrs->raise($this->job, array_merge([
            'description' => 'Cover to reinforcement 22 mm against 40 mm specified',
            'requirement_breached' => 'BS EN 1992 clause 4.4.1',
            'severity' => Ncr::SEVERITY_MAJOR,
            'responsible_label' => 'Concrete Co',
        ], $attributes));
    }

    private ?ItpActivity $point = null;

    /**
     * A hold point in force, so inspections can be raised against it.
     *
     * Memoised: the ITP reference is unique per job, so drafting a second plan with the same reference is a constraint
     * violation rather than a second plan — which is the register working.
     */
    private function holdPoint(): ItpActivity
    {
        if ($this->point !== null) {
            return $this->point;
        }

        $itp = $this->plans->draft($this->job, ['reference' => 'ITP-CIV-001', 'title' => 'In-situ concrete']);
        $point = $this->plans->addActivity($itp, [
            'activity_description' => 'Reinforcement prior to pour',
            'point_type' => ItpActivity::POINT_HOLD,
            'notice_hours' => 24,
        ]);
        $this->plans->addParty($point, ['party' => 'engineer', 'role' => 'witnesses']);
        $this->plans->issue($itp->refresh());

        return $this->point = $point->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function inspection(string $status, array $attributes = []): Inspection
    {
        return $this->inspections->record(
            $this->inspections->requestAgainst($this->holdPoint(), $attributes),
            ['status' => $status, 'inspected_on' => '2026-08-22'],
        );
    }

    private function contract(): Contract
    {
        $contracts = app(ContractService::class);
        $contract = $contracts->create($this->job, [
            'title' => 'Main works',
            'contract_sum' => 10_000_000,
            'retention_percent' => 5,
            'retention_limit_percent' => 100,
        ]);
        $contracts->addItem($contract, [
            'item_no' => '1', 'description' => 'The works', 'scheduled_value' => 10_000_000,
        ]);

        return $contracts->execute($contract)->refresh();
    }

    // ------------------------------------------------------------------ raising

    public function test_an_ncr_is_numbered_per_job_and_needs_describing(): void
    {
        $this->assertSame('NCR-1', $this->ncr()->ncr_number);
        $this->assertSame('NCR-2', $this->ncr(['description' => 'Honeycombing to column face'])->ncr_number);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs describing');

        $this->ncr(['description' => '  ']);
    }

    /**
     * **A disposition is chosen, never defaulted** — and a form that posted one with the raise does not settle it.
     *
     * §17.2 calls it "the field that decides whether money changes hands", so a default of `rework` would answer the
     * commercially significant question on every new row before anybody had looked at the work.
     */
    public function test_a_new_ncr_has_no_disposition_even_if_one_is_posted(): void
    {
        $ncr = $this->ncr(['disposition' => Ncr::DISPOSITION_REWORK, 'status' => Ncr::STATUS_CLOSED]);

        $this->assertNull($ncr->disposition);
        $this->assertFalse($ncr->isDispositioned());
        $this->assertSame(Ncr::STATUS_OPEN, $ncr->status);
        $this->assertCount(1, $this->ncrs->awaitingDisposition($this->job));
    }

    /**
     * **Raised from a failed inspection, carrying the ITP row across** — §17.2's traceability the standard asks for.
     */
    public function test_an_ncr_raised_from_an_inspection_carries_the_traceability(): void
    {
        $failed = $this->inspection(Inspection::STATUS_FAILED);

        $ncr = $this->ncrs->raiseFromInspection($failed, ['severity' => Ncr::SEVERITY_MAJOR]);

        $this->assertSame($failed->getKey(), $ncr->inspection_id);
        $this->assertSame($failed->itp_activity_id, $ncr->itp_activity_id);
        $this->assertNotNull($ncr->itp_activity_id, 'the ITP row travelled');
        $this->assertStringContainsString('Failed inspection', $ncr->description);
        // And the inspection points back, so the register can say which failures produced an NCR.
        $this->assertSame($ncr->getKey(), $failed->refresh()->ncr_id);
    }

    /** An NCR whose own evidence contradicts it is refused. */
    public function test_an_ncr_cannot_be_raised_from_an_inspection_that_passed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('own evidence contradicts it');

        $this->ncrs->raiseFromInspection($this->inspection(Inspection::STATUS_PASSED));
    }

    // ------------------------------------------------------------------ disposition

    public function test_dispositioning_records_who_and_when(): void
    {
        $ncr = $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_REWORK, null, '2026-08-23');

        $this->assertSame(Ncr::DISPOSITION_REWORK, $ncr->disposition);
        $this->assertSame(Ncr::STATUS_DISPOSITIONED, $ncr->status);
        $this->assertSame('2026-08-23', $ncr->dispositioned_on->toDateString());
        $this->assertNotNull($ncr->dispositioned_by);
        $this->assertFalse($ncr->acceptsNonconformingWork());
    }

    /** **A concession needs its reference** — how a job avoids an as-built nobody can defend. */
    public function test_a_concession_needs_its_reference(): void
    {
        try {
            $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_CONCESSION);
            $this->fail('A concession with no reference should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('as-built nobody can defend', $e->getMessage());
        }

        $ncr = $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_CONCESSION, 'Client letter 2026-08-24');

        $this->assertSame('Client letter 2026-08-24', $ncr->concession_reference);
        $this->assertTrue($ncr->acceptsNonconformingWork());
    }

    public function test_an_unknown_disposition_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("not one of ISO 9001's dispositions");

        $this->ncrs->disposition($this->ncr(), 'ignore_it');
    }

    /**
     * **Accepted nonconforming work with nothing proposed** — a concession given away for free.
     *
     * The client took less than the specification and got nothing for it, and no report anywhere else would show that.
     */
    public function test_accepting_nonconforming_work_with_no_reduction_is_reported(): void
    {
        $free = $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_USE_AS_IS);
        $paid = $this->ncrs->disposition($this->ncr(['description' => 'Honeycombing']), Ncr::DISPOSITION_USE_AS_IS);
        $this->ncrs->proposeDeduction($paid, 25_000);
        // Rework is not a concession — nothing is being accepted.
        $this->ncrs->disposition($this->ncr(['description' => 'Chip and patch']), Ncr::DISPOSITION_REWORK);

        $exposed = $this->ncrs->acceptedWithoutReduction($this->job);

        $this->assertCount(1, $exposed);
        $this->assertSame($free->getKey(), $exposed->first()->getKey());
        $this->assertTrue($free->refresh()->acceptedWithoutReduction());
    }

    // ------------------------------------------------------------------ CAPA

    /** **Two pairs of fields**, and the register can say when only one of them was done. */
    public function test_corrective_and_preventive_action_are_recorded_separately(): void
    {
        $ncr = $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_REWORK);

        $ncr = $this->ncrs->recordCapa($ncr, [
            'root_cause' => 'Spacers omitted at the east end.',
            'root_cause_method' => 'Five whys',
            'corrective_action' => 'Break out and recast the affected metre.',
            'corrective_owner_label' => 'Site agent',
            'corrective_due_on' => '2026-08-30',
            'corrective_done_on' => '2026-08-28',
            'preventive_action' => 'Spacer check added to the pre-pour checklist.',
            'preventive_owner_label' => 'QA manager',
            'preventive_due_on' => '2026-09-15',
        ]);

        $this->assertSame(Ncr::STATUS_ACTION_TAKEN, $ncr->status, 'the corrective action being done moves it on');
        $this->assertSame('Five whys', $ncr->root_cause_method);

        // The commonest CAPA failure: the pour fixed, the reason it happened not.
        $this->assertTrue($ncr->fixedButNotPrevented());
        $this->assertCount(1, $this->ncrs->fixedButNotPrevented($this->job));

        $ncr = $this->ncrs->recordCapa($ncr, ['preventive_done_on' => '2026-09-10']);

        $this->assertFalse($ncr->fixedButNotPrevented());
        $this->assertCount(0, $this->ncrs->fixedButNotPrevented($this->job));
    }

    public function test_overdue_corrective_and_preventive_action_appear_in_one_list(): void
    {
        $corrective = $this->ncrs->recordCapa($this->ncr(), ['corrective_due_on' => '2026-08-20']);
        $preventive = $this->ncrs->recordCapa($this->ncr(['description' => 'Honeycombing']), [
            'preventive_action' => 'Vibration training', 'preventive_due_on' => '2026-08-20',
        ]);
        $this->ncrs->recordCapa($this->ncr(['description' => 'Chip']), ['corrective_due_on' => '2027-01-01']);

        $overdue = $this->ncrs->overdueActions($this->job, '2026-08-25');

        $this->assertCount(2, $overdue);
        $this->assertTrue($corrective->refresh()->correctiveOverdue('2026-08-25'));
        $this->assertTrue($preventive->refresh()->preventiveOverdue('2026-08-25'));
    }

    // ------------------------------------------------------------------ close-out

    /**
     * **A closure needs a re-inspection that passed — and not the one that failed.**
     *
     * §17.2: close-out points at the re-inspection, "which is what makes a closure evidence rather than an assertion".
     */
    public function test_closing_needs_a_verification_against_a_different_passing_inspection(): void
    {
        $failed = $this->inspection(Inspection::STATUS_FAILED);
        $ncr = $this->ncrs->raiseFromInspection($failed);

        try {
            $this->ncrs->close($ncr);
            $this->fail('An unverified NCR should not close.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('evidence rather than an assertion', $e->getMessage());
        }

        // Verifying against the failure that raised it proves the opposite of what it claims.
        try {
            $this->ncrs->verify($ncr, $failed);
            $this->fail('Verifying against its own failure should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('proves the opposite', $e->getMessage());
        }

        // Nor against a re-inspection that also failed.
        $stillWrong = $this->inspection(Inspection::STATUS_FAILED);

        try {
            $this->ncrs->verify($ncr, $stillWrong);
            $this->fail('Verifying against a failed re-inspection should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not right yet', $e->getMessage());
        }

        $reinspection = $this->inspection(Inspection::STATUS_PASSED);

        $ncr = $this->ncrs->verify($ncr, $reinspection);

        $this->assertTrue($ncr->isVerified());
        $this->assertSame($reinspection->getKey(), $ncr->verification_inspection_id);
        $this->assertSame(Ncr::STATUS_VERIFIED, $ncr->status);
        $this->assertNotNull($ncr->verified_by);

        $closed = $this->ncrs->close($ncr, '2026-08-30');

        $this->assertSame(Ncr::STATUS_CLOSED, $closed->status);
        $this->assertSame('2026-08-30', $closed->closed_on->toDateString());
    }

    /** Voiding keeps the number and the reason — the same discipline as §16.2's cancelled RFI. */
    public function test_voiding_keeps_the_number_and_needs_a_reason(): void
    {
        try {
            $this->ncrs->void($this->ncr(), '   ');
            $this->fail('A void with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('raised again next month', $e->getMessage());
        }

        $ncr = $this->ncrs->void($this->ncr(['description' => 'Gap at skirting']), 'Specified as an open joint.');

        $this->assertSame(Ncr::STATUS_VOID, $ncr->status);
        $this->assertSame('Specified as an open joint.', $ncr->void_reason);
        $this->assertSame('NCR-3', $this->ncrs->nextNumber($this->job), 'both numbers stay used');
    }

    public function test_a_closed_ncr_takes_no_further_changes(): void
    {
        $failed = $this->inspection(Inspection::STATUS_FAILED);
        $ncr = $this->ncrs->raiseFromInspection($failed);
        $this->ncrs->verify($ncr, $this->inspection(Inspection::STATUS_PASSED));
        $ncr = $this->ncrs->close($ncr->refresh());

        foreach ([
            fn () => $this->ncrs->update($ncr, ['description' => 'Something else']),
            fn () => $this->ncrs->disposition($ncr, Ncr::DISPOSITION_REWORK),
            fn () => $this->ncrs->recordCapa($ncr, ['root_cause' => 'Anything']),
            fn () => $this->ncrs->close($ncr),
            fn () => $this->ncrs->void($ncr, 'Changed my mind.'),
            fn () => $this->ncrs->proposeDeduction($ncr, 1_000),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A closed NCR should be settled.');
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/closed|nothing left to withhold/', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ the deduction proposes and never applies

    /**
     * **Proposing writes no deduction anywhere.** The core assertion of this whole sub-phase.
     */
    public function test_proposing_a_deduction_withholds_nothing(): void
    {
        $contract = $this->contract();
        $ncr = $this->ncrs->disposition($this->ncr(['contract_id' => $contract->getKey()]), Ncr::DISPOSITION_USE_AS_IS);

        $ncr = $this->ncrs->proposeDeduction($ncr, 40_000, 'Agreed reduction for reduced cover.');

        $this->assertTrue($ncr->deduct_from_payment);
        $this->assertSame(40_000.0, (float) $ncr->deduction_amount);
        $this->assertTrue($ncr->proposesDeduction());
        $this->assertFalse($ncr->deductionWasTaken());
        $this->assertNull($ncr->deduction_certificate_id);

        // Nothing anywhere in the certification tables.
        $this->assertSame(0, CertificateDeduction::query()->count());
    }

    public function test_a_proposal_needs_a_figure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a figure');

        $this->ncrs->proposeDeduction($this->ncr(), 0);
    }

    public function test_a_proposal_can_be_withdrawn_while_no_certificate_has_taken_it(): void
    {
        $ncr = $this->ncrs->proposeDeduction($this->ncr(), 40_000);

        $ncr = $this->ncrs->withdrawDeduction($ncr);

        $this->assertFalse($ncr->deduct_from_payment);
        $this->assertNull($ncr->deduction_amount);
        $this->assertCount(0, $this->ncrs->proposedDeductions($this->job));
    }

    /** The offer is a read: asking for it changes nothing. */
    public function test_the_offer_list_is_a_read(): void
    {
        $contract = $this->contract();
        $ncr = $this->ncrs->proposeDeduction(
            $this->ncr(['contract_id' => $contract->getKey()]),
            40_000,
        );

        $offers = app(NcrDeductionOffer::class);

        $this->assertCount(1, $offers->offersFor($contract));
        $this->assertSame(40_000.0, $offers->totalOffered($contract));
        $this->assertSame(0, CertificateDeduction::query()->count());
        $this->assertNull($ncr->refresh()->deduction_certificate_id);
    }

    /**
     * **Taking the offer up writes the row, marks it as somebody's decision, and records which certificate took it.**
     *
     * `is_automatic = false` with `approved_by` filled in is §17.2 in two columns: this is not a figure the certificate
     * worked out.
     */
    public function test_taking_an_offer_writes_a_human_decision_and_records_it(): void
    {
        $contract = $this->contract();
        $ncr = $this->ncrs->proposeDeduction(
            $this->ncrs->disposition($this->ncr(['contract_id' => $contract->getKey()]), Ncr::DISPOSITION_USE_AS_IS),
            40_000,
        );

        $certificate = app(CertificationService::class)->prepare($contract, '2026-08-31');

        $deduction = app(NcrDeductionOffer::class)->take($certificate, $ncr, 40_000, 'As agreed at the meeting.');

        $this->assertSame(CertificateDeduction::KIND_NCR, $deduction->kind);
        $this->assertSame(-40_000.0, (float) $deduction->amount);
        $this->assertFalse($deduction->is_automatic, 'a human decided this');
        $this->assertNotNull($deduction->approved_by);
        // The morph *alias*, not the class name — `tests/alias-lock.json` is what keeps this stable across a rename,
        // and Phase 9g's ProgrammeActivity rename is why that matters.
        $this->assertSame('App\\Models\\Ncr', $deduction->source_type);
        $this->assertSame($ncr->getKey(), (int) $deduction->source_id);
        $this->assertStringContainsString('NCR-1', $deduction->description);

        // And the NCR records that a human took the proposal up — written on the certification side.
        $this->assertSame($certificate->getKey(), (int) $ncr->refresh()->deduction_certificate_id);
        $this->assertTrue($ncr->deductionWasTaken());
        $this->assertFalse($ncr->proposesDeduction(), 'it is off the offer list');
        $this->assertCount(0, app(NcrDeductionOffer::class)->offersFor($contract->refresh()));
    }

    /**
     * **Withholding less than was proposed is the ordinary outcome**, so the amount is a decision rather than a copy.
     */
    public function test_less_may_be_withheld_than_was_proposed_but_never_more(): void
    {
        $contract = $this->contract();
        $ncr = $this->ncrs->proposeDeduction($this->ncr(['contract_id' => $contract->getKey()]), 40_000);
        $certificate = app(CertificationService::class)->prepare($contract, '2026-08-31');

        $offers = app(NcrDeductionOffer::class);

        try {
            $offers->take($certificate, $ncr, 60_000);
            $this->fail('More than proposed should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('more than the NCR proposed', $e->getMessage());
        }

        $deduction = $offers->take($certificate, $ncr, 15_000, 'Negotiated down.');

        $this->assertSame(-15_000.0, (float) $deduction->amount);
    }

    public function test_an_issued_certificate_takes_no_new_deduction(): void
    {
        $contract = $this->contract();
        $ncr = $this->ncrs->proposeDeduction($this->ncr(['contract_id' => $contract->getKey()]), 40_000);

        $certification = app(CertificationService::class);
        $certificate = $certification->prepare($contract, '2026-08-31');
        $certification->issue($certificate->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the client has a copy');

        app(NcrDeductionOffer::class)->take($certificate->refresh(), $ncr);
    }

    public function test_an_ncr_not_proposing_anything_cannot_be_taken(): void
    {
        $contract = $this->contract();
        $ncr = $this->ncr(['contract_id' => $contract->getKey()]);
        $certificate = app(CertificationService::class)->prepare($contract, '2026-08-31');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not proposing a deduction');

        app(NcrDeductionOffer::class)->take($certificate, $ncr);
    }

    /** A proposal under another contract on the same job is not this certificate's business. */
    public function test_offers_are_scoped_to_the_contract(): void
    {
        $first = $this->contract();
        $second = $this->contract();

        $this->ncrs->proposeDeduction($this->ncr(['contract_id' => $second->getKey()]), 40_000);
        // No contract at all: a job-level nonconformity, offered on any certificate for that job.
        $this->ncrs->proposeDeduction($this->ncr(['description' => 'Job-wide']), 5_000);

        $offers = app(NcrDeductionOffer::class);

        $this->assertCount(1, $offers->offersFor($first), 'only the unattributed one');
        $this->assertCount(2, $offers->offersFor($second));
    }

    /**
     * **Without the quality module a certificate is exactly what it was before Phase 10b.**
     *
     * §18.1's graceful half: no offers, and `take()` refuses in one sentence. An NCR never deducted anything by itself,
     * so there is nothing for the absence to switch off.
     */
    public function test_a_certificate_has_no_offers_without_the_quality_module(): void
    {
        $contract = $this->contract();
        $this->ncrs->proposeDeduction($this->ncr(['contract_id' => $contract->getKey()]), 40_000);

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'construction_qhse')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $offers = app(NcrDeductionOffer::class);

        $this->assertFalse($offers->isAvailable());
        $this->assertCount(0, $offers->offersFor($contract));
        $this->assertSame(0.0, $offers->totalOffered($contract));
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_proposed_rather_than_deducted(): void
    {
        $ncr = $this->ncrs->proposeDeduction(
            $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_USE_AS_IS),
            40_000,
        );

        Livewire::test(ListNcrs::class)
            ->assertCanSeeTableRecords([$ncr])
            ->assertSee('NCR-1')
            ->assertSee('40,000.00 proposed')
            ->assertSee('Use as is');
    }

    public function test_the_register_names_a_concession_given_away_for_nothing(): void
    {
        $ncr = $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_USE_AS_IS);

        Livewire::test(ListNcrs::class)
            ->assertCanSeeTableRecords([$ncr])
            ->assertSee('accepted, nothing proposed');
    }

    public function test_the_screen_dispositions_and_then_proposes(): void
    {
        $ncr = $this->ncr();

        Livewire::test(ListNcrs::class)
            ->callAction(TestAction::make('disposition')->table($ncr), [
                'disposition' => Ncr::DISPOSITION_REPAIR,
                'on' => '2026-08-23',
            ]);

        $this->assertSame(Ncr::DISPOSITION_REPAIR, $ncr->refresh()->disposition);

        Livewire::test(ListNcrs::class)
            ->callAction(TestAction::make('proposeDeduction')->table($ncr), [
                'amount' => 12_500,
                'note' => 'Cost of the repair.',
            ]);

        $ncr->refresh();

        $this->assertTrue($ncr->proposesDeduction());
        $this->assertSame(0, CertificateDeduction::query()->count(), 'still nothing withheld');
    }

    public function test_the_screen_surfaces_the_concession_reference_refusal(): void
    {
        $ncr = $this->ncr();

        Livewire::test(ListNcrs::class)
            ->callAction(TestAction::make('disposition')->table($ncr), [
                'disposition' => Ncr::DISPOSITION_CONCESSION,
            ]);

        $this->assertNull($ncr->refresh()->disposition, 'the refusal held');
    }

    /** The module works with only the spine and itself — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_contracts', 'construction_costing', 'invoicing', 'accounting'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $ncr = $this->ncrs->proposeDeduction(
            $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_USE_AS_IS),
            40_000,
        );

        // The free-text label carries the responsible party, and the proposal sits on the list with nowhere to go.
        $this->assertSame('Concrete Co', $ncr->responsibleName());
        $this->assertCount(1, $this->ncrs->proposedDeductions($this->job));
        $this->assertFalse($ncr->deductionWasTaken());
    }
}
