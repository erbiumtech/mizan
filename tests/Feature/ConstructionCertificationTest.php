<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\ListPaymentCertificates;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Pages\ListProgressClaims;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionContracts\Models\ProgressClaimLine;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionContracts\Services\VariationService;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Claims, certificates and deductions — `docs/construction-management-plan.md` §10, Phase 4c.
 *
 * Four properties carry this file, and each is a way a certificate is wrong while looking right.
 *
 *  - **Cumulative in, movement derived.** A corrected or voided earlier certificate is absorbed by the next one's
 *    movement rather than compounding through every later total.
 *  - **Draft computes, issue freezes.** An issued certificate is a statement of a moment the other party
 *    countersigned, and nothing may restate it — asserted by recompute refusing outright.
 *  - **Only agreed variations reach the certified sum.** `variations_net_to_date` reads `agreed()`, so a
 *    provisionally priced variation stays in the forecast where §9 put it.
 *  - **The previous figures are snapshotted, not joined.** Voiding certificate 1 must not change what
 *    certificate 2 printed in column D.
 */
class ConstructionCertificationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contract $contract;

    private CertificationService $certification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'certification@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $job = Job::create(['code' => 'J-1', 'name' => 'Tower']);

        $contracts = app(ContractService::class);
        $this->contract = $contracts->create($job, [
            'title' => 'Main works',
            'contract_sum' => 10_000_000,
            'retention_percent' => 10,
            'retention_limit_percent' => 5,
            'payment_terms_days' => 56,
        ]);

        // Two lines of 4,000,000 and 6,000,000, so the schedule equals the contract sum and the arithmetic below
        // is readable without a calculator.
        $contracts->addItem($this->contract, [
            'item_no' => '1', 'description' => 'Substructure', 'scheduled_value' => 4_000_000,
        ]);
        $contracts->addItem($this->contract, [
            'item_no' => '2', 'description' => 'Superstructure', 'scheduled_value' => 6_000_000,
        ]);

        $contracts->execute($this->contract);
        $this->contract->refresh();

        $this->certification = app(CertificationService::class);
    }

    /**
     * A submitted claim at the given percentages, one per schedule line in order.
     *
     * @param  array<int, float>  $percentages
     */
    private function claim(string $periodEnd, array $percentages, float $materials = 0): ProgressClaim
    {
        $claim = $this->certification->openClaim($this->contract, $periodEnd);

        foreach ($claim->lines()->orderBy('id')->get()->values() as $index => $line) {
            $line->update([
                'measurement_input' => ProgressClaimLine::INPUT_PERCENT,
                'cumulative_percent' => $percentages[$index] ?? 0,
                'cumulative_materials_value' => $index === 0 ? $materials : 0,
            ]);
        }

        return $this->certification->submitClaim($claim->refresh());
    }

    private function certificate(ProgressClaim $claim): PaymentCertificate
    {
        return $this->certification->prepare($this->contract, $claim->period_end->toDateString(), $claim);
    }

    /**
     * The retention row says what rate it took — and says it correctly.
     *
     * A regression, and the bug is worth naming because it printed on a document a subcontractor signs.
     * The description was built with `rtrim(rtrim((string) $percent, '0'), '.')`, which strips *characters*
     * rather than a decimal fraction: given `10` there is no decimal point to stop it, so it removed the
     * trailing zero and the certificate read **"Retention @ 1%"** against a contract holding ten per cent.
     *
     * It survived because it is invisible from the wrong side. `ConstructionCertificatePrintTest` asserts
     * the label — but hand-writes the deduction row rather than generating it, so it was checking a string
     * it had written itself. Nothing exercised `writeAutomaticDeductions()` on a whole-ten rate until now.
     *
     * The figures are unaffected either way; only the words were wrong. That is precisely why it lasted.
     */
    public function test_the_retention_row_names_the_rate_it_took(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));

        $retention = $certificate->deductions->firstWhere('kind', CertificateDeduction::KIND_RETENTION);

        $this->assertNotNull($retention, 'a 10% contract must produce a retention row');
        $this->assertSame('Retention @ 10%', $retention->description);
        $this->assertStringNotContainsString('@ 1%', $retention->description, 'ten per cent must not print as one');
    }

    // ------------------------------------------------------------------ claims

    public function test_a_claim_is_numbered_and_seeded_with_every_schedule_line(): void
    {
        $claim = $this->certification->openClaim($this->contract, '2026-08-31');

        $this->assertSame('STMT-1', $claim->claim_number);
        $this->assertSame(2, $claim->lines()->count());
        $this->assertSame(ProgressClaim::STATUS_DRAFT, $claim->status);
    }

    /**
     * The next claim starts where the last one finished.
     *
     * A claim is cumulative, so a blank second claim would certify less than the first — which reads as work
     * un-done rather than as an empty form.
     */
    public function test_the_next_claim_is_seeded_from_the_last_one(): void
    {
        $this->claim('2026-08-31', [50, 10]);

        $second = $this->certification->openClaim($this->contract, '2026-09-30');

        $this->assertSame('STMT-2', $second->claim_number);
        $this->assertEquals(2_000_000, $second->lines()->orderBy('id')->first()->cumulative_work_value);
    }

    /** §10.2: whichever input was typed resolves to a value, and the value is what the certificate sums. */
    public function test_each_measurement_input_resolves_to_a_value(): void
    {
        $claim = $this->certification->openClaim($this->contract, '2026-08-31');
        $lines = $claim->lines()->orderBy('id')->get()->values();

        $lines[0]->update([
            'measurement_input' => ProgressClaimLine::INPUT_PERCENT,
            'cumulative_percent' => 25,
        ]);
        $lines[1]->update([
            'measurement_input' => ProgressClaimLine::INPUT_VALUE,
            'cumulative_work_value' => 1_500_000,
        ]);

        $submitted = $this->certification->submitClaim($claim->refresh());

        $this->assertEquals(1_000_000, $submitted->lines()->orderBy('id')->first()->cumulative_work_value);
        $this->assertEquals(2_500_000, $submitted->claimed_work_to_date);
    }

    /** A milestone line is nothing or everything, which is what NEC4 Option A's activity schedule requires. */
    public function test_a_milestone_line_pays_nothing_until_it_is_complete(): void
    {
        $claim = $this->certification->openClaim($this->contract, '2026-08-31');
        $line = $claim->lines()->orderBy('id')->first();

        $line->update([
            'measurement_input' => ProgressClaimLine::INPUT_MILESTONE,
            'cumulative_percent' => 90,
        ]);

        $this->assertSame(0.0, $line->refresh()->resolveWorkValue());

        $line->update(['cumulative_percent' => 100]);

        $this->assertSame(4_000_000.0, $line->refresh()->resolveWorkValue());
    }

    public function test_a_submitted_claim_cannot_be_resubmitted(): void
    {
        $claim = $this->claim('2026-08-31', [50, 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be resubmitted');

        $this->certification->submitClaim($claim->refresh());
    }

    // ------------------------------------------------------------------ certificates

    public function test_a_certificate_snapshots_the_contract_sum_and_the_lines(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));

        $this->assertSame('IPC-1', $certificate->certificate_number);
        $this->assertSame(1, $certificate->sequence);
        $this->assertEquals(10_000_000, $certificate->contract_sum_original);
        $this->assertSame(2, $certificate->lines()->count());
        $this->assertEquals(2_000_000, $certificate->gross_work_to_date);
        $this->assertEquals(2_000_000, $certificate->gross_value_to_date);
    }

    /**
     * The retention movement is this period's; the header figure is cumulative.
     *
     * G702 line 5 prints total retainage while the deduction row is what reduces *this* payment — two different
     * questions that a single column cannot answer.
     */
    public function test_retention_is_held_cumulatively_and_deducted_by_movement(): void
    {
        // The cap is lifted out of the way so this test is about the movement alone; capping has its own test
        // below, and leaving the 5% limit in place here would have made the two rules argue in one assertion.
        $this->contract->update(['retention_limit_percent' => 100]);

        $first = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($first, '2026-09-05');

        $this->assertEquals(200_000, $first->refresh()->retention_to_date, '10% of 2,000,000');
        $this->assertSame(-200_000.0, $first->retentionThisPeriod());

        $second = $this->certificate($this->claim('2026-09-30', [100, 50]));

        // 10% of 7,000,000 held to date, but only 500,000 of it is new.
        $this->assertEquals(700_000, $second->retention_to_date);
        $this->assertSame(-500_000.0, $second->retentionThisPeriod());
    }

    /** The cap is a real contract term: retention stops accruing at the limit. */
    public function test_retention_stops_at_the_contract_cap(): void
    {
        // 5% of 10,000,000 = 500,000, which 10% of the works passes at 5,000,000 certified.
        $certificate = $this->certificate($this->claim('2026-08-31', [100, 100]));

        $this->assertEquals(500_000, $certificate->retention_to_date, 'capped, not 1,000,000');
    }

    public function test_a_line_that_does_not_bear_retention_is_left_out_of_it(): void
    {
        $this->contract->items()->where('item_no', '2')->update(['retention_applies' => false]);

        $certificate = $this->certificate($this->claim('2026-08-31', [100, 100]));

        // Only the 4,000,000 line bears it: 400,000 rather than the capped 500,000.
        $this->assertEquals(400_000, $certificate->retention_to_date);
    }

    /**
     * **The exit condition of this sub-phase.** Only agreed variations reach the certified sum.
     *
     * A provisionally priced variation belongs in the cost report, and putting it here would certify money nobody
     * agreed (§9).
     */
    public function test_only_agreed_variations_reach_the_certified_sum(): void
    {
        $variations = app(VariationService::class);

        $provisional = $variations->create($this->contract, ['title' => 'Disputed extra']);
        $variations->addItem($provisional, ['item_no' => '3', 'description' => 'Extra', 'quantity' => 1, 'rate' => 900_000]);
        $variations->submit($provisional);
        $variations->price($provisional->refresh());
        $variations->approveInPrinciple($provisional->refresh());

        $agreed = $variations->create($this->contract, ['title' => 'Agreed extra']);
        $variations->addItem($agreed, ['item_no' => '4', 'description' => 'Extra', 'quantity' => 1, 'rate' => 500_000]);
        $variations->submit($agreed);
        $variations->price($agreed->refresh());
        $variations->approve($agreed->refresh());

        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));

        $this->assertEquals(500_000, $certificate->variations_net_to_date, 'the provisional 900,000 is not here');
        $this->assertSame(10_500_000.0, $certificate->contractSumToDate());
    }

    /** Column D is a snapshot, and column E is derived from it. */
    public function test_the_previous_figures_are_frozen_into_the_next_certificate(): void
    {
        $first = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($first, '2026-09-05');

        $second = $this->certificate($this->claim('2026-09-30', [75, 0]));
        $line = $second->lines()->orderBy('item_no')->first();

        $this->assertEquals(2_000_000, $line->previous_work_value);
        $this->assertEquals(3_000_000, $line->cumulative_work_value);
        $this->assertSame(1_000_000.0, $line->workThisPeriod());
        $this->assertSame(3_000_000.0, $line->totalToDate());
        $this->assertSame(1_000_000.0, $line->balanceToFinish());
    }

    /**
     * A line the claim says nothing about keeps what it had.
     *
     * Zeroing it would un-certify work already paid for, and the continuation sheet would stop adding up to the
     * contract.
     */
    public function test_a_line_absent_from_the_claim_keeps_its_previous_value(): void
    {
        $first = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($first, '2026-09-05');

        // A second claim measuring only the second line at all.
        $claim = $this->certification->openClaim($this->contract, '2026-09-30');
        $claim->lines()->orderBy('id')->first()->delete();
        $second = $this->certificate($this->certification->submitClaim($claim->refresh()));

        $this->assertEquals(2_000_000, $second->lines()->orderBy('item_no')->first()->cumulative_work_value);
    }

    /** Advance recovery starts at the trigger and never recovers more than was advanced. */
    public function test_advance_recovery_starts_at_the_trigger_and_stops_when_repaid(): void
    {
        $this->contract->update([
            'advance_payment_amount' => 1_000_000,
            'advance_recovery_start_pct' => 20,
            'advance_recovery_rate_pct' => 25,
        ]);

        // 10% certified — below the 20% trigger, so nothing is recovered.
        $early = $this->certificate($this->claim('2026-08-31', [25, 0]));
        $this->assertSame(0.0, $this->recoveryOn($early));

        $this->certification->issue($early, '2026-09-05');

        // 60% certified — past the trigger. 25% of this period's 4,000,000 movement.
        $later = $this->certificate($this->claim('2026-09-30', [100, 33.3333]));
        $this->assertEquals(-1_000_000, $this->recoveryOn($later), 'capped at the advance, not 25% of the movement');
    }

    private function recoveryOn(PaymentCertificate $certificate): float
    {
        return (float) $certificate->deductions()
            ->where('kind', CertificateDeduction::KIND_ADVANCE_RECOVERY)
            ->sum('amount');
    }

    /**
     * The bottom line: gross to date, less every deduction row.
     *
     * **This assertion used to read 2,000,000 and that figure was wrong**, which is worth recording because
     * the test was here the whole time and passed. A certificate's deduction rows are *movements* — this
     * period's retention — but the row netting off earlier certificates used the previous **net** cash,
     * which had already had its own retention removed. Subtracting it handed that retention back, and
     * nothing put it back again.
     *
     * The old numbers settle to nonsense over the two certificates: 1,800,000 + 2,000,000 = 3,800,000 paid
     * against 4,000,000 of work, so only 200,000 was withheld — while `retention_to_date` and the retention
     * register both said 400,000. Two of the company's own records disagreeing, neither wrong alone.
     */
    public function test_the_amount_due_is_the_gross_less_every_deduction(): void
    {
        $first = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($first, '2026-09-05');

        // 2,000,000 gross less 200,000 retention.
        $this->assertEquals(1_800_000, $first->refresh()->current_due);

        $second = $this->certificate($this->claim('2026-09-30', [100, 0]));

        // 4,000,000 gross to date, 400,000 retention held to date, 200,000 of it new, and netted against
        // the previous GROSS of 2,000,000 rather than the 1,800,000 that was paid for it.
        $this->assertSame(4_000_000.0, (float) $second->gross_value_to_date);
        $this->assertSame(400_000.0, (float) $second->retention_to_date);
        $this->assertSame(2_000_000.0, (float) $second->previous_gross_value_to_date);
        $this->assertSame(1_800_000.0, $second->currentDue());
    }

    /**
     * **The identity the convention exists to hold.** Over any run of live certificates:
     *
     *     Σ current_due  ==  gross certified  −  retention held
     *
     * Asserted rather than reasoned, because it is the one statement that catches this class of error
     * whatever shape it arrives in — a movement netted against a net figure, a cumulative row counted
     * twice, a cap applied on the wrong side. Each certificate can look defensible on its own and still
     * break this.
     *
     * It needs a **second live certificate**, which is exactly what the suite lacked: the two existing
     * two-certificate tests both *void* the first, and voiding zeroes what the second nets against — so
     * the second behaves like a first, where movement and cumulative agree and nothing can go wrong.
     */
    public function test_payments_settle_to_gross_less_retention(): void
    {
        $first = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($first, '2026-09-05');

        $second = $this->certificate($this->claim('2026-09-30', [100, 0]));
        $this->certification->issue($second, '2026-10-05');

        $paid = PaymentCertificate::where('contract_id', $this->contract->id)
            ->live()
            ->sum('current_due');

        $gross = (float) $second->refresh()->gross_value_to_date;
        $held = (float) $second->retention_to_date;

        $this->assertSame(
            round($gross - $held, 2),
            round((float) $paid, 2),
            'cumulative payments must equal gross certified less retention held — anything else means the '
            .'contract is over- or under-certified and the retention register disagrees with the cash',
        );
    }

    public function test_a_manual_deduction_reduces_the_payment_and_shows_its_source(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));

        $this->certification->addDeduction($certificate, [
            'kind' => CertificateDeduction::KIND_NCR,
            'description' => 'Deduction under clause 14.6, NCR-0012',
            'amount' => -150_000,
        ]);

        $this->assertSame(1_650_000.0, $certificate->refresh()->currentDue());
    }

    /** A second retention row would deduct it twice, so the computed kinds are refused by hand. */
    public function test_a_computed_deduction_cannot_be_added_manually(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deduct it twice');

        $this->certification->addDeduction($certificate, [
            'kind' => CertificateDeduction::KIND_RETENTION,
            'description' => 'More retention',
            'amount' => -100_000,
        ]);
    }

    // ------------------------------------------------------------------ issue and freeze

    public function test_issuing_freezes_the_figures_and_starts_the_payment_clock(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($certificate, '2026-09-05');
        $certificate->refresh();

        $this->assertSame(PaymentCertificate::STATUS_ISSUED, $certificate->status);
        $this->assertSame('2026-09-05', $certificate->issued_on->toDateString());
        // 56 days, from the contract rather than from a constant in the code.
        $this->assertSame('2026-10-31', $certificate->due_on->toDateString());
        $this->assertEquals(1_800_000, $certificate->current_due);
        $this->assertSame(ProgressClaim::STATUS_CERTIFIED, $certificate->progressClaim->refresh()->status);
    }

    /**
     * An issued certificate is never recomputed.
     *
     * Its figures were countersigned by somebody else. The corrections are to void it, or to let the next one
     * absorb the difference.
     */
    public function test_an_issued_certificate_refuses_to_be_recomputed(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($certificate, '2026-09-05');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('statement of a moment');

        $this->certification->recompute($certificate->refresh());
    }

    /** And its frozen figure survives a later change to the schedule it was measured against. */
    public function test_an_issued_certificate_survives_a_change_to_the_schedule(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($certificate, '2026-09-05');

        $this->contract->items()->where('item_no', '1')->update(['scheduled_value' => 9_000_000]);

        $line = $certificate->refresh()->lines()->orderBy('item_no')->first();

        $this->assertEquals(4_000_000, $line->scheduled_value, 'the printed sheet is reproducible');
        $this->assertEquals(1_800_000, $certificate->current_due);
    }

    public function test_a_certificate_below_the_contract_minimum_is_not_issued(): void
    {
        $this->contract->update(['minimum_certificate_amount' => 5_000_000]);

        $certificate = $this->certificate($this->claim('2026-08-31', [10, 0]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('below this contract\'s minimum');

        $this->certification->issue($certificate);
    }

    public function test_voiding_needs_a_reason_and_keeps_the_number(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($certificate, '2026-09-05');

        $this->certification->void($certificate->refresh(), 'Issued against the wrong valuation date.');
        $certificate->refresh();

        $this->assertSame(PaymentCertificate::STATUS_VOID, $certificate->status);
        $this->assertSame('IPC-1', $certificate->certificate_number, 'the series has no gaps');
    }

    /**
     * A voided certificate stops counting, and the next one closes the arithmetic by itself.
     *
     * The whole reason for storing cumulative figures rather than movements.
     */
    public function test_voiding_a_certificate_is_absorbed_by_the_next_one(): void
    {
        $first = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($first, '2026-09-05');
        $this->certification->void($first->refresh(), 'Wrong figures.');

        $second = $this->certificate($this->claim('2026-09-30', [75, 0]));

        $this->assertSame(0.0, (float) $second->previously_certified, 'the voided certificate does not count');
        // The whole 3,000,000 less its 300,000 retention is payable now.
        $this->assertSame(2_700_000.0, $second->currentDue());
    }

    /** And column D still prints what it printed, because it was snapshotted rather than joined. */
    public function test_column_d_survives_the_voiding_of_the_certificate_it_came_from(): void
    {
        $first = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($first, '2026-09-05');

        $second = $this->certificate($this->claim('2026-09-30', [75, 0]));
        $this->certification->issue($second, '2026-10-05');

        $this->certification->void($first->refresh(), 'Superseded on review.');

        $this->assertEquals(
            2_000_000,
            $second->refresh()->lines()->orderBy('item_no')->first()->previous_work_value,
            'the frozen snapshot is unaffected',
        );
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_claim_register_shows_applied_against_certified(): void
    {
        $claim = $this->claim('2026-08-31', [50, 0]);
        $certificate = $this->certificate($claim);
        // Certified at less than claimed, which is the ordinary case and the reason both documents exist.
        $certificate->lines()->orderBy('item_no')->first()->update(['cumulative_work_value' => 1_700_000]);
        $this->certification->recompute($certificate->refresh());
        $this->certification->issue($certificate->refresh(), '2026-09-05');

        Livewire::test(ListProgressClaims::class)
            ->assertSuccessful()
            ->assertSee('STMT-1')
            // 2,000,000 applied, 1,700,000 certified.
            ->assertSee('2,000,000.00')
            ->assertSee('1,700,000.00');
    }

    public function test_the_certificate_register_renders_and_certifies(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));

        Livewire::test(ListPaymentCertificates::class)
            ->assertSuccessful()
            ->assertSee('IPC-1')
            ->callTableAction('certify', $certificate, ['issued_on' => '2026-09-05']);

        $this->assertSame(PaymentCertificate::STATUS_ISSUED, $certificate->refresh()->status);
    }

    public function test_the_void_action_needs_its_reason(): void
    {
        $certificate = $this->certificate($this->claim('2026-08-31', [50, 0]));
        $this->certification->issue($certificate, '2026-09-05');

        Livewire::test(ListPaymentCertificates::class)
            ->callTableAction('void', $certificate, ['reason' => 'Wrong valuation date.']);

        $this->assertSame(PaymentCertificate::STATUS_VOID, $certificate->refresh()->status);
    }
}
