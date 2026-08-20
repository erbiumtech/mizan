<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\Pages\ListRetentionMovements;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaimLine;
use App\Modules\ConstructionContracts\Models\RetentionMovement;
use App\Modules\ConstructionContracts\Services\CertificationService;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionContracts\Services\RetentionService;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The retention ledger — `docs/construction-management-plan.md` §11, Phase 4d.
 *
 * **The balance is the sum of the movements and is never stored.** Everything here follows from that: holding is a
 * consequence of issuing a certificate, releasing and forfeiting are decisions that write their own rows, and
 * correcting one is another row rather than an edit.
 *
 * Two rules carry money.
 *
 *  - **The release rule is a contract field, not a consequence of the contract family.** A FIDIC contract with a
 *    negotiated single-stage release is ordinary, and reading `contract_standard` at release time would overrule
 *    what the parties agreed.
 *  - **A zero punch-list holdback explains itself in words.** §18.1 names this as one of two places where
 *    "smaller, never broken" is not enough: a silent zero looks exactly like a job with nothing outstanding, and
 *    releasing the balance on a job with fifty open punch items is money that does not come back.
 */
class ConstructionRetentionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contract $contract;

    private RetentionService $retention;

    private CertificationService $certification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'retention@test.local'));
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
            // Lifted out of the way: the cap has its own test in the certification suite, and leaving it at 5%
            // here would make two rules argue inside every assertion below.
            'retention_limit_percent' => 100,
            'retention_first_release_pct' => 50,
            'payment_terms_days' => 56,
            'defects_period_days' => 365,
        ]);

        $contracts->addItem($this->contract, [
            'item_no' => '1', 'description' => 'The works', 'scheduled_value' => 10_000_000,
        ]);
        $contracts->execute($this->contract);
        $this->contract->refresh();

        $this->retention = app(RetentionService::class);
        $this->certification = app(CertificationService::class);
    }

    /** Certify the works to a percentage, which is what holds retention. */
    private function certifyTo(float $percent, string $periodEnd = '2026-08-31'): PaymentCertificate
    {
        $claim = $this->certification->openClaim($this->contract, $periodEnd);
        $claim->lines()->first()->update([
            'measurement_input' => ProgressClaimLine::INPUT_PERCENT,
            'cumulative_percent' => $percent,
        ]);
        $submitted = $this->certification->submitClaim($claim->refresh());

        $certificate = $this->certification->prepare($this->contract, $periodEnd, $submitted);

        return $this->certification->issue($certificate, $periodEnd);
    }

    // ------------------------------------------------------------------ holding

    /**
     * Issuing a certificate writes the movement — through `RetentionService`, which §11 makes the only writer.
     *
     * The link back to the deduction row is what lets the reconciliation compare the two registers line by line
     * rather than in total.
     */
    public function test_issuing_a_certificate_holds_retention_in_the_ledger(): void
    {
        $certificate = $this->certifyTo(30);

        $movement = RetentionMovement::query()->where('contract_id', $this->contract->getKey())->firstOrFail();

        $this->assertSame(RetentionMovement::KIND_HELD, $movement->kind);
        $this->assertEquals(300_000, $movement->amount, '10% of 3,000,000, and positive because it is held');
        $this->assertEquals(3_000_000, $movement->basis_gross);
        $this->assertEquals(10, $movement->rate_applied);
        $this->assertSame($certificate->getKey(), $movement->payment_certificate_id);
        $this->assertNotNull($movement->certificate_deduction_id, 'linked to the deduction row it corresponds to');
        $this->assertSame(300_000.0, $this->retention->balance($this->contract));
    }

    public function test_each_certificate_holds_only_its_own_movement(): void
    {
        $this->certifyTo(30, '2026-08-31');
        $this->certifyTo(60, '2026-09-30');

        $this->assertSame(2, RetentionMovement::query()->count());
        // 300,000 then 300,000 more.
        $this->assertSame(600_000.0, $this->retention->balance($this->contract));
    }

    /** Retention is held by issuing, never by hand — otherwise two registers each hold their own version. */
    public function test_a_held_movement_cannot_be_written_by_hand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not by hand');

        $this->retention->record($this->contract, RetentionMovement::KIND_HELD, 100_000, 'Because');
    }

    public function test_a_draft_certificate_holds_nothing(): void
    {
        $claim = $this->certification->openClaim($this->contract, '2026-08-31');
        $claim->lines()->first()->update(['cumulative_percent' => 30]);
        $certificate = $this->certification->prepare(
            $this->contract,
            '2026-08-31',
            $this->certification->submitClaim($claim->refresh()),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('while it is a draft');

        $this->retention->recordFromCertificate($certificate);
    }

    /** The cap is the reason a movement can be smaller than rate × basis, and the row says so. */
    public function test_the_cap_is_recorded_on_the_movement(): void
    {
        $this->contract->update(['retention_limit_percent' => 2]);

        $this->certifyTo(50);

        $movement = RetentionMovement::query()->firstOrFail();

        // 10% of 5,000,000 would be 500,000; the 2% limit of the contract sum caps it at 200,000.
        $this->assertEquals(200_000, $movement->amount);
        $this->assertTrue($movement->cap_reached);
    }

    // ------------------------------------------------------------------ releasing

    /**
     * The two-stage rule: half at taking-over, the rest at the end of the defects period.
     *
     * The second date is computed from completion plus the period in days, so an extension of time moves it
     * without anybody remembering to.
     */
    public function test_the_two_stage_schedule_names_both_dates(): void
    {
        $this->certifyTo(100);
        $this->contract->update(['practical_completion_date' => '2027-03-31']);

        $schedule = $this->retention->schedule($this->contract->refresh());

        $this->assertSame(RetentionMovement::STAGE_FIRST_RELEASE, $schedule[0]['stage']);
        $this->assertSame('2027-03-31', $schedule[0]['due_on']);
        $this->assertSame(500_000.0, $schedule[0]['amount'], 'half of the 1,000,000 held');

        $this->assertSame(RetentionMovement::STAGE_FINAL_RELEASE, $schedule[1]['stage']);
        $this->assertSame('2028-03-30', $schedule[1]['due_on'], 'completion plus 365 days, computed');
        $this->assertSame(500_000.0, $schedule[1]['amount']);
    }

    /**
     * With no completion date the schedule says **why** it is not due, rather than showing nothing.
     *
     * "Nothing is due" and "we cannot tell yet" are different sentences, and only one of them is true here.
     */
    public function test_with_no_completion_date_the_schedule_says_why(): void
    {
        $this->certifyTo(100);

        $schedule = $this->retention->schedule($this->contract);

        $this->assertNull($schedule[0]['due_on']);
        $this->assertStringContainsString('Not yet due', $schedule[0]['note']);
        $this->assertStringContainsString('taking-over certificate', $schedule[0]['note']);
    }

    public function test_releasing_writes_a_negative_movement_and_moves_the_balance(): void
    {
        $this->certifyTo(100);
        $this->contract->update(['practical_completion_date' => now()->subDay()->toDateString()]);

        $released = $this->retention->release($this->contract->refresh());

        $this->assertSame(RetentionMovement::KIND_RELEASED, $released->kind);
        $this->assertEquals(-500_000, $released->amount);
        $this->assertSame(500_000.0, $this->retention->balance($this->contract));
    }

    /** And the same stage cannot be released twice: the share already released is taken off. */
    public function test_the_first_release_cannot_be_taken_twice(): void
    {
        $this->certifyTo(100);
        $this->contract->update(['practical_completion_date' => now()->subDay()->toDateString()]);
        $this->retention->release($this->contract->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to release');

        $this->retention->release($this->contract->refresh());
    }

    /** An early release is a decision with a name on it, not a date check to wave through. */
    public function test_an_early_release_needs_a_reason(): void
    {
        $this->certifyTo(100);
        $this->contract->update(['practical_completion_date' => now()->addMonths(6)->toDateString()]);

        try {
            $this->retention->release($this->contract->refresh());
            $this->fail('An early release with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not yet due', $e->getMessage());
            $this->assertStringContainsString('sectional taking-over', $e->getMessage());
        }

        $movement = $this->retention->release(
            $this->contract->refresh(),
            RetentionMovement::STAGE_FIRST_RELEASE,
            null,
            'Sectional taking-over of tower A, clause 14.9.',
        );

        // Recorded as an early release rather than as the stage asked for: what happened, not what was intended.
        $this->assertSame(RetentionMovement::STAGE_EARLY_RELEASE, $movement->stage);
        $this->assertStringContainsString('14.9', $movement->reason);
    }

    public function test_releasing_more_than_is_held_is_refused(): void
    {
        $this->certifyTo(100);
        $this->contract->update(['practical_completion_date' => now()->subDay()->toDateString()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would exceed the');

        $this->retention->release($this->contract->refresh(), RetentionMovement::STAGE_FIRST_RELEASE, 2_000_000);
    }

    /**
     * A single-stage FIDIC contract releases in one go.
     *
     * The rule is its own column, so this contract keeps FIDIC vocabulary and a negotiated release — which is
     * ordinary, and which reading the standard at release time would have overruled.
     */
    public function test_the_release_rule_and_not_the_contract_family_decides(): void
    {
        $this->contract->update([
            'retention_release_rule' => Contract::RELEASE_SINGLE_STAGE,
            'practical_completion_date' => now()->subDay()->toDateString(),
        ]);
        $this->certifyTo(100);

        $schedule = $this->retention->schedule($this->contract->refresh());

        $this->assertCount(1, $schedule);
        $this->assertSame(1_000_000.0, $schedule[0]['amount']);
        $this->assertSame('fidic', $this->contract->contract_standard, 'still a FIDIC contract');
    }

    /**
     * **The exit condition of this sub-phase.** A zero holdback says so in words.
     *
     * §18.1: without the site operations module the open punch value is *unknown*, not nil, and a silent zero
     * looks exactly like a job with nothing outstanding.
     */
    public function test_the_aia_holdback_is_zero_with_the_reason_stated(): void
    {
        $this->contract->update([
            'retention_release_rule' => Contract::RELEASE_AIA_SUBSTANTIAL,
            'practical_completion_date' => now()->subDay()->toDateString(),
        ]);
        $this->certifyTo(100);

        $schedule = $this->retention->schedule($this->contract->refresh());

        $this->assertSame(1_000_000.0, $schedule[0]['amount'], 'the whole balance, holdback zero');
        // Stated whatever is licensed: Phase 9a licensed `construction_field` for the notice clock while punch
        // lists remain unbuilt, so a module-licence guard here would have silenced the reason and left a bare zero.
        $this->assertStringContainsString('unknown rather than nil', $schedule[0]['note']);
        $this->assertStringContainsString('not recorded yet', $schedule[0]['note']);
    }

    // ------------------------------------------------------------------ decisions

    public function test_a_forfeit_reduces_the_balance_and_keeps_its_reason(): void
    {
        $this->certifyTo(100);

        $movement = $this->retention->record(
            $this->contract,
            RetentionMovement::KIND_FORFEITED,
            150_000,
            'Roof flashing not corrected within the notice period.',
        );

        $this->assertEquals(-150_000, $movement->amount, 'signed here, so a positive figure cannot add money');
        $this->assertSame(850_000.0, $this->retention->balance($this->contract));
        $this->assertStringContainsString('flashing', $movement->reason);
    }

    public function test_a_bond_substitution_takes_the_cash_out_of_the_ledger(): void
    {
        $this->certifyTo(100);

        $this->retention->record(
            $this->contract,
            RetentionMovement::KIND_SUBSTITUTED_BY_BOND,
            1_000_000,
            'Retention bond from Habib Bank, ref RB-2026-114.',
        );

        $this->assertSame(0.0, $this->retention->balance($this->contract));
    }

    public function test_a_movement_that_needs_a_reason_is_refused_without_one(): void
    {
        $this->certifyTo(100);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->retention->record($this->contract, RetentionMovement::KIND_ADJUSTED, 10_000, '  ');
    }

    public function test_a_movement_cannot_take_the_balance_below_zero(): void
    {
        $this->certifyTo(30);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the');

        $this->retention->record($this->contract, RetentionMovement::KIND_FORFEITED, 400_000, 'Too much.');
    }

    // ------------------------------------------------------------------ reconciliation

    public function test_the_ledger_agrees_with_the_certificates(): void
    {
        $this->certifyTo(30, '2026-08-31');
        $this->certifyTo(60, '2026-09-30');

        $result = $this->retention->reconcile($this->contract);

        $this->assertSame(600_000.0, $result['held']);
        $this->assertSame(600_000.0, $result['certified']);
        $this->assertSame(0.0, $result['difference']);
        $this->assertTrue($result['explained']);
    }

    /**
     * A release makes the balance differ from the certified figure, and that is not a discrepancy.
     *
     * The reconciliation compares **held** movements with the certificates for exactly this reason: comparing the
     * balance would report a difference on every job that has ever released anything, which is a report people
     * stop reading.
     */
    public function test_a_release_does_not_look_like_a_discrepancy(): void
    {
        $this->certifyTo(100);
        $this->contract->update(['practical_completion_date' => now()->subDay()->toDateString()]);
        $this->retention->release($this->contract->refresh());

        $result = $this->retention->reconcile($this->contract);

        $this->assertSame(1_000_000.0, $result['held']);
        $this->assertSame(500_000.0, $result['balance'], 'half of it has gone back');
        $this->assertTrue($result['explained']);
    }

    /** And a hand-written held-like movement does show up, which is the point of running it nightly. */
    public function test_a_reinstatement_shows_as_a_difference_to_be_explained(): void
    {
        $this->certifyTo(30);

        $this->retention->record(
            $this->contract,
            RetentionMovement::KIND_REINSTATED,
            50_000,
            'Reinstated after the defect was accepted as pre-existing.',
        );

        $result = $this->retention->reconcile($this->contract);

        $this->assertSame(350_000.0, $result['balance']);
        $this->assertSame(300_000.0, $result['held']);
        $this->assertSame(300_000.0, $result['certified']);
        $this->assertTrue($result['explained'], 'a reinstatement is not a held movement, so the comparison holds');
    }

    /**
     * The command reports rather than throwing: a check that fails stops running.
     *
     * `handle()` directly rather than through `artisan()`, following `PayComponentReconciliationTest`'s note: the
     * command is `TenantAware`, and that trait's `execute()` iterates every tenant and switches database for each,
     * which this suite cannot do running against one in-memory database. `handle()` is where the command's own
     * decisions live; the tenant loop is the package's.
     */
    public function test_the_reconciliation_command_reports_and_succeeds(): void
    {
        $this->certifyTo(30);

        [$code, $output] = $this->runReconciliation();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('agree on every contract', $output);
    }

    /**
     * And a difference **warns without failing**, because a command that failed would stop running and a register
     * nobody reconciles is the state this check exists to find.
     */
    public function test_a_difference_warns_and_still_succeeds(): void
    {
        $certificate = $this->certifyTo(30);
        // A ledger that disagrees with the certificates: the movement is edited behind the service's back, which
        // is what a bug or a hand-written row looks like from here.
        RetentionMovement::query()->where('payment_certificate_id', $certificate->getKey())
            ->update(['amount' => 275_000]);

        [$code, $output] = $this->runReconciliation();

        $this->assertSame(0, $code, 'a failing exit code would take the scheduler down with it');
        $this->assertStringContainsString('DIFFERS', $output);
        $this->assertStringContainsString('none of the three fixes itself', $output);
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function runReconciliation(): array
    {
        $command = new \App\Modules\ConstructionContracts\Console\Commands\ReconcileRetention;
        $command->setLaravel($this->app);

        $input = new \Symfony\Component\Console\Input\ArrayInput(
            [],
            new \Symfony\Component\Console\Input\InputDefinition([
                new \Symfony\Component\Console\Input\InputOption('contract', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL),
            ]),
        );
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;

        $command->setInput($input);
        $command->setOutput(new \Illuminate\Console\OutputStyle($input, $buffer));

        return [$command->handle($this->retention), $buffer->fetch()];
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_register_renders_with_the_balance_as_the_total(): void
    {
        $this->certifyTo(30);

        Livewire::test(ListRetentionMovements::class)
            ->assertSuccessful()
            ->assertSee('Held')
            ->assertSee('300,000.00');
    }

    public function test_the_release_action_writes_the_movement(): void
    {
        $this->certifyTo(100);
        $this->contract->update(['practical_completion_date' => now()->subDay()->toDateString()]);

        Livewire::test(ListRetentionMovements::class)
            ->callAction('release', [
                'contract_id' => $this->contract->getKey(),
                'stage' => RetentionMovement::STAGE_FIRST_RELEASE,
            ]);

        $this->assertSame(500_000.0, $this->retention->balance($this->contract->refresh()));
    }

    public function test_the_ledger_has_no_edit_or_delete(): void
    {
        $this->certifyTo(30);

        $this->assertSame([], \App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\RetentionMovementResource::getPages()['edit'] ?? []);
        // A movement is an event: the correction is another movement, which is the cost ledger's discipline too.
        $this->assertFalse(
            array_key_exists('edit', \App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\RetentionMovementResource::getPages()),
        );
    }
}
