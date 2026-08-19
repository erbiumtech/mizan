<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Console\Commands\CheckComplianceExpiry;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Pages\ListComplianceDocuments;
use App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Pages\ListComplianceRequirements;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\ListPaymentCertificates;
use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use App\Modules\ConstructionContracts\Models\ComplianceRequirement;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionContracts\Models\ProgressClaimLine;
use App\Modules\ConstructionContracts\Notifications\ComplianceDocumentExpiring;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\ComplianceService;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Models\Contact;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Subcontractor compliance — `docs/construction-management-plan.md` §12, Phase 6a.
 *
 * Phase 6's exit condition is one sentence and the last two tests in this file are it: **a certificate that refuses to
 * certify against expired insurance, and an override that records who and why.**
 *
 * Four properties carry the rest, and each is a way a compliance register looks like a control while being none.
 *
 *  - **Nothing is stored.** §12 calls a stored status the most dangerous silent failure on the payable side: a row
 *    saying `verified` with an expiry three months past pays a subcontractor with no cover while the screen looks
 *    fine. Every status here is derived from the dates, so there is nothing to drift.
 *  - **Judged as at the date asked about.** A June certificate issued in August is judged on June's cover. Asking
 *    about today would refuse a payment for work that was properly covered when it was done.
 *  - **A document is not compliance.** Unverified, insufficiently covered, and out-of-period documents all exist and
 *    all fail — a register that only asked whether a row existed would pass every one of them.
 *  - **The refusal is at certification, not payment.** Blocking at payment leaves an approved payable finance cannot
 *    pay, which is worse than a refusal because the liability already exists and the stuck payment has no owner.
 */
class ConstructionComplianceTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contract $contract;

    private Contact $subcontractor;

    private CertificationService $certification;

    private ComplianceService $compliance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'compliance@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->subcontractor = Contact::create(['name' => 'Steelwork Ltd', 'kind' => Contact::KIND_SUPPLIER]);

        $contracts = app(ContractService::class);
        $this->contract = $contracts->create($job, [
            'side' => Contract::SIDE_PAYABLE,
            'title' => 'Structural steel',
            'contact_id' => $this->subcontractor->getKey(),
            'contract_sum' => 1_000_000,
            'retention_percent' => 5,
            'payment_terms_days' => 30,
        ]);

        $contracts->addItem($this->contract, [
            'item_no' => '1', 'description' => 'Fabricate and erect', 'scheduled_value' => 1_000_000,
        ]);

        $contracts->execute($this->contract);
        $this->contract->refresh();

        $this->certification = app(CertificationService::class);
        $this->compliance = app(ComplianceService::class);
    }

    /**
     * A document of a kind, with dates. Everything else this file asserts is derived from these.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function document(string $kind, array $attributes = []): ComplianceDocument
    {
        return ComplianceDocument::create(array_merge([
            'contact_id' => $this->subcontractor->getKey(),
            'kind' => $kind,
            'scope' => ComplianceDocument::SCOPE_COMPANY,
            'reference' => strtoupper($kind).'-1',
            'effective_from' => '2026-01-01',
            'expires_on' => '2026-12-31',
            'verified_at' => now(),
            'verified_by' => auth()->id(),
        ], $attributes));
    }

    /** A company-level requirement of a kind, which is what makes its absence a finding. */
    private function require(string $kind, array $attributes = []): ComplianceRequirement
    {
        return ComplianceRequirement::create(array_merge(['kind' => $kind], $attributes));
    }

    /** A submitted claim and a draft certificate for a period, so the compliance rule has something to refuse. */
    private function certificate(string $periodEnd, float $percent = 40): PaymentCertificate
    {
        $claim = $this->certification->openClaim($this->contract, $periodEnd);

        $claim->lines()->orderBy('id')->first()->update([
            'measurement_input' => ProgressClaimLine::INPUT_PERCENT,
            'cumulative_percent' => $percent,
        ]);

        $submitted = $this->certification->submitClaim($claim->refresh());

        return $this->certification->prepare($this->contract, $submitted->period_end->toDateString(), $submitted);
    }

    // -------------------------------------------------------------- the status

    /**
     * The whole design in one assertion: **no stored status can be wrong, because there is no stored status.**
     *
     * §12's named failure is a row reading `verified` with an expiry months past. Here the row *is* verified, and the
     * status is `expired` anyway, because it is the dates that answer the question.
     */
    public function test_a_verified_document_past_its_expiry_reads_as_expired(): void
    {
        $policy = $this->document('general_liability', [
            'expires_on' => '2026-06-30',
            'verified_at' => now(),
        ]);

        $this->assertSame('valid', $policy->statusOn('2026-03-01'));
        $this->assertSame('expired', $policy->statusOn('2026-08-01'));

        // And the column that would have carried a stored answer does not exist.
        $this->assertArrayNotHasKey('status', $policy->getAttributes());
    }

    /** Signed, so "expired 32 days ago" is distinguishable from "expires in 32 days". */
    public function test_days_until_expiry_is_signed_rather_than_clamped(): void
    {
        $policy = $this->document('general_liability', ['expires_on' => '2026-06-30']);

        $this->assertSame(30, $policy->daysUntilExpiry('2026-05-31'));
        $this->assertSame(-32, $policy->daysUntilExpiry('2026-08-01'));

        // No expiry at all is not "expiring in a very long time" — a perpetual trade licence has no deadline.
        $this->assertNull($this->document('trade_licence', ['expires_on' => null])->daysUntilExpiry('2026-08-01'));
    }

    /**
     * Arriving and being read are different facts, and only the second satisfies a requirement.
     *
     * A register whose rows counted on arrival would be a register of documents nobody had looked at, which is exactly
     * what `verified_at` exists to distinguish.
     */
    public function test_a_document_nobody_has_read_does_not_satisfy(): void
    {
        $unread = $this->document('general_liability', ['verified_at' => null, 'verified_by' => null]);

        $this->assertSame('unverified', $unread->statusOn('2026-03-01'));
        $this->assertFalse($unread->satisfiesOn('2026-03-01'));

        $this->compliance->verify($unread);

        $this->assertTrue($unread->refresh()->satisfiesOn('2026-03-01'));
    }

    /**
     * An expired document nobody read is **expired**, not merely unverified.
     *
     * The order of the two checks is deliberate: reporting the softer of two true statements understates the problem,
     * and "unverified" reads as paperwork where "expired" reads as uninsured.
     */
    public function test_expiry_is_reported_ahead_of_never_having_been_read(): void
    {
        $document = $this->document('general_liability', [
            'expires_on' => '2026-06-30',
            'verified_at' => null,
            'verified_by' => null,
        ]);

        $this->assertSame('expired', $document->statusOn('2026-08-01'));
    }

    // -------------------------------------------------------- requirements

    /**
     * A missing document is a row in the report, not an absence from it.
     *
     * A register that listed only what it had would be a register of good news, and the finding here *is* the absence.
     */
    public function test_a_requirement_with_no_document_reports_as_missing(): void
    {
        $this->require('workers_compensation');

        $rows = $this->compliance->statusFor($this->contract, '2026-03-01');

        $this->assertCount(1, $rows);
        $this->assertSame('missing', $rows[0]['status']);
        $this->assertNull($rows[0]['document']);
        $this->assertTrue($rows[0]['blocks_certification']);
    }

    /**
     * The contract's own requirement beats the company template.
     *
     * Without the template every contract restates the same insurance list and the one that mattered is forgotten;
     * without the override every exception has to be written into the template, which is worse.
     */
    public function test_a_contract_requirement_overrides_the_company_template(): void
    {
        $this->require('professional_indemnity', ['blocks' => ComplianceRequirement::BLOCKS_CERTIFICATION]);
        $this->require('professional_indemnity', [
            'contract_id' => $this->contract->getKey(),
            'blocks' => ComplianceRequirement::BLOCKS_NONE,
        ]);

        $requirements = $this->compliance->requirementsFor($this->contract);

        $this->assertCount(1, $requirements);
        $this->assertSame(ComplianceRequirement::BLOCKS_NONE, $requirements->get('professional_indemnity')->blocks);
        $this->assertSame([], $this->compliance->blockers($this->contract, '2026-03-01'));
    }

    /** `blocks = none` is a register entry that is chased, not a rule that stops a payment. */
    public function test_a_requirement_that_blocks_nothing_does_not_block(): void
    {
        $this->require('training_matrix', ['blocks' => ComplianceRequirement::BLOCKS_NONE]);

        $rows = $this->compliance->statusFor($this->contract, '2026-03-01');

        $this->assertSame('missing', $rows[0]['status']);
        $this->assertFalse($rows[0]['blocks_certification']);
    }

    /**
     * Grace days tolerate the renewal that is in the post.
     *
     * Without them the register stops a certificate on the day a policy lapses and gets overridden every month until
     * somebody sets the grace period they should have set at the start — and an override that happens every month is
     * not a control.
     */
    public function test_grace_days_tolerate_a_lapse_that_is_being_renewed(): void
    {
        $this->require('general_liability', ['grace_days' => 14]);
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        // Inside grace: expiring, and not a blocker.
        $this->assertSame([], $this->compliance->blockers($this->contract, '2026-07-10'));

        // Past it: expired, and the certificate stops.
        $this->assertNotSame([], $this->compliance->blockers($this->contract, '2026-07-20'));
    }

    /**
     * A live policy for too little money is not compliance.
     *
     * §12's reason for the column: a subcontractor asked for ten million of public liability who produces a
     * one-million policy has produced a document, and a check that only asked whether a document existed would pass it.
     */
    public function test_a_policy_below_the_required_sum_insured_is_not_compliance(): void
    {
        $this->require('general_liability', ['minimum_cover' => 10_000_000]);
        $this->document('general_liability', ['amount_covered' => 1_000_000]);

        $rows = $this->compliance->statusFor($this->contract, '2026-03-01');

        $this->assertSame('insufficient_cover', $rows[0]['status']);
        $this->assertTrue($rows[0]['blocks_certification']);
    }

    /**
     * A lien waiver is per payment; an insurance certificate is per policy period.
     *
     * A period-scoped document that covered everything would let one waiver clear every payment for the life of the
     * contract, which is the opposite of what a waiver is for.
     */
    public function test_a_period_scoped_waiver_covers_only_its_own_period(): void
    {
        $this->require('lien_waiver_conditional_progress');
        $this->document('lien_waiver_conditional_progress', [
            'scope' => ComplianceDocument::SCOPE_PERIOD,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'expires_on' => null,
        ]);

        $this->assertSame([], $this->compliance->blockers($this->contract, '2026-06-30'));
        $this->assertNotSame([], $this->compliance->blockers($this->contract, '2026-07-31'));
    }

    /**
     * The best document of a kind answers the requirement, not the newest row.
     *
     * A subcontractor who re-sends last year's certificate should not displace the live one — and sorting by expiry
     * alone would let a document with no expiry outrank a current policy.
     */
    public function test_the_document_that_satisfies_wins_over_one_that_does_not(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['reference' => 'LAST-YEAR', 'expires_on' => '2025-12-31']);
        $this->document('general_liability', ['reference' => 'CURRENT', 'expires_on' => '2026-12-31']);

        $rows = $this->compliance->statusFor($this->contract, '2026-03-01');

        $this->assertSame('CURRENT', $rows[0]['document']->reference);
        $this->assertSame([], $this->compliance->blockers($this->contract, '2026-03-01'));
    }

    // ------------------------------------------------------------ the refusal

    /**
     * **Phase 6's exit condition, first half: a certificate refuses to certify against expired insurance.**
     *
     * The refusal is here rather than at payment because blocking at payment leaves an approved payable finance cannot
     * pay — worse than a refusal, since the liability already exists and the stuck payment has nobody's name on it.
     */
    public function test_a_certificate_refuses_to_issue_against_expired_insurance(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        $certificate = $this->certificate('2026-08-31');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be certified');

        $this->certification->issue($certificate);
    }

    /** And the refusal names the document and how long ago it lapsed, because "not compliant" sends somebody hunting. */
    public function test_the_refusal_names_the_document_and_the_lapse(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        $certificate = $this->certificate('2026-08-31');

        try {
            $this->certification->issue($certificate);
            $this->fail('An expired policy should have stopped the certificate.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('General liability', $e->getMessage());
            $this->assertStringContainsString('expired', $e->getMessage());
            $this->assertStringContainsString('62 days ago', $e->getMessage());
        }

        // And it is still a draft: a refusal that half-issued would be worse than no rule at all.
        $this->assertSame(PaymentCertificate::STATUS_DRAFT, $certificate->refresh()->status);
    }

    /**
     * Compliance is judged on the **valuation date**, not on the day somebody gets round to issuing.
     *
     * A June certificate issued in August has to be judged on the cover that was in force in June. Asking about today
     * would refuse a payment for work that was properly covered when it was done — which is a refusal nobody can act
     * on, because the past cannot be re-insured.
     */
    public function test_a_certificate_is_judged_on_the_cover_in_force_in_its_own_period(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        // Valued in June, when the policy was live, even though it has lapsed by now.
        $this->travelTo('2026-08-15');

        $certificate = $this->certificate('2026-06-30');
        $issued = $this->certification->issue($certificate);

        $this->assertSame(PaymentCertificate::STATUS_ISSUED, $issued->status);
        $this->assertNull($issued->compliance_override_at);
    }

    /** A company that has never set a requirement is never blocked: the template is empty, so nothing is required. */
    public function test_a_company_with_no_requirements_certifies_as_before(): void
    {
        $issued = $this->certification->issue($this->certificate('2026-08-31'));

        $this->assertSame(PaymentCertificate::STATUS_ISSUED, $issued->status);
    }

    // ----------------------------------------------------------- the override

    /**
     * **Phase 6's exit condition, second half: an override that records who and why.**
     *
     * §12: "a system with no override is a system people work around with a spreadsheet, and then the register is
     * decorative". So the override exists — and it carries a name, a time and a mandatory sentence.
     */
    public function test_an_override_records_who_and_why_and_lets_the_certificate_issue(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        $approver = $this->makeUser('Administrator', 'override@test.local');
        $this->actingAs($approver);

        $certificate = $this->certificate('2026-08-31');

        $overridden = $this->compliance->override(
            $certificate,
            'Renewal confirmed by broker in writing; certificate to follow. Payment released against that undertaking.',
        );

        $this->assertNotNull($overridden->compliance_override_at);
        $this->assertSame($approver->getKey(), $overridden->compliance_override_by);
        $this->assertStringContainsString('broker in writing', $overridden->compliance_override_reason);

        $issued = $this->certification->issue($overridden);

        $this->assertSame(PaymentCertificate::STATUS_ISSUED, $issued->status);
    }

    /** The reason is the record that somebody took the risk knowingly, so an empty one is refused. */
    public function test_an_override_without_a_reason_is_refused(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        $certificate = $this->certificate('2026-08-31');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->compliance->override($certificate, '   ');
    }

    /** Overriding nothing would put a decision on the file about a risk nobody took. */
    public function test_an_override_with_nothing_blocking_is_refused(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-12-31']);

        $certificate = $this->certificate('2026-08-31');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nothing is blocking');

        $this->compliance->override($certificate, 'Belt and braces.');
    }

    /**
     * The override clears **this** certificate and no other.
     *
     * Certifying past lapsed cover once is a judgement about one month. Recording it on the compliance document would
     * silently clear every later certificate too, and the second month's decision would never be taken by anybody.
     */
    public function test_an_override_does_not_clear_the_next_certificate(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        $first = $this->certificate('2026-08-31', 40);
        $this->certification->issue($this->compliance->override($first, 'Renewal in progress.'));

        $second = $this->certificate('2026-09-30', 60);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be certified');

        $this->certification->issue($second);
    }

    /**
     * Waiving is a standing decision about a document; overriding is about one payment.
     *
     * Both are needed. A waiver for a subcontractor who will never hold professional indemnity should not have to be
     * re-argued every month, and a monthly override would be indistinguishable from a control nobody applies.
     */
    public function test_a_waived_document_satisfies_its_requirement_and_says_why(): void
    {
        $this->require('training_matrix');
        $document = $this->document('training_matrix', ['expires_on' => null, 'verified_at' => null]);

        $this->compliance->waive($document, 'Two-man specialist erector; competence evidenced by trade certificates.');

        $this->assertSame('waived', $document->refresh()->statusOn('2026-08-01'));
        $this->assertSame([], $this->compliance->blockers($this->contract, '2026-08-31'));
        $this->assertStringContainsString('specialist erector', $document->waiver_reason);
        $this->assertSame(auth()->id(), $document->waived_by);
    }

    // ------------------------------------------------------------ the warning

    /**
     * Once per threshold crossed, never once per day.
     *
     * A job that mails the same warning for thirty days trains somebody to filter it, and then the one that mattered
     * is filtered too. The stored threshold is what makes the run speak only when the answer changes.
     */
    public function test_the_expiry_warning_speaks_once_per_threshold(): void
    {
        $document = $this->document('general_liability', ['expires_on' => now()->addDays(45)->toDateString()]);

        $due = $this->compliance->dueForWarning();
        $this->assertCount(1, $due);
        $this->assertSame(60, $due->first()['threshold']);

        $this->compliance->markWarned($document, 60);

        // Nothing has changed, so nothing is said.
        $this->assertCount(0, $this->compliance->dueForWarning());

        // Now it has crossed 30, which is a different answer and worth another mail.
        $document->update(['expires_on' => now()->addDays(20)->toDateString()]);

        $due = $this->compliance->dueForWarning();
        $this->assertCount(1, $due);
        $this->assertSame(30, $due->first()['threshold']);
    }

    /** A waived document is not chased, and a document with no expiry has no deadline to chase. */
    public function test_the_warning_ignores_waived_and_perpetual_documents(): void
    {
        $waived = $this->document('general_liability', ['expires_on' => now()->addDays(10)->toDateString()]);
        $this->compliance->waive($waived, 'Self-insured; board minute 14/26.');

        $this->document('trade_licence', ['expires_on' => null]);

        $this->assertCount(0, $this->compliance->dueForWarning());
    }

    /**
     * The command mails whoever maintains the register, and marks the document either way.
     *
     * Marked even when nobody holds the permission: a company with no recipient would otherwise re-report the same
     * document every night for ever, and the backlog would bury the first real one.
     */
    public function test_the_command_warns_and_records_that_it_warned(): void
    {
        Notification::fake();

        $document = $this->document('general_liability', ['expires_on' => now()->addDays(5)->toDateString()]);

        $this->assertStringContainsString('General liability', $this->runWarningCommand());
        $this->assertSame(7, $document->refresh()->expiry_notified_at_days);

        Notification::assertSentTo(
            User::holdingPermission('ConstructionComplianceUpdate')->get(),
            ComplianceDocumentExpiring::class,
        );

        // A second run is silent, which is the whole point of the column.
        $this->assertStringContainsString('newly crossed', $this->runWarningCommand());
    }

    // ------------------------------------------------------------- the screens

    /**
     * The register shows the computed status, not a column.
     *
     * Worth a screen test rather than trusting the model test: the whole failure §12 describes is a screen that says
     * everything is fine, so the screen is where the assertion belongs.
     */
    public function test_the_register_shows_the_computed_status(): void
    {
        $this->document('general_liability', ['expires_on' => now()->subDays(30)->toDateString()]);

        Livewire::test(ListComplianceDocuments::class)
            ->assertSuccessful()
            ->assertSee('General liability')
            ->assertSee('expired')
            ->assertSee('30 days ago');
    }

    /** Verifying from the register is the action that turns a filed document into a satisfied requirement. */
    public function test_the_verify_action_records_that_somebody_read_it(): void
    {
        $document = $this->document('general_liability', ['verified_at' => null, 'verified_by' => null]);

        Livewire::test(ListComplianceDocuments::class)
            ->callTableAction('verify', $document);

        $this->assertNotNull($document->refresh()->verified_at);
        $this->assertSame(auth()->id(), $document->verified_by);
    }

    /** The requirements register is what makes the rule settable without a developer. */
    public function test_the_requirements_register_distinguishes_the_template(): void
    {
        $this->require('general_liability', ['grace_days' => 14]);
        $this->require('bond', ['contract_id' => $this->contract->getKey()]);

        Livewire::test(ListComplianceRequirements::class)
            ->assertSuccessful()
            ->assertSee('Company template')
            ->assertSee($this->contract->contract_number)
            ->assertSee('14 days')
            ->assertSee('certification');
    }

    /**
     * The override is offered on the certificate that is blocked, and only there.
     *
     * A permanently visible override is one people reach for out of habit. Appearing with the refusal is what makes it
     * a decision about a known risk rather than a button beside every payment.
     */
    public function test_the_override_action_appears_only_where_something_is_blocking(): void
    {
        $this->require('general_liability');
        $clean = $this->certificate('2026-08-31');

        Livewire::test(ListPaymentCertificates::class)
            ->assertActionVisible(TestAction::make('overrideCompliance')->table($clean));

        // Now nothing blocks, and the button goes away.
        $this->document('general_liability', ['expires_on' => '2026-12-31']);

        Livewire::test(ListPaymentCertificates::class)
            ->assertActionHidden(TestAction::make('overrideCompliance')->table($clean));
    }

    /** And using it writes the reason, which is the whole record. */
    public function test_the_override_action_records_the_reason(): void
    {
        $this->require('general_liability');
        $this->document('general_liability', ['expires_on' => '2026-06-30']);

        $certificate = $this->certificate('2026-08-31');

        Livewire::test(ListPaymentCertificates::class)
            ->callTableAction('overrideCompliance', $certificate, [
                'reason' => 'Broker undertaking on file; renewal certificate expected.',
            ]);

        $certificate->refresh();

        $this->assertNotNull($certificate->compliance_override_at);
        $this->assertStringContainsString('Broker undertaking', $certificate->compliance_override_reason);

        // And the register says so where somebody looking at the payment will see it.
        Livewire::test(ListPaymentCertificates::class)->assertSee('Overridden');
    }

    // ------------------------------------------------------------- the module

    /** A company without the module gets no schedule work done against its database. */
    public function test_the_command_skips_a_company_without_the_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())
            ->where('module', 'construction_contracts')
            ->update(['enabled' => false]);
        modules()->flush();

        $this->document('general_liability', ['expires_on' => now()->addDays(5)->toDateString()]);

        $this->assertStringContainsString('Skipping', $this->runWarningCommand());
    }

    /**
     * The warning command's output.
     *
     * `handle()` directly rather than through `artisan()`: the command is `TenantAware`, and running it through the
     * kernel makes it switch tenant databases, which this suite's connection layout cannot do. The tenant is already
     * current, which is the state the scheduler puts it in anyway.
     */
    private function runWarningCommand(): string
    {
        $command = new CheckComplianceExpiry;
        $command->setLaravel($this->app);

        $input = new ArrayInput([], new InputDefinition([
            new InputOption('date', null, InputOption::VALUE_OPTIONAL),
        ]));
        $buffer = new BufferedOutput;

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        $this->assertSame(0, $command->handle($this->compliance));

        return $buffer->fetch();
    }

    /**
     * The claim is a fixture detail, but it has to be here: a certificate with no claim has nothing to certify, and
     * the refusal tests would then be asserting the wrong exception.
     */
    public function test_the_fixture_certificate_is_a_real_draft_certificate(): void
    {
        $certificate = $this->certificate('2026-08-31');

        $this->assertSame(PaymentCertificate::STATUS_DRAFT, $certificate->status);
        $this->assertEquals(400_000, $certificate->gross_work_to_date);
        $this->assertSame(ProgressClaim::STATUS_SUBMITTED, $certificate->progressClaim->status);
    }
}
