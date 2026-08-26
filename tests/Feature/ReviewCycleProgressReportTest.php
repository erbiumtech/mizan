<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Filament\Pages\ReviewCycleProgress;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\OneToOne;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Review cycle progress — `docs/reports-expansion-plan.md` Phase 3.12.
 *
 * The plan asks for "reviews and goals complete per cycle, one-to-ones held", and *complete* is where the
 * tests concentrate, because `Review` has five rungs and only the last is finished:
 *
 *  - **a closed cycle holding unshared reviews** is the sharpest finding on the report. `isVisibleToEmployee()`
 *    is `shared_at !== null`, so a review at *manager submitted* was written about somebody who never saw it —
 *    and it looks like completed work on every other screen in the application;
 *  - **a closed cycle with open goals** is the same failure in the other column, and it matters that `missed`
 *    is one of `Goal`'s settled states: leaving a goal open is nobody having decided, not a kindness;
 *  - **a cycle with no one-to-ones** means the reviews in it were written with no conversation behind them.
 *
 * Each finding is tested at both ends of a cycle, because an unshared review in an *open* cycle is work in
 * progress and the identical row in a closed one is work abandoned.
 */
class ReviewCycleProgressReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['performance', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function employee(string $name = 'Ayesha'): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-'.mb_substr(md5($name.microtime()), 0, 5),
            'name' => $name,
            'date_of_joining' => '2024-01-01',
            'status' => 1,
        ]);
    }

    private function cycle(
        string $name = 'H1 2026-27',
        string $status = ReviewCycle::STATUS_OPEN,
        string $start = '2026-07-01',
        string $end = '2026-12-31',
    ): ReviewCycle {
        return ReviewCycle::create([
            'name' => $name,
            'period_start' => $start,
            'period_end' => $end,
            'status' => $status,
        ]);
    }

    private function review(ReviewCycle $cycle, string $status, ?string $sharedAt = null): Review
    {
        return Review::create([
            'review_cycle_id' => $cycle->getKey(),
            'employee_id' => $this->employee()->getKey(),
            'status' => $status,
            'shared_at' => $sharedAt,
        ]);
    }

    private function goal(ReviewCycle $cycle, string $status): Goal
    {
        return Goal::create([
            'review_cycle_id' => $cycle->getKey(),
            'employee_id' => $this->employee()->getKey(),
            'title' => 'Ship the thing',
            'status' => $status,
        ]);
    }

    private function meeting(string $metOn): OneToOne
    {
        $employee = $this->employee();

        return OneToOne::create([
            'employee_id' => $employee->getKey(),
            'manager_employee_id' => $this->employee('Manager')->getKey(),
            'met_on' => $metOn,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('ReviewCycleProgress', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for ReviewCycleProgress');

        return $payload;
    }

    private function cell(array $payload, string $column, int $row = 0): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "there is no {$column} column");

        return $payload['rows'][$row][$index];
    }

    // ────────────────────────────────────── the three counts the plan asks for ──

    /** Reviews, goals and one-to-ones per cycle. */
    public function test_a_cycle_reports_its_reviews_goals_and_meetings(): void
    {
        $cycle = $this->cycle();
        $this->review($cycle, Review::STATUS_ACKNOWLEDGED, '2026-12-20 10:00:00');
        $this->review($cycle, Review::STATUS_MANAGER_SUBMITTED);
        $this->goal($cycle, Goal::STATUS_ACHIEVED);
        $this->goal($cycle, Goal::STATUS_OPEN);
        $this->meeting('2026-09-15');

        $payload = $this->report();

        $this->assertSame('H1 2026-27', $this->cell($payload, 'Cycle'));
        $this->assertSame('2026-07-01 – 2026-12-31', $this->cell($payload, 'Period'));
        $this->assertSame('2', $this->cell($payload, 'Reviews'));
        $this->assertSame('1', $this->cell($payload, 'Acknowledged'));
        $this->assertSame('2', $this->cell($payload, 'Goals'));
        $this->assertSame('1', $this->cell($payload, 'Settled'));
        $this->assertSame('1', $this->cell($payload, 'One-to-ones'));
    }

    /** A zero count is a dash — five count columns of noughts is unreadable. */
    public function test_a_zero_count_shows_a_dash(): void
    {
        $this->cycle();

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Reviews'));
        $this->assertSame('—', $this->cell($payload, 'Goals'));
        $this->assertSame('—', $this->cell($payload, 'One-to-ones'));
    }

    /**
     * Only *acknowledged* counts as a finished review.
     *
     * Shared is the manager's job done; acknowledged is the person having read it. A completeness figure that
     * counted shared would report a cycle as finished while half the company had not looked at their review.
     */
    public function test_only_acknowledged_reviews_count_as_complete(): void
    {
        $cycle = $this->cycle();

        foreach ([
            Review::STATUS_PENDING,
            Review::STATUS_SELF_SUBMITTED,
            Review::STATUS_MANAGER_SUBMITTED,
        ] as $status) {
            $this->review($cycle, $status);
        }

        $this->review($cycle, Review::STATUS_SHARED, '2026-12-20 10:00:00');

        $payload = $this->report();

        $this->assertSame('4', $this->cell($payload, 'Reviews'));
        $this->assertSame('—', $this->cell($payload, 'Acknowledged'));
        $this->assertSame(0.0, $payload['tiles'][0]['value']);
        $this->assertStringContainsString('0 OF 4 REVIEWS ACKNOWLEDGED', $payload['note']);
    }

    /**
     * A missed goal is a settled goal.
     *
     * `Goal` has *missed* among its states on purpose, so counting only achieved ones would report an honest
     * miss as unfinished work and quietly reward nobody deciding anything.
     */
    public function test_a_missed_goal_counts_as_decided(): void
    {
        $cycle = $this->cycle();
        $this->goal($cycle, Goal::STATUS_ACHIEVED);
        $this->goal($cycle, Goal::STATUS_MISSED);
        $this->goal($cycle, Goal::STATUS_DROPPED);
        $this->goal($cycle, Goal::STATUS_OPEN);

        $payload = $this->report();

        $this->assertSame('3', $this->cell($payload, 'Settled'));
        $this->assertStringContainsString('3 OF 4 GOALS DECIDED', $payload['note']);
    }

    /**
     * A goal belonging to no cycle is not counted against one.
     *
     * `review_cycle_id` is nullable — a standing objective need not belong to a cycle — and attributing those
     * to whichever cycle happens to be open would make a cycle answerable for goals nobody set in it.
     */
    public function test_a_goal_with_no_cycle_is_not_counted(): void
    {
        $this->cycle();

        Goal::create([
            'review_cycle_id' => null,
            'employee_id' => $this->employee()->getKey(),
            'title' => 'A standing objective',
            'status' => Goal::STATUS_OPEN,
        ]);

        $this->assertSame('—', $this->cell($this->report(), 'Goals'));
    }

    // ─────────────────────── a review nobody shared, in a closed cycle ──

    /**
     * The report's sharpest finding: a closed cycle holding a review the person never saw.
     *
     * Somebody wrote a review of a person, the cycle was closed, and `isVisibleToEmployee()` is still false.
     * On every other screen a review at *manager submitted* looks like completed work.
     */
    public function test_a_closed_cycle_with_unshared_reviews_is_called_out(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_CLOSED);
        $this->review($cycle, Review::STATUS_MANAGER_SUBMITTED);
        $this->review($cycle, Review::STATUS_ACKNOWLEDGED, '2026-12-20 10:00:00');
        // A conversation, so this test is about the unshared review and not also about missing meetings.
        $this->meeting('2026-09-15');

        $payload = $this->report();

        $this->assertSame('Closed · 1 never shared', $this->cell($payload, 'Standing'));
        $this->assertSame(1.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString(
            'ONE REVIEW IN A CLOSED CYCLE WAS NEVER SHARED WITH THE PERSON IT IS ABOUT',
            $payload['note'],
        );
    }

    /** Several read as a sentence, not as a template. */
    public function test_several_unshared_reviews_read_as_a_sentence(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_CLOSED);
        $this->review($cycle, Review::STATUS_MANAGER_SUBMITTED);
        $this->review($cycle, Review::STATUS_PENDING);

        $payload = $this->report();

        $this->assertStringContainsString(
            '2 REVIEWS IN CLOSED CYCLES WERE NEVER SHARED WITH THE PEOPLE THEY ARE ABOUT',
            $payload['note'],
        );
    }

    /**
     * The same row in an open cycle is work in progress, not a failure.
     *
     * The identical fact means opposite things at the two ends of a cycle, which is why the suffix is raised
     * only on a closed one.
     */
    public function test_an_unshared_review_in_an_open_cycle_is_not_a_finding(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_OPEN);
        $this->review($cycle, Review::STATUS_MANAGER_SUBMITTED);
        $this->meeting('2026-09-15');

        $payload = $this->report();

        $this->assertSame('Open', $this->cell($payload, 'Standing'));
        $this->assertSame(0.0, $payload['tiles'][1]['value']);
        $this->assertStringNotContainsString('NEVER SHARED', $payload['note']);
    }

    /** Nor is one in a cycle still being calibrated — the ratings are not settled yet. */
    public function test_an_unshared_review_while_calibrating_is_not_a_finding(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_CALIBRATING);
        $this->review($cycle, Review::STATUS_MANAGER_SUBMITTED);
        $this->meeting('2026-09-15');

        $payload = $this->report();

        $this->assertSame('Calibrating', $this->cell($payload, 'Standing'));
        $this->assertSame(0.0, $payload['tiles'][1]['value']);
    }

    /**
     * Sharing is judged on `shared_at`, not on the status label.
     *
     * `isVisibleToEmployee()` reads the timestamp, so the timestamp is what decides whether the person can
     * see their own review. A status of *shared* with no timestamp is not shared, whatever it says.
     */
    public function test_sharing_is_judged_on_the_timestamp_not_the_status(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_CLOSED);
        $review = $this->review($cycle, Review::STATUS_SHARED, sharedAt: null);
        $this->meeting('2026-09-15');

        $this->assertFalse($review->isVisibleToEmployee(), 'the model agrees');
        $this->assertSame(1.0, $this->report()['tiles'][1]['value']);
        $this->assertSame('Closed · 1 never shared', $this->cell($this->report(), 'Standing'));
    }

    /** A closed cycle where everything was shared says only that it is closed. */
    public function test_a_cleanly_closed_cycle_stands_clean(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_CLOSED);
        $this->review($cycle, Review::STATUS_ACKNOWLEDGED, '2026-12-20 10:00:00');
        $this->goal($cycle, Goal::STATUS_ACHIEVED);
        $this->meeting('2026-09-15');

        $this->assertSame('Closed', $this->cell($this->report(), 'Standing'));
    }

    // ────────────────────── a goal nobody decided, in a closed cycle ──

    /** A closed cycle with open goals is nobody having decided. */
    public function test_a_closed_cycle_with_open_goals_is_called_out(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_CLOSED);
        $this->review($cycle, Review::STATUS_ACKNOWLEDGED, '2026-12-20 10:00:00');
        $this->goal($cycle, Goal::STATUS_OPEN);
        $this->goal($cycle, Goal::STATUS_OPEN);
        $this->meeting('2026-09-15');

        $payload = $this->report();

        $this->assertSame('Closed · 2 goals undecided', $this->cell($payload, 'Standing'));
        $this->assertStringContainsString('2 GOALS LEFT UNDECIDED IN CLOSED CYCLES', $payload['note']);
    }

    /** An open goal in a running cycle is just an open goal. */
    public function test_an_open_goal_in_a_running_cycle_is_not_a_finding(): void
    {
        $cycle = $this->cycle(status: ReviewCycle::STATUS_OPEN);
        $this->goal($cycle, Goal::STATUS_OPEN);

        $payload = $this->report();

        $this->assertSame('Open', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('UNDECIDED', $payload['note']);
    }

    // ──────────────────────────────── one-to-ones held ──

    /**
     * One-to-ones are counted inside the cycle's own period, not the report's.
     *
     * `one_to_ones` has no cycle column: the only thing tying a meeting to a cycle is the date falling inside
     * it. So a conversation held after the cycle ended was not a conversation about that cycle.
     */
    public function test_meetings_are_counted_inside_the_cycles_own_period(): void
    {
        $this->cycle(start: '2026-07-01', end: '2026-12-31');
        $this->meeting('2026-07-01');
        $this->meeting('2026-09-15');
        $this->meeting('2026-12-31');
        // Before the cycle began — and inside the report's period, since the fiscal year starts on the same
        // day. A bound on only one end would still have counted this, which is what a surviving mutation said.
        $this->meeting('2026-06-30');
        // After the cycle ended, also inside the report's period.
        $this->meeting('2027-01-20');

        $this->assertSame('3', $this->cell($this->report(), 'One-to-ones'), 'both ends are inclusive bounds');
    }

    /**
     * A cycle with reviews but no conversations is stated.
     *
     * The reviews in it were written with nothing behind them, which is the thing "one-to-ones held" is
     * actually asking about.
     */
    public function test_a_cycle_with_reviews_but_no_meetings_is_called_out(): void
    {
        $cycle = $this->cycle();
        $this->review($cycle, Review::STATUS_MANAGER_SUBMITTED);

        $payload = $this->report();

        $this->assertSame('Open · no one-to-ones', $this->cell($payload, 'Standing'));
        $this->assertStringContainsString('1 CYCLE HAD NO ONE-TO-ONES AT ALL', $payload['note']);
    }

    /** A cycle with no reviews yet has nothing to have talked about, so it is not flagged. */
    public function test_a_cycle_with_no_reviews_is_not_flagged_for_missing_meetings(): void
    {
        $this->cycle();

        $payload = $this->report();

        $this->assertSame('Open', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('NO ONE-TO-ONES AT ALL', $payload['note']);
    }

    /** The total is reported whether or not anything is flagged. */
    public function test_the_note_states_how_many_meetings_were_held(): void
    {
        $this->cycle();
        $this->meeting('2026-09-15');
        $this->meeting('2026-10-15');

        $this->assertStringContainsString('2 ONE-TO-ONES HELD', $this->report()['note']);
    }

    // ─────────────────────────────────── which cycles appear ──

    /** A draft cycle has no progress to report. */
    public function test_a_draft_cycle_is_not_listed(): void
    {
        $this->cycle(status: ReviewCycle::STATUS_DRAFT);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO REVIEW CYCLE WAS RUNNING IN THIS PERIOD', $payload['note']);
    }

    /**
     * A cycle is included if it *overlaps* the period, not if it fits inside it.
     *
     * A cycle running July to December belongs in this year's report from the day it starts.
     */
    public function test_a_cycle_overlapping_the_period_is_included(): void
    {
        // Starts before the financial year and ends inside it.
        $this->cycle(start: '2026-04-01', end: '2026-09-30');

        $this->assertCount(1, $this->report()['rows']);
    }

    /** One that ended before the financial year belongs to last year's report. */
    public function test_a_cycle_that_ended_before_the_period_is_not_listed(): void
    {
        $this->cycle(start: '2025-07-01', end: '2026-06-30');

        $this->assertSame([], $this->report()['rows']);
    }

    /** And one that has not started by the date being read has not started. */
    public function test_a_cycle_starting_after_the_date_is_not_listed(): void
    {
        $this->cycle(start: '2027-04-01', end: '2027-09-30');

        $this->assertSame([], $this->report()['rows']);
    }

    /** The most recent cycle is first. */
    public function test_the_most_recent_cycle_is_first(): void
    {
        $this->cycle('Older', start: '2026-07-01', end: '2026-12-31');
        $this->cycle('Newer', start: '2027-01-01', end: '2027-06-30');

        $payload = $this->report();

        $this->assertSame('Newer', $this->cell($payload, 'Cycle', 0));
        $this->assertSame('Older', $this->cell($payload, 'Cycle', 1));
    }

    // ──────────────────────────────────── totals and shape ──

    /** The footer totals every count column. */
    public function test_the_footer_totals_every_count(): void
    {
        $one = $this->cycle('One', start: '2026-07-01', end: '2026-09-30');
        $this->review($one, Review::STATUS_ACKNOWLEDGED, '2026-09-20 10:00:00');
        $this->goal($one, Goal::STATUS_ACHIEVED);

        $two = $this->cycle('Two', start: '2026-10-01', end: '2026-12-31');
        $this->review($two, Review::STATUS_PENDING);
        $this->goal($two, Goal::STATUS_OPEN);

        $payload = $this->report();
        $at = fn (string $column): string => $payload['footer'][array_search($column, $payload['columns'], true)];

        $this->assertSame('Total — 2 cycles', $payload['footer'][0]);
        $this->assertSame('2', $at('Reviews'));
        $this->assertSame('1', $at('Acknowledged'));
        $this->assertSame('2', $at('Goals'));
        $this->assertSame('1', $at('Settled'));
    }

    /** Eight columns and a standing carrying sentences, so it scrolls — Phase 0.2. */
    public function test_the_table_is_marked_wide(): void
    {
        $this->cycle();

        $this->assertTrue($this->report()['wide']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->review($this->cycle(), Review::STATUS_ACKNOWLEDGED, '2026-12-20 10:00:00');

        $onThePage = Livewire::test(ReviewCycleProgress::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame(
            $onThePage,
            app(ReportPaneRenderer::class)->for('ReviewCycleProgress', self::AS_OF, false, []),
        );
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(ReviewCycleProgress::canAccess());
    }
}
