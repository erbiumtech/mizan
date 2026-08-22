<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\WipSnapshot;
use App\Modules\ConstructionCosting\Policies\CostPeriodPolicy;
use App\Modules\ConstructionCosting\Services\ConstructionGlPostingService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\PeriodCloseService;
use App\Modules\ConstructionCosting\Services\ReconciliationService;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Closing a month — §3.4 and §4.3, Phase 11e.
 *
 * §4.3 turns §4's report into a control with five mechanisms, and three of them are under test here:
 *
 *  2. **"The period cannot be closed while unbalanced."**
 *  3. **"Unless a user holding `ConstructionPeriodForceClose` accepts the difference with a stated reason."**
 *  4. **"A forced close never fudges the ledger. No plug entry, no balancing figure. Both sides stay true and the
 *     difference stays visible in every later period until the cause is fixed."**
 *
 * The fourth is what makes the third safe, and it is asserted in the strong form: after a forced close, not one cost
 * entry and not one journal line has been added.
 *
 * **And two blockers are this phase's rather than the plan's**, both because the failure they prevent is silent *and*
 * permanent: cost still awaiting the general ledger (posting refuses to reach a closed month, so it is orphaned for
 * good), and an unlocked WIP position (which keeps recomputing against a month somebody signed).
 */
class ConstructionPeriodCloseTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labour;

    private CostLedger $ledger;

    private PeriodCloseService $close;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'close@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create([
            'code' => 'J-1',
            'name' => 'Tower',
            'status' => Job::STATUS_IN_PROGRESS,
        ]);
        $this->labour = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);

        $this->ledger = app(CostLedger::class);
        $this->close = app(PeriodCloseService::class);
    }

    private function nominate(): void
    {
        ControlAccount::create([
            'account_id' => Account::firstOrCreate(['code' => '5100'], ['name' => 'Job cost', 'type' => 'expense'])->getKey(),
            'kind' => ControlAccount::KIND_COST,
            'cost_type' => 'labour',
        ]);
        ControlAccount::create([
            'account_id' => Account::firstOrCreate(['code' => '5910'], ['name' => 'Burden absorbed', 'type' => 'liability'])->getKey(),
            'kind' => ControlAccount::KIND_RECOVERY,
            'purpose' => 'labour_burden',
        ]);
    }

    private function burden(float $amount, string $incurredOn = '2026-08-10'): CostEntry
    {
        return $this->ledger->record($this->job, $this->labour, [
            'amount' => $amount,
            'incurred_on' => $incurredOn,
            'is_burden' => true,
            'gl_purpose' => 'labour_burden',
            'description' => 'Burden',
        ]);
    }

    /** A month with cost, posted, reconciled and balanced — the state a close is meant to be reached from. */
    private function readyToClose(string $periodStart = '2026-08-01'): CostPeriod
    {
        $this->nominate();
        $this->burden(50_000, '2026-08-10');
        app(ConstructionGlPostingService::class)->post($periodStart);
        app(ReconciliationService::class)->run($periodStart);

        return CostPeriod::forDate($periodStart);
    }

    private function period(string $periodStart = '2026-08-01'): CostPeriod
    {
        return CostPeriod::forDate($periodStart);
    }

    // ---------------------------------------------------------------- the happy path

    /** A month that passes every check closes, and records what both ledgers said. */
    public function test_a_clean_month_closes_and_records_both_control_totals(): void
    {
        $period = $this->readyToClose();

        $closed = $this->close->close($period);

        $this->assertTrue($closed->isClosed());
        $this->assertSame('50000.00', $closed->jc_control_total);
        $this->assertSame('50000.00', $closed->gl_control_total);
        $this->assertSame('0.00', $closed->difference);
    }

    /**
     * **A balanced close is `reconciled`, not merely `closed`.**
     *
     * §3.4 gives the period three statuses and the third has to mean something stronger than the second. A forced close
     * over a difference is closed and not reconciled, which is exactly the distinction somebody reading the period list
     * needs.
     */
    public function test_a_balanced_month_is_marked_reconciled(): void
    {
        $closed = $this->close->close($this->readyToClose());

        $this->assertSame(CostPeriod::STATUS_RECONCILED, $closed->status);
        $this->assertNotNull($closed->reconciled_at);
    }

    /** Every check passing says so, rather than saying nothing. */
    public function test_a_clean_month_says_every_check_passes(): void
    {
        $checklist = $this->close->checks($this->readyToClose());

        $this->assertTrue($checklist->canClose());
        $this->assertSame([], $checklist->warnings());
        $this->assertSame('Every check passes.', $checklist->describe());
    }

    // ---------------------------------------------------------------- §4.3's second mechanism

    /**
     * **A month nobody has reconciled blocks, and that is worse than an unbalanced one.**
     *
     * §4 opens with "a second ledger that nobody proves is a second ledger that is wrong". A gate that only caught
     * *proved* differences would wave through every company that never runs the report.
     */
    public function test_a_month_that_was_never_reconciled_cannot_be_closed(): void
    {
        $this->nominate();
        $this->burden(50_000);
        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $checklist = $this->close->checks($this->period());

        $this->assertFalse($checklist->canClose());
        $this->assertStringContainsString('never been reconciled', $checklist->describe());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be closed');
        $this->close->close($this->period());
    }

    /** **§4.3's second mechanism, verbatim**: an unbalanced month does not close. */
    public function test_an_unbalanced_month_cannot_be_closed(): void
    {
        $this->nominate();
        // A mirrored entry with no journal behind it: on the job, nowhere in the accounts.
        $this->ledger->record($this->job, $this->labour, [
            'amount' => 90_000,
            'incurred_on' => '2026-08-10',
            'gl_treatment' => CostEntry::GL_MIRRORED,
            'description' => 'A cost with no GL side',
        ]);
        app(ReconciliationService::class)->run('2026-08-01');

        $checklist = $this->close->checks($this->period());

        $this->assertFalse($checklist->canClose());
        $this->assertStringContainsString('do not agree', $checklist->describe());
    }

    /** And an **accepted** difference unblocks it — §4.3's third mechanism, working through the normal door. */
    public function test_an_accepted_difference_lets_the_month_close_normally(): void
    {
        $this->nominate();
        $this->ledger->record($this->job, $this->labour, [
            'amount' => 90_000,
            'incurred_on' => '2026-08-10',
            'gl_treatment' => CostEntry::GL_MIRRORED,
            'description' => 'A cost with no GL side',
        ]);
        $run = app(ReconciliationService::class)->run('2026-08-01');
        app(ReconciliationService::class)->accept($run, 'The supplier journal is being reposted next month.');

        $closed = $this->close->close($this->period());

        $this->assertTrue($closed->isClosed());
        // Closed, not reconciled: the difference was accepted, not corrected.
        $this->assertSame(CostPeriod::STATUS_CLOSED, $closed->status);
        $this->assertSame('-90000.00', $closed->difference, 'and the difference is recorded on the month');
    }

    // ---------------------------------------------------- this phase's two blockers

    /**
     * **Cost still awaiting the general ledger blocks.**
     *
     * `ConstructionGlPostingService` refuses to post into a closed period, so closing a month with pending entries
     * orphans them from the accounts for good — a silent, permanent loss, which is a harder failure than an unexplained
     * difference.
     */
    public function test_pending_cost_blocks_the_close(): void
    {
        $this->nominate();
        $this->burden(50_000);
        app(ReconciliationService::class)->run('2026-08-01');

        $checklist = $this->close->checks($this->period());

        $this->assertFalse($checklist->canClose());
        $this->assertStringContainsString('awaiting the general ledger', $checklist->describe());
        $this->assertStringContainsString('orphans them from the accounts for good', $checklist->describe());
    }

    /** And the blocker names the account that is missing, so the fix is on the screen rather than in somebody's head. */
    public function test_the_pending_blocker_names_the_missing_account(): void
    {
        // Cost account nominated, burden absorption deliberately not.
        ControlAccount::create([
            'account_id' => Account::firstOrCreate(['code' => '5100'], ['name' => 'Job cost', 'type' => 'expense'])->getKey(),
            'kind' => ControlAccount::KIND_COST,
            'cost_type' => 'labour',
        ]);
        $this->burden(412_900);
        app(ReconciliationService::class)->run('2026-08-01');

        $this->assertStringContainsString(
            'First nominate: Labour burden absorbed',
            $this->close->checks($this->period())->describe(),
        );
    }

    /**
     * **An unlocked work-in-progress position blocks.**
     *
     * §4.4's whole exception is that a locked position does not move. One left unlocked against a closed month keeps
     * recomputing from a forecast that has since changed, so the figure a bank was shown and the figure on the screen
     * drift apart with nothing to say when.
     */
    public function test_an_unlocked_wip_position_blocks_the_close(): void
    {
        $period = $this->readyToClose();
        WipSnapshot::create([
            'job_id' => $this->job->getKey(),
            'period_start' => '2026-08-01',
            'percent_complete_method' => WipSnapshot::METHOD_COST_TO_COST,
            'percent_complete' => 50,
        ]);

        $checklist = $this->close->checks($period);

        $this->assertFalse($checklist->canClose());
        $this->assertStringContainsString('still live', $checklist->describe());
    }

    /** A locked one does not. */
    public function test_a_locked_wip_position_does_not_block(): void
    {
        $period = $this->readyToClose();
        WipSnapshot::create([
            'job_id' => $this->job->getKey(),
            'period_start' => '2026-08-01',
            'percent_complete_method' => WipSnapshot::METHOD_COST_TO_COST,
            'percent_complete' => 50,
            'locked_at' => now(),
        ]);

        $this->assertTrue($this->close->checks($period)->canClose());
    }

    /**
     * **A company that computes no positions is not stopped**, which is what makes the blocker proportionate.
     *
     * WIP is used or it is not, and a company using none should not be unable to close a month.
     */
    public function test_a_company_with_no_wip_positions_can_still_close(): void
    {
        $this->assertTrue($this->close->checks($this->readyToClose())->canClose());
    }

    /**
     * A job with a method chosen and no position is a **warning**, not a blocker.
     *
     * A WIP report missing a job is a balance sheet missing a contract — but blocking on it would stop a close for a job
     * somebody has not got round to, and the answer to that is to be told.
     */
    public function test_a_job_expecting_a_position_and_having_none_is_a_warning(): void
    {
        $period = $this->readyToClose();
        $this->job->update(['percent_complete_method' => WipSnapshot::METHOD_SURVEYED]);

        $checklist = $this->close->checks($period);

        $this->assertTrue($checklist->canClose(), 'it does not block');
        $this->assertCount(1, $checklist->warnings());
        $this->assertStringContainsString('balance sheet missing a contract', $checklist->describe());
    }

    // ---------------------------------------------------------------- the warnings

    /** Late costs are named on the way out, because the earlier month's total is not what they belong to. */
    public function test_late_cost_is_named_as_a_warning(): void
    {
        $this->nominate();
        // July closes with nothing in it, then a July cost arrives.
        $july = CostPeriod::forDate('2026-07-01');
        $july->update(['status' => CostPeriod::STATUS_CLOSED, 'closed_at' => now()]);
        $this->burden(20_000, '2026-07-15');

        app(ConstructionGlPostingService::class)->post('2026-08-01');
        app(ReconciliationService::class)->run('2026-08-01');

        $checklist = $this->close->checks($this->period());

        $this->assertTrue($checklist->canClose());
        $this->assertStringContainsString('arrived after the month it belonged to', $checklist->describe());
    }

    /** An accrual never unwound is a warning, because the next month's open will fix it. */
    public function test_an_unrolled_accrual_is_a_warning_rather_than_a_blocker(): void
    {
        $this->nominate();
        $this->ledger->record($this->job, $this->labour, [
            'amount' => 40_000,
            'incurred_on' => '2026-07-10',
            'kind' => CostEntry::KIND_ACCRUAL,
            'gl_treatment' => CostEntry::GL_MEMO,
            'description' => 'An accrual nobody rolled',
        ]);
        app(ReconciliationService::class)->run('2026-08-01');

        $checklist = $this->close->checks($this->period());

        $this->assertTrue($checklist->canClose());
        $this->assertStringContainsString('never rolled', $checklist->describe());
    }

    // ------------------------------------------- §4.3's third and fourth mechanisms

    /** **The forced close, with a reason.** */
    public function test_a_forced_close_records_the_reason(): void
    {
        $this->nominate();
        $this->burden(50_000);

        $closed = $this->close->forceClose($this->period(), 'The client needs the report today.');

        $this->assertTrue($closed->isClosed());
        $this->assertStringContainsString('The client needs the report today.', $closed->notes);
    }

    /**
     * **And what it overrode, in words.**
     *
     * A reason with no facts beside it ("closing anyway, the client needs the report") tells whoever reads it in a year
     * nothing about what was known at the time.
     */
    public function test_a_forced_close_records_what_it_overrode(): void
    {
        $this->nominate();
        $this->burden(412_900);

        $closed = $this->close->forceClose($this->period(), 'Signing off regardless.');

        $this->assertStringContainsString('Overridden — The month has never been reconciled', $closed->notes);
        $this->assertStringContainsString('Overridden — Cost is still awaiting the general ledger', $closed->notes);
        $this->assertStringContainsString('412,900.00', $closed->notes);
    }

    /**
     * **§4.3's fourth mechanism, in the strong form: a forced close never fudges the ledger.**
     *
     * "No plug entry, no balancing figure. Both sides stay true and the difference stays visible in every later period
     * until the cause is fixed."
     */
    public function test_a_forced_close_writes_no_plug_entry_and_no_journal(): void
    {
        $this->nominate();
        $this->burden(412_900);

        $entriesBefore = CostEntry::query()->count();
        $journalsBefore = JournalEntry::query()->count();
        $linesBefore = \App\Modules\Accounting\Models\JournalEntryLine::query()->count();

        $this->close->forceClose($this->period(), 'Signing off regardless.');

        $this->assertSame($entriesBefore, CostEntry::query()->count(), 'no plug cost entry');
        $this->assertSame($journalsBefore, JournalEntry::query()->count(), 'no balancing journal');
        $this->assertSame($linesBefore, \App\Modules\Accounting\Models\JournalEntryLine::query()->count());
    }

    /** And the pending cost is still pending, still on the job, still absent from the accounts. */
    public function test_a_forced_close_leaves_the_underlying_problem_exactly_as_it_was(): void
    {
        $this->nominate();
        $entry = $this->burden(412_900);

        $this->close->forceClose($this->period(), 'Signing off regardless.');

        $this->assertSame(CostEntry::GL_PENDING, $entry->fresh()->gl_treatment);
        $this->assertSame('412900.00', $entry->fresh()->amount);
    }

    /**
     * A forced close is **closed**, never **reconciled**.
     *
     * §3.4's third status has to be stronger than its second, or the period list cannot tell a month that was proved
     * from a month that was signed for.
     */
    public function test_a_forced_close_is_never_marked_reconciled(): void
    {
        $this->nominate();
        $this->burden(412_900);

        $this->assertSame(
            CostPeriod::STATUS_CLOSED,
            $this->close->forceClose($this->period(), 'Signing off regardless.')->status,
        );
    }

    /** Even a forced close records both control totals, so the month somebody doubted is not the least explicable. */
    public function test_a_forced_close_still_records_both_control_totals(): void
    {
        $this->nominate();
        $this->burden(412_900);
        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $closed = $this->close->forceClose($this->period(), 'Signing off regardless.');

        $this->assertSame('412900.00', $closed->jc_control_total);
        $this->assertSame('412900.00', $closed->gl_control_total);
    }

    /** Forcing needs a reason. It is the only thing the act records. */
    public function test_forcing_without_a_reason_is_refused(): void
    {
        $this->nominate();
        $this->burden(50_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');
        $this->close->forceClose($this->period(), '   ');
    }

    /** And a clean month has nothing to force: use the front door. */
    public function test_a_clean_month_cannot_be_forced(): void
    {
        $period = $this->readyToClose();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to force');
        $this->close->forceClose($period, 'Just in case.');
    }

    // ---------------------------------------------------------------- no way back

    /**
     * **There is no reopen and there never will be.**
     *
     * §3.4: reopening a signed-off period "invalidates the WIP snapshot, the client certificate and the GL summary that
     * all depended on that period's total — and silently changing a closed month is the precise failure this whole design
     * exists to prevent."
     */
    public function test_there_is_no_way_back_into_a_closed_month(): void
    {
        $this->close->close($this->readyToClose());

        foreach (['close', 'forceClose'] as $door) {
            try {
                $door === 'close'
                    ? $this->close->close($this->period())
                    : $this->close->forceClose($this->period(), 'Let me back in.');
                $this->fail("{$door} should refuse a closed month");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no reopen', $e->getMessage());
            }
        }

        $this->assertStringNotContainsString(
            'reopen',
            implode(' ', get_class_methods(PeriodCloseService::class)),
            'and there is no method offering one',
        );
    }

    /** Opening a closed month is refused with the same sentence and the alternative it points at. */
    public function test_opening_a_closed_month_is_refused_and_names_the_alternative(): void
    {
        $this->close->close($this->readyToClose());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('earliest open period');
        $this->close->open('2026-08-01');
    }

    // ---------------------------------------------------------------- opening

    /**
     * §4.5's unwind belongs to **open**, not close.
     *
     * "Its own failure mode — the reversal not running, so the accrual and the real invoice both sit in the ledger and
     * the job costs double for a month — is why the reversal belongs to period *open* rather than period close."
     */
    public function test_opening_a_month_unwinds_the_standing_accruals(): void
    {
        $this->ledger->record($this->job, $this->labour, [
            'amount' => 400_000,
            'incurred_on' => '2026-08-20',
            'kind' => CostEntry::KIND_ACCRUAL,
            'gl_purpose' => 'grni',
            'description' => 'Goods received not invoiced',
        ]);

        $run = $this->close->open('2026-09-01');

        $this->assertSame(1, $run->reversedCount);
        $this->assertSame(400_000.0, $run->reversedTotal);
    }

    /** And it creates the month if this is its first act. */
    public function test_opening_creates_the_month(): void
    {
        $this->assertNull(CostPeriod::query()->starting('2026-11-01')->first());

        $this->close->open('2026-11-01');

        $this->assertNotNull(CostPeriod::query()->starting('2026-11-01')->first());
    }

    // ---------------------------------------------------------------- permissions

    /**
     * §4.3's third mechanism is a separate grant, and the policy is where that is decided.
     *
     * Asserted against the class rather than the gate, because `Gate::before` makes an Administrator pass every ability
     * and the assertion would then be about nothing.
     */
    public function test_closing_and_forcing_are_different_grants(): void
    {
        $period = $this->period();
        $policy = app(CostPeriodPolicy::class);

        $surveyor = $this->makeUser('Accountant', 'surveyor4@test.local');
        $manager = $this->makeUser('Manager', 'closer3@test.local');
        $ceo = $this->makeUser('CEO', 'ceo4@test.local');

        $this->assertFalse($policy->close($surveyor, $period));
        $this->assertTrue($policy->close($manager, $period));

        $this->assertFalse($policy->forceClose($manager, $period), 'closing is not forcing');
        $this->assertTrue($policy->forceClose($ceo, $period));
    }

    /** Neither door opens on a month already closed, at the policy level too. */
    public function test_the_policy_closes_both_doors_on_a_closed_month(): void
    {
        $closed = $this->close->close($this->readyToClose());
        $policy = app(CostPeriodPolicy::class);
        $ceo = $this->makeUser('CEO', 'ceo5@test.local');

        $this->assertFalse($policy->close($ceo, $closed));
        $this->assertFalse($policy->forceClose($ceo, $closed));
    }
}
