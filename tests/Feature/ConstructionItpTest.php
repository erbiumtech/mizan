<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\Pages\ListInspections;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\Pages\ListItps;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Services\InspectionService;
use App\Modules\ConstructionQhse\Services\ItpService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * ITPs and inspections — §17.1, Phase 10a.
 *
 * **`point_type` is the entire reason an ITP exists**, and the tests below are mostly about taking that seriously.
 * §17.1: "a **hold** point means work may not proceed past it; a **witness** point means a party is invited and work may
 * proceed if they do not attend; a **review** point is documentation only. Collapsing them into a checkbox turns the
 * document into a formality."
 *
 * Four properties follow from it:
 *
 *  - **The point type is snapshotted onto the inspection at request time.** An ITP gets revised and a hold point becomes
 *    a witness point; an inspection carried out under the old plan was carried out under the old rules. Reading the
 *    current plan would retroactively change what an inspection *meant* — §8's certificate snapshot and §13's
 *    notice-day snapshot are the same argument.
 *  - **Releasing a hold point is a separate act with a separate permission.** §17.1: "a hold point that releases nothing
 *    and blocks nothing is a checkbox with extra steps."
 *  - **A hold point that names nobody cannot be issued.** The pivot exists because "who must attend" is what a hold
 *    point answers, and a hold point waiting for no-one is a stoppage nobody can clear.
 *  - **A witness invited who did not attend is the contractor's protection**, and it has to be recorded rather than
 *    inferred, because nobody writes it down at the time.
 */
class ConstructionItpTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private ItpService $plans;

    private InspectionService $inspections;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'itp@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_qhse'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->plans = app(ItpService::class);
        $this->inspections = app(InspectionService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function itp(array $attributes = []): Itp
    {
        return $this->plans->draft($this->job, array_merge([
            'reference' => 'ITP-CIV-001',
            'title' => 'In-situ concrete',
            'discipline' => 'Civil',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function point(Itp $itp, array $attributes = []): ItpActivity
    {
        return $this->plans->addActivity($itp, array_merge([
            'activity_description' => 'Reinforcement prior to pour',
            'reference_standard' => 'BS EN 1992',
            'acceptance_criteria' => 'Cover 40 mm minimum, laps to schedule',
            'point_type' => ItpActivity::POINT_HOLD,
            'notice_hours' => 24,
        ], $attributes));
    }

    /** A plan in force with one hold point that names the Engineer. */
    private function issuedPlan(): array
    {
        $itp = $this->itp();
        $point = $this->point($itp);
        $this->plans->addParty($point, ['party' => 'engineer', 'role' => 'witnesses', 'attendance_mandatory' => true]);

        return [$this->plans->issue($itp->refresh()), $point->refresh()];
    }

    // ------------------------------------------------------------------ the plan is a controlled document

    public function test_a_plan_needs_a_reference_and_a_title(): void
    {
        foreach ([['reference' => ' '], ['title' => ' ']] as $override) {
            try {
                $this->itp($override);
                $this->fail('An unnameable plan should be refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('quoted in correspondence', $e->getMessage());
            }
        }
    }

    public function test_a_point_needs_a_description_and_a_point_type(): void
    {
        $itp = $this->itp();

        foreach ([
            [['activity_description' => ' '], 'needs describing'],
            [['point_type' => 'maybe'], 'needs a point type'],
        ] as [$override, $expected]) {
            try {
                $this->point($itp, $override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /** Sequenced in tens, so a point can be inserted between two later without renumbering the plan. */
    public function test_points_are_sequenced_automatically(): void
    {
        $itp = $this->itp();

        $this->assertSame(10, $this->point($itp)->sequence);
        $this->assertSame(20, $this->point($itp->refresh(), ['activity_description' => 'Formwork'])->sequence);
    }

    /**
     * **A hold point that names nobody cannot be issued.**
     *
     * The refusal names the sequence numbers, because "hold point 10 names nobody" is actionable where "cannot issue"
     * is not. And it is refused at *issue* rather than at row level: a plan halfway through being written legitimately
     * has a hold point with no parties yet.
     */
    public function test_a_hold_point_with_no_party_blocks_issue(): void
    {
        $itp = $this->itp();
        $point = $this->point($itp);

        try {
            $this->plans->issue($itp->refresh());
            $this->fail('A hold point naming nobody should block issue.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Hold point(s) 10 name nobody', $e->getMessage());
            $this->assertStringContainsString('stoppage waiting for no-one', $e->getMessage());
        }

        $this->plans->addParty($point, ['party' => 'engineer', 'role' => 'witnesses']);

        $this->assertSame(Itp::STATUS_ISSUED, $this->plans->issue($itp->refresh())->status);
    }

    /** A review point naming nobody is fine — it is documentation only. */
    public function test_a_review_point_needs_no_party(): void
    {
        $itp = $this->itp();
        $this->point($itp, ['point_type' => ItpActivity::POINT_REVIEW, 'notice_hours' => null]);

        $this->assertSame(Itp::STATUS_ISSUED, $this->plans->issue($itp->refresh())->status);
    }

    public function test_an_empty_plan_cannot_be_issued(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An ITP with no points is a cover sheet');

        $this->plans->issue($this->itp());
    }

    /** Issuing sets revision A where nobody set one, so a plan always says which revision it is. */
    public function test_issuing_sets_the_first_revision(): void
    {
        [$itp] = $this->issuedPlan();

        $this->assertSame('A', $itp->revision);
        $this->assertNotNull($itp->issued_on);
        $this->assertTrue($itp->isInForce());
        $this->assertFalse($itp->isEditable());
    }

    /** An issued plan is frozen: people are working to it. */
    public function test_an_issued_plan_takes_no_edits_or_new_points(): void
    {
        [$itp] = $this->issuedPlan();

        try {
            $this->plans->update($itp, ['title' => 'Something else']);
            $this->fail('An issued plan should be frozen.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Revise it instead', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('takes no new rows');

        $this->point($itp);
    }

    public function test_approving_happens_after_issue_not_instead_of_it(): void
    {
        $itp = $this->itp();
        $point = $this->point($itp);
        $this->plans->addParty($point, ['party' => 'engineer', 'role' => 'approves']);

        try {
            $this->plans->approve($itp->refresh());
            $this->fail('A draft should not be approvable.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('issued first and approved second', $e->getMessage());
        }

        $approved = $this->plans->approve($this->plans->issue($itp->refresh()));

        $this->assertTrue($approved->isApproved());
        $this->assertNotNull($approved->approved_by);
        $this->assertNotNull($approved->approved_on);
    }

    /**
     * **Revising is a new plan, not an edit** — which is what lets a past inspection keep citing the plan it was
     * carried out under.
     */
    public function test_revising_copies_the_points_and_supersedes_the_old_plan(): void
    {
        [$itp, $point] = $this->issuedPlan();
        $this->plans->addParty($point, ['party' => 'third_party_lab', 'role' => 'verifies']);

        $next = $this->plans->revise($itp->refresh());

        $this->assertSame('B', $next->revision);
        $this->assertSame(Itp::STATUS_DRAFT, $next->status);
        $this->assertSame(Itp::STATUS_SUPERSEDED, $itp->refresh()->status);

        $next->load('activities.parties');

        $this->assertCount(1, $next->activities);
        $this->assertSame(ItpActivity::POINT_HOLD, $next->activities->first()->point_type);
        // Both parties travelled: the pivot is what a hold point means, so a revision that dropped it would lose it.
        $this->assertCount(2, $next->activities->first()->parties);
    }

    /**
     * **A party is a party *and* a role**, because one point can need the Engineer to approve and a laboratory to
     * verify.
     */
    public function test_a_point_can_name_several_parties_with_different_roles(): void
    {
        $itp = $this->itp();
        $point = $this->point($itp);

        $this->plans->addParty($point, ['party' => 'engineer', 'role' => 'witnesses', 'attendance_mandatory' => true]);
        $this->plans->addParty($point, ['party' => 'third_party_lab', 'role' => 'verifies']);
        $this->plans->addParty($point, ['party' => 'employer', 'role' => 'approves', 'party_label' => 'Client rep']);

        $point->refresh()->load('parties');

        $this->assertCount(3, $point->parties);
        $this->assertTrue($point->hasMandatoryAttendance());
        // As entered, because the relation is ordered — an ITP is a document somebody compares with last month's copy.
        $this->assertSame([
            'Engineer witnesses (mandatory)',
            'Third-party laboratory verifies',
            'Employer approves',
        ], $point->parties->map(fn ($party): string => $party->describe())->all());
    }

    public function test_a_party_needs_a_party_and_a_role(): void
    {
        $point = $this->point($this->itp());

        foreach ([['party' => 'nobody', 'role' => 'witnesses'], ['party' => 'engineer', 'role' => 'watches']] as $bad) {
            try {
                $this->plans->addParty($point, $bad);
                $this->fail('An unknown party or role should be refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('pivot rather than a column', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ the snapshot

    /**
     * **The point type and notice period are frozen onto the inspection.**
     *
     * The test then revises the plan so the point becomes a review point, and asserts the inspection still says hold —
     * because an inspection carried out under the old plan was carried out under the old rules.
     */
    public function test_the_point_type_is_snapshotted_at_request_time(): void
    {
        [$itp, $point] = $this->issuedPlan();

        $inspection = $this->inspections->requestAgainst($point, ['requested_on' => '2026-08-20']);

        $this->assertSame(ItpActivity::POINT_HOLD, $inspection->point_type);
        $this->assertSame(24, $inspection->notice_hours);
        $this->assertSame('INS-1', $inspection->reference);
        $this->assertSame('Reinforcement prior to pour', $inspection->activity_description);

        // The plan is revised and the point downgraded.
        $next = $this->plans->revise($itp->refresh());
        $next->activities()->update(['point_type' => ItpActivity::POINT_REVIEW]);

        $this->assertSame(
            ItpActivity::POINT_HOLD,
            $inspection->refresh()->point_type,
            'a revision cannot change what an inspection already meant',
        );
        $this->assertTrue($inspection->isHoldPoint());
    }

    /** An inspection cannot cite a plan nobody has issued. */
    public function test_an_inspection_against_a_draft_plan_is_refused(): void
    {
        $itp = $this->itp();
        $point = $this->point($itp);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a certification audit reads for');

        $this->inspections->requestAgainst($point);
    }

    /** Nor against a point somebody has retired. */
    public function test_an_inspection_against_an_inactive_point_is_refused(): void
    {
        [, $point] = $this->issuedPlan();
        $point->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer active');

        $this->inspections->requestAgainst($point->refresh());
    }

    /** **Ad-hoc inspections exist**, which §17.1 requires: a client asking to see a detail is an inspection. */
    public function test_an_ad_hoc_inspection_needs_no_plan_row(): void
    {
        $inspection = $this->inspections->request($this->job, [
            'activity_description' => 'Client walk-round of level 4 finishes',
            'point_type' => ItpActivity::POINT_REVIEW,
        ]);

        $this->assertTrue($inspection->isAdHoc());
        $this->assertSame('INS-1', $inspection->reference);
    }

    public function test_an_inspection_needs_a_description_and_a_known_point_type(): void
    {
        foreach ([
            [['activity_description' => ' ', 'point_type' => 'review'], 'what is being inspected'],
            [['activity_description' => 'Something', 'point_type' => 'guess'], 'needs a point type'],
        ] as [$attributes, $expected]) {
            try {
                $this->inspections->request($this->job, $attributes);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ the hold point actually holds

    /** **Recording a result never releases a hold point.** Two decisions, and on a certified site two people. */
    public function test_recording_a_pass_does_not_release_the_hold_point(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->requestAgainst($point);

        $inspection = $this->inspections->record($inspection, [
            'status' => Inspection::STATUS_PASSED,
            'inspected_on' => '2026-08-22',
            // Even asked for directly, the release is not written by `record()`.
            'released_hold_point' => true,
        ]);

        $this->assertTrue($inspection->wasAccepted());
        $this->assertFalse($inspection->released_hold_point);
        $this->assertTrue($inspection->awaitingRelease());
        $this->assertTrue($inspection->blocksWork(), 'work may not proceed until somebody releases it');
    }

    public function test_releasing_records_who_and_when_and_lets_work_proceed(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->record(
            $this->inspections->requestAgainst($point),
            ['status' => Inspection::STATUS_PASSED, 'inspected_on' => '2026-08-22'],
        );

        $released = $this->inspections->release($inspection, 'Pour may proceed.');

        $this->assertTrue($released->released_hold_point);
        $this->assertNotNull($released->released_by);
        $this->assertNotNull($released->released_at);
        $this->assertFalse($released->blocksWork());
        $this->assertFalse($released->awaitingRelease());
    }

    /** The three refusals, and each is the mechanism rather than a rule about it. */
    public function test_a_release_is_refused_on_anything_that_is_not_a_passed_hold_point(): void
    {
        [, $point] = $this->issuedPlan();

        // Not inspected yet.
        $open = $this->inspections->requestAgainst($point);

        try {
            $this->inspections->release($open);
            $this->fail('An uninspected hold point should not release.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('what the hold point exists to prevent', $e->getMessage());
        }

        // Failed.
        $failed = $this->inspections->record($open, [
            'status' => Inspection::STATUS_FAILED, 'inspected_on' => '2026-08-22',
        ]);

        try {
            $this->inspections->release($failed);
            $this->fail('A failed hold point should not release.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('the work is not right yet', $e->getMessage());
        }

        // Not a hold point at all.
        $review = $this->inspections->record(
            $this->inspections->request($this->job, [
                'activity_description' => 'Records check',
                'point_type' => ItpActivity::POINT_REVIEW,
            ]),
            ['status' => Inspection::STATUS_PASSED],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('never blocked work');

        $this->inspections->release($review);
    }

    public function test_a_release_cannot_happen_twice(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->record(
            $this->inspections->requestAgainst($point),
            ['status' => Inspection::STATUS_PASSED],
        );
        $this->inspections->release($inspection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was released on');

        $this->inspections->release($inspection->refresh());
    }

    public function test_a_recorded_inspection_cannot_be_recorded_again(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->record(
            $this->inspections->requestAgainst($point),
            ['status' => Inspection::STATUS_PASSED],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not evidence of anything');

        $this->inspections->record($inspection, ['status' => Inspection::STATUS_FAILED]);
    }

    /** Passed-with-comments releases work, because the fabricator starts and the comments become actions. */
    public function test_passed_with_comments_can_release_a_hold_point(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->record(
            $this->inspections->requestAgainst($point),
            ['status' => Inspection::STATUS_PASSED_WITH_COMMENTS, 'result_notes' => 'Two ties to be added.'],
        );

        $this->assertTrue($this->inspections->release($inspection)->released_hold_point);
    }

    // ------------------------------------------------------------------ the witness point

    /**
     * **Invited and did not attend** — the fact that lets work proceed past a witness point.
     *
     * Recorded rather than inferred from a name being filled in, and the notice date is separate from the request date
     * because a notice period runs from when the other party was told.
     */
    public function test_a_witness_invited_who_did_not_attend_is_recorded(): void
    {
        [$itp] = $this->issuedPlan();
        $witnessPlan = $this->plans->revise($itp->refresh());
        $witnessPlan->activities()->update(['point_type' => ItpActivity::POINT_WITNESS, 'notice_hours' => 48]);
        $this->plans->issue($witnessPlan->refresh());

        $point = $witnessPlan->activities()->firstOrFail();

        $inspection = $this->inspections->requestAgainst($point, ['requested_on' => '2026-08-18']);
        $inspection = $this->inspections->notify($inspection, '2026-08-19', '2026-08-22');

        $this->assertSame('2026-08-19', $inspection->notified_on->toDateString());
        $this->assertSame(Inspection::STATUS_SCHEDULED, $inspection->status);

        $inspection = $this->inspections->record($inspection, [
            'status' => Inspection::STATUS_PASSED,
            'inspected_on' => '2026-08-22',
            'witness_attended' => false,
        ]);

        $this->assertTrue($inspection->witnessFailedToAttend());
        // Three days' notice against a 48-hour requirement.
        $this->assertTrue($inspection->noticeGivenInFull());
        $this->assertFalse($inspection->blocksWork(), 'a witness point never blocked work');

        $this->assertCount(1, $this->inspections->witnessedInAbsence($this->job));
    }

    /** Short notice is said out loud, because it is the other side's first answer. */
    public function test_notice_shorter_than_the_plan_requires_is_visible(): void
    {
        [, $point] = $this->issuedPlan();

        $inspection = $this->inspections->notify(
            $this->inspections->requestAgainst($point, ['requested_on' => '2026-08-22']),
            '2026-08-22',
        );
        $inspection = $this->inspections->record($inspection, [
            'status' => Inspection::STATUS_PASSED,
            'inspected_on' => '2026-08-22',
        ]);

        $this->assertFalse($inspection->noticeGivenInFull(), 'nothing like 24 hours');
    }

    /** An honest "cannot tell" where either half is missing, rather than a false accusation. */
    public function test_notice_cannot_be_judged_without_both_dates(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->requestAgainst($point);

        $this->assertNull($inspection->noticeGivenInFull());
    }

    // ------------------------------------------------------------------ the reports

    /** **Hold points awaiting release** — work standing still, and the query the register is built around. */
    public function test_hold_points_awaiting_release_are_reported_oldest_first(): void
    {
        [, $point] = $this->issuedPlan();

        foreach (['2026-08-20', '2026-08-15'] as $date) {
            $inspection = $this->inspections->requestAgainst($point);
            $this->inspections->record($inspection, [
                'status' => Inspection::STATUS_PASSED, 'inspected_on' => $date,
            ]);
        }

        $waiting = $this->inspections->awaitingRelease($this->job);

        $this->assertCount(2, $waiting);
        $this->assertSame('2026-08-15', $waiting->first()->inspected_on->toDateString());
        $this->assertSame(7, $waiting->first()->daysAwaitingRelease('2026-08-22'));
    }

    /** Hold points not yet inspected are the other half of "work standing still". */
    public function test_uninspected_hold_points_are_reported_separately(): void
    {
        [, $point] = $this->issuedPlan();
        $this->inspections->requestAgainst($point);

        $this->assertCount(1, $this->inspections->blockingWork($this->job));
        $this->assertCount(0, $this->inspections->awaitingRelease($this->job));
    }

    /**
     * §17.6's leading indicator: **hold points released at the first attempt**.
     *
     * Null rather than a percentage where nothing has been inspected — 100% first-time on a job with no inspections is
     * the most flattering wrong answer available, which is §17.6's whole complaint.
     */
    public function test_the_first_time_hold_point_rate_is_null_until_something_is_inspected(): void
    {
        [$itp, $point] = $this->issuedPlan();

        $this->assertNull($this->inspections->firstTimeHoldPointRate($this->job));

        // One passed first time.
        $this->inspections->record(
            $this->inspections->requestAgainst($point),
            ['status' => Inspection::STATUS_PASSED, 'inspected_on' => '2026-08-20'],
        );

        $this->assertSame(100.0, $this->inspections->firstTimeHoldPointRate($this->job));

        // A second point that failed and then passed: two decisions, one of them not first time.
        $second = $this->itp(['reference' => 'ITP-CIV-002']);
        $secondPoint = $this->point($second, ['activity_description' => 'Formwork prior to pour']);
        $this->plans->addParty($secondPoint, ['party' => 'engineer', 'role' => 'witnesses']);
        $this->plans->issue($second->refresh());

        $this->inspections->record(
            $this->inspections->requestAgainst($secondPoint->refresh()),
            ['status' => Inspection::STATUS_FAILED, 'inspected_on' => '2026-08-21'],
        );
        $this->inspections->record(
            $this->inspections->requestAgainst($secondPoint->refresh()),
            ['status' => Inspection::STATUS_PASSED, 'inspected_on' => '2026-08-23'],
        );

        // Three decided: one first-time pass, one failure, one pass on a row that had failed.
        $this->assertSame(33.3, $this->inspections->firstTimeHoldPointRate($this->job));
    }

    /** The check sheet keeps values, and an unfilled line is not a failure. */
    public function test_a_check_sheet_keeps_expected_against_actual(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->requestAgainst($point);

        $passed = $this->inspections->addCheck($inspection, [
            'description' => 'Cover to reinforcement',
            'expected_value' => '40', 'actual_value' => '43', 'unit' => 'mm', 'passed' => true,
        ]);
        $failed = $this->inspections->addCheck($inspection, [
            'description' => 'Lap length', 'expected_value' => '600', 'actual_value' => '480',
            'unit' => 'mm', 'passed' => false,
        ]);
        $unchecked = $this->inspections->addCheck($inspection, ['description' => 'Tie spacing']);

        $this->assertSame('40 mm expected, 43 mm measured', $passed->describe());
        $this->assertTrue($failed->hasFailed());
        // The one that matters: nobody has reached this line, which is not a failure.
        $this->assertTrue($unchecked->isOutstanding());
        $this->assertFalse($unchecked->hasFailed());

        $this->assertCount(1, $inspection->refresh()->load('checks')->failedChecks());
    }

    public function test_a_check_needs_describing(): void
    {
        [, $point] = $this->issuedPlan();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs describing');

        $this->inspections->addCheck($this->inspections->requestAgainst($point), ['expected_value' => '40']);
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_itp_register_shows_the_hold_point_count_and_issues(): void
    {
        $itp = $this->itp();
        $point = $this->point($itp);
        $this->plans->addParty($point, ['party' => 'engineer', 'role' => 'witnesses']);

        Livewire::test(ListItps::class)
            ->assertCanSeeTableRecords([$itp])
            ->assertSee('ITP-CIV-001')
            ->callAction(TestAction::make('issue')->table($itp));

        $this->assertSame(Itp::STATUS_ISSUED, $itp->refresh()->status);
    }

    public function test_the_issue_action_surfaces_the_unnamed_hold_point_refusal(): void
    {
        $itp = $this->itp();
        $this->point($itp);

        Livewire::test(ListItps::class)->callAction(TestAction::make('issue')->table($itp));

        $this->assertSame(Itp::STATUS_DRAFT, $itp->refresh()->status, 'the refusal held');
    }

    public function test_the_inspection_register_records_a_result_and_then_releases(): void
    {
        [, $point] = $this->issuedPlan();
        $inspection = $this->inspections->requestAgainst($point);

        Livewire::test(ListInspections::class)
            ->assertCanSeeTableRecords([$inspection])
            ->assertSee('INS-1')
            ->callAction(TestAction::make('record')->table($inspection), [
                'status' => Inspection::STATUS_PASSED,
                'inspected_on' => '2026-08-22',
                'witness_attended' => true,
            ]);

        $this->assertTrue($inspection->refresh()->awaitingRelease());

        Livewire::test(ListInspections::class)
            ->assertSee('awaiting release')
            ->callAction(TestAction::make('release')->table($inspection), ['notes' => 'Pour may proceed.']);

        $this->assertTrue($inspection->refresh()->released_hold_point);
    }

    /** The module works with only the spine and itself — §18. */
    public function test_the_module_works_with_only_the_spine_and_itself(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', [
                'construction_costing', 'construction_contracts', 'construction_field', 'invoicing', 'accounting',
            ])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        [, $point] = $this->issuedPlan();

        // The free-text label carries the attending party without contact records.
        $this->plans->addParty($point, ['party' => 'engineer', 'role' => 'approves', 'party_label' => 'Ove Arup']);

        $inspection = $this->inspections->record(
            $this->inspections->requestAgainst($point->refresh()),
            ['status' => Inspection::STATUS_PASSED, 'witness_label' => 'A. Engineer'],
        );

        $this->assertSame('A. Engineer', $inspection->witnessName());
        $this->assertTrue($this->inspections->release($inspection)->released_hold_point);
    }
}
