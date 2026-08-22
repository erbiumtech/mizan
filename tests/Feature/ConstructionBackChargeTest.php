<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Pages\ListBackCharges;
use App\Modules\ConstructionContracts\Models\BackCharge;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaimLine;
use App\Modules\ConstructionContracts\Services\BackChargeService;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Support\ModuleMap;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Back-charges — `docs/construction-management-plan.md` §12, Phase 6b.
 *
 * Four properties carry this file, and each is a way a back-charge is money the company thinks it has and does not.
 *
 *  - **Notice before deduction.** §12's sentence is that an incurred-but-unnotified back-charge is money the company
 *    will not get and does not yet know it has lost. So `apply()` refuses a draft outright, and the un-notified list is
 *    a query rather than a habit somebody has.
 *  - **Nothing applies itself.** §16.5's rule for NCRs, inherited here: the charge proposes and a human confirms. A
 *    certificate recomputed twenty times still carries no back-charge nobody chose — asserted, because a later reader
 *    would otherwise be tempted to "helpfully" wire it in.
 *  - **Draft computes, notice freezes.** The notified total is the figure the subcontractor was told. After notice the
 *    only way it changes is `agree()`, and the settled figure sits beside the original rather than over it.
 *  - **The deduction is the only writer of money**, through `CertificationService::addDeduction()`, so the sign
 *    convention and the draft-only rule stay in the one place that owns them.
 */
class ConstructionBackChargeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contract $subcontract;

    private BackChargeService $charges;

    private CertificationService $certification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'backcharge@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $job = Job::create(['code' => 'J-1', 'name' => 'Tower']);

        $contracts = app(ContractService::class);
        $this->subcontract = $contracts->create($job, [
            'side' => Contract::SIDE_PAYABLE,
            'title' => 'Structural steel',
            'contract_sum' => 1_000_000,
            'retention_percent' => 5,
            'payment_terms_days' => 30,
        ]);

        $contracts->addItem($this->subcontract, [
            'item_no' => '1', 'description' => 'Fabricate and erect', 'scheduled_value' => 1_000_000,
        ]);

        $contracts->execute($this->subcontract);
        $this->subcontract->refresh();

        $this->charges = app(BackChargeService::class);
        $this->certification = app(CertificationService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function charge(array $attributes = []): BackCharge
    {
        return $this->charges->create($this->subcontract, array_merge([
            'kind' => 'cleanup',
            'description' => 'Cleared debris left in the west bay after erection.',
            'incurred_on' => '2026-08-10',
            'amount' => 200_000,
            'markup_percent' => 20,
        ], $attributes));
    }

    /** A draft certificate on the subcontract, so a charge has something to be deducted from. */
    private function certificate(string $periodEnd = '2026-08-31', float $percent = 40): PaymentCertificate
    {
        $claim = $this->certification->openClaim($this->subcontract, $periodEnd);

        $claim->lines()->orderBy('id')->first()->update([
            'measurement_input' => ProgressClaimLine::INPUT_PERCENT,
            'cumulative_percent' => $percent,
        ]);

        $submitted = $this->certification->submitClaim($claim->refresh());

        return $this->certification->prepare($this->subcontract, $periodEnd, $submitted);
    }

    // ------------------------------------------------------------ raising one

    /** Numbered per subcontract, and the total is cost plus markup. */
    public function test_a_back_charge_is_numbered_and_priced(): void
    {
        $charge = $this->charge();

        $this->assertSame('BC-1', $charge->reference);
        $this->assertSame(BackCharge::STATUS_DRAFT, $charge->status);
        $this->assertEquals(240_000, $charge->total_amount);
        // The job is copied off the contract rather than asked for twice.
        $this->assertSame($this->subcontract->job_id, $charge->job_id);
    }

    /**
     * The series reads the trailing segment only.
     *
     * `ContractService::nextContractNumber()` records why: stripping every non-digit from a reference carrying a code
     * lets the code eat the series.
     */
    public function test_the_series_continues(): void
    {
        $this->charge();

        $this->assertSame('BC-2', $this->charge()->reference);
    }

    /**
     * A back-charge is something recovered from a subcontractor, so it has no meaning on a receivable contract.
     *
     * Asserted on a draft, which is enough: the side is checked before the executed check, so the refusal names the
     * right problem — "receivable contract", not "not executed".
     */
    public function test_a_receivable_contract_has_no_back_charges(): void
    {
        $job = Job::create(['code' => 'J-2', 'name' => 'Bridge']);
        $main = app(ContractService::class)->create($job, ['title' => 'Main works', 'contract_sum' => 5_000_000]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('receivable contract');

        $this->charges->create($main, ['kind' => 'cleanup', 'description' => 'x', 'amount' => 100]);
    }

    /** Nothing to deduct from a contract nobody has executed. */
    public function test_a_draft_subcontract_has_nothing_to_deduct_from(): void
    {
        $job = Job::create(['code' => 'J-3', 'name' => 'Depot']);
        $draft = app(ContractService::class)->create($job, [
            'side' => Contract::SIDE_PAYABLE, 'title' => 'Groundworks', 'contract_sum' => 100_000,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not executed');

        $this->charges->create($draft, ['kind' => 'cleanup', 'description' => 'x', 'amount' => 100]);
    }

    // ------------------------------------------------------------------ notice

    /**
     * **The rule the table exists for: an un-notified charge cannot be deducted.**
     *
     * §12: almost every subcontract requires notice first, so deducting without it is a payment the subcontractor can
     * recover — and the company would then have spent the money twice.
     */
    public function test_a_charge_with_no_notice_cannot_be_deducted(): void
    {
        $charge = $this->charge();
        $certificate = $this->certificate();

        try {
            $this->charges->apply($charge, $certificate);
            $this->fail('A draft back-charge should not have been deductible.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('has not been notified', $e->getMessage());
            $this->assertStringContainsString('spent the money twice', $e->getMessage());
        }

        // And no deduction row was written on the way to the refusal.
        $this->assertSame(0, $certificate->deductions()
            ->where('kind', CertificateDeduction::KIND_BACK_CHARGE)->count());
    }

    /**
     * Notice records the subcontractor's date, not today's.
     *
     * A system that stamped `now()` would quietly move every notice inside the contractual window it is being measured
     * against — which is the window the whole notice provision is about.
     */
    public function test_notice_keeps_the_date_it_was_served(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');

        $this->assertSame(BackCharge::STATUS_NOTIFIED, $charge->status);
        $this->assertSame('2026-08-14', $charge->notified_on->toDateString());
        $this->assertSame(auth()->id(), $charge->notified_by);
    }

    /** Notice cannot predate the work it is notice of. */
    public function test_notice_cannot_predate_the_work(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot predate the work');

        $this->charges->notify($this->charge(['incurred_on' => '2026-08-10']), '2026-08-01');
    }

    /** A contractual clock started over nothing is worse than no notice at all. */
    public function test_a_charge_with_no_value_cannot_be_notified(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no value');

        $this->charges->notify($this->charge(['amount' => 0, 'markup_percent' => null]));
    }

    /** Notice is served once, because the date is what the subcontract measures. */
    public function test_notice_is_served_once(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already notified');

        $this->charges->notify($charge, '2026-08-20');
    }

    // ------------------------------------------------- freezing and settling

    /** While it is a draft, the total follows the cost and the markup. */
    public function test_a_draft_recomputes_its_total(): void
    {
        $charge = $this->charge();

        $charge->update(['amount' => 300_000, 'markup_percent' => 10]);

        $this->assertEquals(330_000, $this->charges->recompute($charge)->total_amount);
    }

    /**
     * Once notice is served the total is what the subcontractor was told.
     *
     * A figure that drifted after notice would make every notice a document nobody could rely on — the same reason
     * `CertificationService::recompute()` refuses an issued certificate.
     */
    public function test_notice_freezes_the_total(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');

        $charge->update(['amount' => 900_000]);

        $this->assertEquals(240_000, $this->charges->recompute($charge->refresh())->total_amount);
    }

    /**
     * A settlement keeps **both** figures.
     *
     * "Notified 240,000, settled at 180,000" is the fact somebody needs at final account. One column loses the first
     * half of it, and with it the reason the account does not add up to the notices.
     */
    public function test_a_settlement_sits_beside_the_notified_figure(): void
    {
        $charge = $this->charges->agree($this->charges->notify($this->charge(), '2026-08-14'), 180_000);

        $this->assertSame(BackCharge::STATUS_AGREED, $charge->status);
        $this->assertEquals(240_000, $charge->total_amount);
        $this->assertEquals(180_000, $charge->agreed_amount);
        $this->assertEquals(180_000, $charge->recoverableAmount());
    }

    /** Settling above the notified figure is a new charge, and it needs its own notice. */
    public function test_a_settlement_above_the_notice_is_refused(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs its own notice');

        $this->charges->agree($charge, 400_000);
    }

    /** Agreeing something nobody was told about records a settlement of a negotiation that never happened. */
    public function test_an_unnotified_charge_cannot_be_agreed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has not been notified');

        $this->charges->agree($this->charge(), 100_000);
    }

    /**
     * A dispute is recorded, not resolved — and it does not stop the recovery.
     *
     * Most subcontracts let the main contractor deduct a notified charge and send the argument where the contract says
     * arguments go. A register that refused would be a register people worked around.
     */
    public function test_a_disputed_charge_is_still_deductible(): void
    {
        $charge = $this->charges->dispute(
            $this->charges->notify($this->charge(), '2026-08-14'),
            'They say the debris was the following trade\'s.',
        );

        $this->assertSame(BackCharge::STATUS_DISPUTED, $charge->status);
        $this->assertTrue($charge->isApplicable());

        $deduction = $this->charges->apply($charge, $this->certificate());

        $this->assertEquals(-240_000, $deduction->amount);
    }

    /** "Disputed" with no grounds is a row nobody can answer. */
    public function test_a_dispute_needs_the_grounds(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the subcontractor');

        $this->charges->dispute($charge, '   ');
    }

    // ------------------------------------------------------------- deducting

    /**
     * Applying writes **one** deduction, negative, traceable back to the charge.
     *
     * §10.3's convention stated once: a negative amount reduces the payment. The morph is what makes the bottom half of
     * the certificate readable backwards.
     */
    public function test_applying_writes_one_traceable_negative_deduction(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');
        $certificate = $this->certificate();

        $deduction = $this->charges->apply($charge, $certificate);

        $this->assertEquals(-240_000, $deduction->amount);
        $this->assertSame(CertificateDeduction::KIND_BACK_CHARGE, $deduction->kind);
        $this->assertFalse($deduction->is_automatic);
        $this->assertSame(auth()->id(), $deduction->approved_by);
        // Written through the alias, not the class name — §18.2's plain-column morph trap.
        $this->assertSame(ModuleMap::alias(BackCharge::class), $deduction->source_type);
        $this->assertSame($charge->getKey(), (int) $deduction->source_id);
        $this->assertStringContainsString('BC-1', $deduction->description);

        $charge->refresh();
        $this->assertSame(BackCharge::STATUS_APPLIED, $charge->status);
        $this->assertSame($certificate->getKey(), $charge->applied_certificate_id);
        $this->assertSame(auth()->id(), $charge->applied_by);
    }

    /** And the certificate's payment falls by exactly that much. */
    public function test_the_deduction_reduces_what_is_due(): void
    {
        $certificate = $this->certificate();
        $before = $certificate->currentDue();

        $this->charges->apply($this->charges->notify($this->charge(), '2026-08-14'), $certificate);

        $this->assertEquals(round($before - 240_000, 2), round($certificate->refresh()->currentDue(), 2));
    }

    /** Deducting it twice takes money the subcontractor has already lost once. */
    public function test_a_charge_cannot_be_deducted_twice(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');
        $this->charges->apply($charge, $this->certificate());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already deducted');

        $this->charges->apply($charge->refresh(), $this->certificate('2026-09-30', 60));
    }

    /** A charge on somebody else's certificate deducts from the wrong party. */
    public function test_a_charge_cannot_be_deducted_from_another_subcontract(): void
    {
        $other = app(ContractService::class)->create(
            Job::firstWhere('code', 'J-1'),
            ['side' => Contract::SIDE_PAYABLE, 'title' => 'Cladding', 'contract_sum' => 500_000],
        );
        app(ContractService::class)->addItem($other, [
            'item_no' => '1', 'description' => 'Panels', 'scheduled_value' => 500_000,
        ]);
        app(ContractService::class)->execute($other);

        $claim = $this->certification->openClaim($other->refresh(), '2026-08-31');
        $claim->lines()->orderBy('id')->first()->update([
            'measurement_input' => ProgressClaimLine::INPUT_PERCENT, 'cumulative_percent' => 50,
        ]);
        $theirs = $this->certification->prepare(
            $other, '2026-08-31', $this->certification->submitClaim($claim->refresh()),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('different subcontract');

        $this->charges->apply($this->charges->notify($this->charge(), '2026-08-14'), $theirs);
    }

    /** An issued certificate is frozen, so the charge belongs on the next one. */
    public function test_an_issued_certificate_takes_no_further_back_charge(): void
    {
        $certificate = $this->certificate();
        $this->certification->issue($certificate);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is issued');

        $this->charges->apply($this->charges->notify($this->charge(), '2026-08-14'), $certificate->refresh());
    }

    /**
     * Taking it back off returns the charge to notified — **never to draft**.
     *
     * Notice cannot be unserved. A charge sent back to draft would be re-notified with a later date, moving it inside a
     * contractual window it had already left.
     */
    public function test_taking_a_charge_off_a_draft_returns_it_to_notified(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');
        $certificate = $this->certificate();
        $before = $certificate->currentDue();

        $this->charges->apply($charge, $certificate);
        $charge = $this->charges->unapply($charge->refresh(), 'Charged to the wrong trade.');

        $this->assertSame(BackCharge::STATUS_NOTIFIED, $charge->status);
        $this->assertSame('2026-08-14', $charge->notified_on->toDateString());
        $this->assertNull($charge->applied_certificate_id);

        // The deduction row is gone and the certificate has been recomputed without it.
        $this->assertSame(0, $certificate->refresh()->deductions()
            ->where('kind', CertificateDeduction::KIND_BACK_CHARGE)->count());
        $this->assertEquals(round($before, 2), round($certificate->currentDue(), 2));
    }

    /** A settled charge taken off a certificate goes back to agreed, not to notified. */
    public function test_a_settled_charge_returns_to_agreed(): void
    {
        $charge = $this->charges->agree($this->charges->notify($this->charge(), '2026-08-14'), 180_000);
        $this->charges->apply($charge, $this->certificate());

        $this->assertSame(BackCharge::STATUS_AGREED, $this->charges->unapply($charge->refresh())->status);
    }

    /** An issued certificate cannot give a back-charge back; the way out is the next certificate or a void. */
    public function test_an_issued_certificate_cannot_give_a_charge_back(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');
        $certificate = $this->certificate();
        $this->charges->apply($charge, $certificate);
        $this->certification->issue($certificate->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('figures are frozen');

        $this->charges->unapply($charge->refresh());
    }

    // ----------------------------------------------------- nothing automatic

    /**
     * **A certificate never grows a back-charge nobody chose.**
     *
     * §16.5's rule, and the assertion exists so a later reader does not take the absence for an oversight and wire it
     * in: a deduction appearing on a certificate that nobody decided on is the fastest available route to a dispute,
     * and it will be this company's dispute, because the other party's copy has already left the building.
     */
    public function test_recomputing_a_certificate_applies_nothing(): void
    {
        $this->charges->agree($this->charges->notify($this->charge(), '2026-08-14'), 180_000);

        $certificate = $this->certificate();

        for ($i = 0; $i < 3; $i++) {
            $this->certification->recompute($certificate->refresh());
        }

        $this->assertSame(0, $certificate->refresh()->deductions()
            ->where('kind', CertificateDeduction::KIND_BACK_CHARGE)->count());
        $this->assertSame(BackCharge::STATUS_AGREED, BackCharge::firstWhere('reference', 'BC-1')->status);
    }

    /** What it does instead is offer them, which is the whole mechanism. */
    public function test_the_service_offers_what_a_certificate_could_take(): void
    {
        $this->charges->notify($this->charge(), '2026-08-14');
        $this->charge(['description' => 'Second charge, no notice served.']);

        $offered = $this->charges->offerFor($this->certificate());

        $this->assertCount(1, $offered);
        $this->assertSame('BC-1', $offered->first()->reference);
    }

    /** And it offers nothing against a certificate that is already issued. */
    public function test_nothing_is_offered_against_an_issued_certificate(): void
    {
        $this->charges->notify($this->charge(), '2026-08-14');
        $certificate = $this->certificate();
        $this->certification->issue($certificate);

        $this->assertCount(0, $this->charges->offerFor($certificate->refresh()));
    }

    // --------------------------------------------------------- the exposure

    /**
     * **§12's sentence as a number.**
     *
     * Money the company has spent on somebody else's obligation and cannot yet recover — and until it is a query,
     * nobody knows the total.
     */
    public function test_the_unnotified_total_is_the_exposure(): void
    {
        $this->charge();                                              // 240,000, no notice
        $this->charge(['amount' => 50_000, 'markup_percent' => 0]);  // 50,000, no notice
        $this->charges->notify($this->charge(['amount' => 100_000, 'markup_percent' => 0]), '2026-08-14');

        $this->assertEquals(290_000, $this->charges->unnotifiedTotal());
        $this->assertCount(2, $this->charges->unnotified());

        // The other half: recoverable and simply not taken, which is a person's oversight rather than a bar.
        $this->assertEquals(100_000, $this->charges->awaitingApplicationTotal());
    }

    /** An applied charge leaves both figures, because it is no longer exposure. */
    public function test_applying_clears_the_charge_from_both_totals(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');
        $this->charges->apply($charge, $this->certificate());

        $this->assertEquals(0, $this->charges->unnotifiedTotal());
        $this->assertEquals(0, $this->charges->awaitingApplicationTotal());
    }

    // ------------------------------------------------------------ withdrawal

    /** Withdrawing needs a reason: the subcontractor was told, and this row explains why the account differs. */
    public function test_withdrawing_needs_a_reason(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->charges->withdraw($charge, '  ');
    }

    public function test_a_withdrawn_charge_keeps_its_reason_and_leaves_the_exposure(): void
    {
        $charge = $this->charges->withdraw(
            $this->charges->notify($this->charge(), '2026-08-14'),
            'Debris was ours; the wrong trade was charged.',
        );

        $this->assertSame(BackCharge::STATUS_WITHDRAWN, $charge->status);
        $this->assertStringContainsString('wrong trade', $charge->withdrawal_reason);
        $this->assertNotNull($charge->withdrawn_at);
        $this->assertEquals(0, $this->charges->awaitingApplicationTotal());
    }

    /** Applied money has to come off the certificate before the charge can be dropped, so the two move together. */
    public function test_an_applied_charge_cannot_be_withdrawn_while_it_is_deducted(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');
        $this->charges->apply($charge, $this->certificate());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Take it off the certificate first');

        $this->charges->withdraw($charge->refresh(), 'Changed our minds.');
    }

    /** There is no delete: a charge somebody was told about is a fact about the account either way. */
    public function test_a_back_charge_is_never_deleted(): void
    {
        $this->assertFalse($this->manager()->can('delete', $this->charge()));
    }

    // ------------------------------------------------------------- the screen

    public function test_the_register_shows_the_exposure_and_its_total(): void
    {
        $this->charge();
        $this->charges->notify($this->charge(['amount' => 100_000, 'markup_percent' => 0]), '2026-08-14');

        Livewire::test(ListBackCharges::class)
            ->assertSuccessful()
            ->assertSee('BC-1')
            ->assertSee('Not served')
            ->assertSee('240,000.00');
    }

    public function test_the_notice_action_records_the_date(): void
    {
        $charge = $this->charge();

        Livewire::test(ListBackCharges::class)
            ->callTableAction('notify', $charge, ['notified_on' => '2026-08-15']);

        $this->assertSame('2026-08-15', $charge->refresh()->notified_on->toDateString());
    }

    public function test_the_apply_action_offers_only_draft_certificates_of_that_subcontract(): void
    {
        $charge = $this->charges->notify($this->charge(), '2026-08-14');
        $certificate = $this->certificate();

        Livewire::test(ListBackCharges::class)
            ->callTableAction('apply', $charge, ['payment_certificate_id' => $certificate->getKey()]);

        $this->assertSame(BackCharge::STATUS_APPLIED, $charge->refresh()->status);
        $this->assertEquals(-240_000, $certificate->refresh()->deductions()
            ->where('kind', CertificateDeduction::KIND_BACK_CHARGE)->sum('amount'));
    }

    /** The deduct button is absent on a charge nobody has served notice of — the rule, on the screen. */
    public function test_the_apply_action_is_hidden_without_notice(): void
    {
        $charge = $this->charge();

        $this->actingAs($this->manager());

        Livewire::test(ListBackCharges::class)
            ->assertActionHidden(TestAction::make('apply')->table($charge))
            ->assertActionVisible(TestAction::make('notify')->table($charge));
    }

    /** And editing closes the moment notice is served. */
    public function test_editing_closes_once_notice_is_served(): void
    {
        $charge = $this->charge();

        $this->actingAs($this->manager());

        Livewire::test(ListBackCharges::class)
            ->assertActionVisible(TestAction::make('edit')->table($charge));

        $this->charges->notify($charge, '2026-08-14');

        Livewire::test(ListBackCharges::class)
            ->assertActionHidden(TestAction::make('edit')->table($charge->refresh()));
    }

    /**
     * A Manager, because a refusal asserted as an Administrator asserts nothing.
     *
     * `AppServiceProvider` registers a `Gate::before` that waves an Administrator through every ability except
     * `create`, so the policy is never consulted for one — and a hidden-button assertion would be checking Filament's
     * rendering rather than the rule. The Manager is the role these policies actually apply to and holds all three
     * back-charge grants, which makes it the right user to ask "is this button absent because the *state* forbids it".
     */
    private function manager(): User
    {
        (new RoleSeeder)->run();

        return $this->makeUser('Manager', 'backcharge-manager@test.local');
    }
}
