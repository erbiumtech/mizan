<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Models\ReviewRating;
use App\Modules\Performance\Models\ReviewTemplate;
use App\Modules\Performance\Services\ReviewCycleService;
use App\Modules\Performance\Services\ReviewTemplates;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\InterviewFeedback;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Services\Interviews;
use App\Notifications\RecordChanged;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Review templates with weighted competencies, and interviews with a panel —
 * docs/saas-launch-plan.md §14.6. Both still write nothing until approved.
 */
class AppraisalDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Notification::fake();
        $this->actingAs($this->makeUser('Administrator', 'appr-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'performance', 'recruitment', 'payroll', 'accounting', 'mpr'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->manager = Employee::create(['employee_id' => 'EMP-M', 'name' => 'Imran', 'gender' => 'Male', 'is_active' => 1]);
        $this->employee = Employee::create(['employee_id' => 'EMP-R', 'name' => 'Sana', 'gender' => 'Female', 'is_active' => 1, 'manager_id' => $this->manager->getKey(), 'date_of_joining' => '2024-01-01']);
    }

    private function template(array $weights = ['Quality' => 50, 'Teamwork' => 30, 'Initiative' => 20]): ReviewTemplate
    {
        $template = ReviewTemplate::create(['name' => 'Annual', 'is_default' => true]);
        $sort = 0;

        foreach ($weights as $name => $weight) {
            $template->competencies()->create(['name' => $name, 'weight' => $weight, 'sort' => $sort++]);
        }

        return $template->refresh();
    }

    // ────────────────────────────────────────────────────────── competencies ──

    public function test_a_template_is_used_only_when_its_weights_add_up_and_opening_a_cycle_applies_it(): void
    {
        $wrong = $this->template(['Quality' => 60, 'Teamwork' => 30]);
        $this->assertSame('The weights add up to 90, not 100.', $wrong->weightProblem());

        try {
            app(ReviewTemplates::class)->apply(new Review(['review_cycle_id' => 1, 'employee_id' => $this->employee->id]), $wrong);
            $this->fail('A template with broken weights was applied.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('add up to 90', $e->getMessage());
        }

        $wrong->competencies()->create(['name' => 'Initiative', 'weight' => 10, 'sort' => 3]);
        $this->assertNull($wrong->fresh()->weightProblem());

        $cycle = ReviewCycle::create(['name' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        app(ReviewCycleService::class)->open($cycle);

        $review = Review::query()->where('employee_id', $this->employee->id)->sole();
        $this->assertSame($wrong->getKey(), (int) $review->review_template_id);
        $this->assertSame(['Quality', 'Teamwork', 'Initiative'], $review->ratings()->with('competency')->get()->pluck('competency.name')->all());
        $this->assertSame($this->manager->getKey(), (int) $review->reviewer_employee_id);
    }

    public function test_the_weighted_rating_lands_on_the_review_and_follows_the_companys_scale(): void
    {
        $template = $this->template();
        $cycle = ReviewCycle::create(['name' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        app(ReviewCycleService::class)->open($cycle);
        $review = Review::query()->where('employee_id', $this->employee->id)->sole();

        $ratings = $review->ratings()->with('competency')->get()->keyBy(fn (ReviewRating $r): string => $r->competency->name);
        $ratings['Quality']->update(['manager_rating' => 5, 'self_rating' => 4]);
        $ratings['Teamwork']->update(['manager_rating' => 3, 'self_rating' => 4]);
        $ratings['Initiative']->update(['manager_rating' => 2, 'self_rating' => 5]);

        $service = app(ReviewTemplates::class);
        $this->assertSame(3.8, $service->weighted($review, 'manager'), '5×50 + 3×30 + 2×20 over 100');
        $this->assertSame(4.2, $service->weighted($review, 'self'));

        $service->syncRatings($review);
        $this->assertSame(4, $review->fresh()->manager_rating, 'rounded to the scale every reader expects');
        $this->assertSame(4, $review->fresh()->self_rating);

        // Half answered reads as the average of what was answered, not as a low score.
        // The manager's own review, raised by the same cycle, with one competency rated.
        $half = Review::query()->where('employee_id', $this->manager->getKey())->sole();
        $this->assertSame($template->getKey(), (int) $half->review_template_id);
        $half->ratings()->first()->update(['manager_rating' => 5]);
        $this->assertSame(5.0, $service->weighted($half->fresh(), 'manager'));

        $this->assertSame(5, ReviewTemplates::scaleMax());
        app(TenantSettings::class)->set('performance.rating_scale', 10);
        $this->assertSame(10, ReviewTemplates::scaleMax());
        $this->assertCount(10, ReviewTemplates::scaleOptions());
    }

    // ──────────────────────────────────── reviewer, increment, promotion ──

    public function test_the_reviewer_can_change_until_it_is_shared_and_nobody_reviews_themselves(): void
    {
        $cycle = ReviewCycle::create(['name' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        $review = Review::create(['review_cycle_id' => $cycle->getKey(), 'employee_id' => $this->employee->getKey(), 'reviewer_employee_id' => $this->manager->getKey(), 'final_rating' => 4, 'submitted_at' => now()]);

        $other = Employee::create(['employee_id' => 'EMP-O', 'name' => 'Owais', 'gender' => 'Male', 'is_active' => 1]);
        app(ReviewCycleService::class)->changeReviewer($review, $other->getKey());
        $this->assertSame($other->getKey(), (int) $review->fresh()->reviewer_employee_id);

        try {
            app(ReviewCycleService::class)->changeReviewer($review->fresh(), $this->employee->getKey());
            $this->fail('Self-review.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('review themselves', $e->getMessage());
        }

        $review->fresh()->update(['shared_at' => now()]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has been shared');
        app(ReviewCycleService::class)->changeReviewer($review->fresh(), $this->manager->getKey());
    }

    public function test_an_increment_given_as_an_amount_raises_the_same_request_and_changes_nothing_itself(): void
    {
        EmployeeSetting::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 100000]);
        $cycle = ReviewCycle::create(['name' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        $review = Review::create(['review_cycle_id' => $cycle->getKey(), 'employee_id' => $this->employee->getKey(), 'final_rating' => 4, 'submitted_at' => now()]);

        try {
            app(ReviewCycleService::class)->proposeIncrementOfAmount($review, 90000, '2026-12-01');
            $this->fail('A cut was proposed as an increment.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not above', $e->getMessage());
        }

        $request = app(ReviewCycleService::class)->proposeIncrementOfAmount($review, 115000, '2026-12-01', auth()->user());

        $this->assertSame(EmployeeChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame(115000.0, round((float) $request->requested_changes['basic_wage'], 2));
        $this->assertSame(100000.0, (float) EmployeeSetting::query()->where('employee_id', $this->employee->id)->orderByDesc('start_date')->first()->basic_wage, 'nothing is applied until it is approved');

        // A promotion recommendation is a note on the review and nothing else.
        $review->update(['promotion_recommended' => true, 'promotion_designation' => 'Senior Executive']);
        $this->assertTrue($review->fresh()->promotion_recommended);
        $this->assertSame(null, $this->employee->fresh()->designation, 'the record is untouched');
    }

    // ───────────────────────────────────────────────────────────── interviews ──

    public function test_scheduling_an_interview_moves_the_stage_tells_the_panel_and_opens_a_form_each(): void
    {
        $vacancy = Vacancy::create(['code' => 'V-1', 'title' => 'Accountant', 'status' => Vacancy::STATUS_OPEN, 'openings' => 1]);
        $applicant = Applicant::create(['name' => 'Hira Shah', 'email' => 'hira@test.local']);
        $application = Application::create(['vacancy_id' => $vacancy->id, 'applicant_id' => $applicant->id, 'stage' => Application::STAGE_SCREENING, 'applied_on' => '2026-11-01']);

        $one = $this->makeUser('Manager', 'panel1@test.local');
        $two = $this->makeUser('Manager', 'panel2@test.local');

        $interview = app(Interviews::class)->schedule($application, '2026-11-10 11:00', [$one->getKey(), $two->getKey()], 'in_person', 'Meeting room 2');

        $this->assertSame(1, $interview->round);
        $this->assertSame(Application::STAGE_INTERVIEW, $application->fresh()->stage);
        $this->assertSame([$one->getKey(), $two->getKey()], $interview->interviewer_user_ids);
        $this->assertSame(2, $interview->feedback()->count(), 'a form each, waiting');
        Notification::assertSentTo($one, RecordChanged::class);
        Notification::assertSentTo($two, RecordChanged::class);

        $this->assertSame(['given' => 0, 'of' => 2, 'average' => null], app(Interviews::class)->feedbackSummary($interview));

        app(Interviews::class)->recordFeedback($interview, $one, ['rating' => 4, 'recommendation' => InterviewFeedback::RECOMMEND_HIRE, 'strengths' => 'Knows the ledger']);
        $this->assertSame(['given' => 1, 'of' => 2, 'average' => 4.0], app(Interviews::class)->feedbackSummary($interview->fresh()));

        $outsider = $this->makeUser('Manager', 'outsider@test.local');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('on the panel');
        app(Interviews::class)->recordFeedback($interview, $outsider, ['rating' => 1]);
    }

    public function test_the_outcome_moves_the_application_only_when_it_is_not_a_pass(): void
    {
        $vacancy = Vacancy::create(['code' => 'V-2', 'title' => 'Clerk', 'status' => Vacancy::STATUS_OPEN, 'openings' => 1]);
        $applicant = Applicant::create(['name' => 'Bilal Khan', 'email' => 'bilal-a@test.local']);
        $application = Application::create(['vacancy_id' => $vacancy->id, 'applicant_id' => $applicant->id, 'stage' => Application::STAGE_SCREENING, 'applied_on' => '2026-11-01']);
        $panelist = $this->makeUser('Manager', 'panel3@test.local');

        $first = app(Interviews::class)->schedule($application, '2026-11-10 11:00', [$panelist->getKey()]);
        app(Interviews::class)->decide($first, Interview::OUTCOME_PASSED, 'Strong');
        $this->assertSame(Application::STAGE_INTERVIEW, $application->fresh()->stage, 'a pass decides nothing by itself');

        $second = app(Interviews::class)->schedule($application->fresh(), '2026-11-14 11:00', [$panelist->getKey()]);
        $this->assertSame(2, $second->round);

        app(Interviews::class)->decide($second, Interview::OUTCOME_NO_SHOW);
        $this->assertSame(Application::STAGE_REJECTED, $application->fresh()->stage);
        $this->assertSame('Did not attend the interview', $application->fresh()->rejected_reason);

        $this->expectException(InvalidArgumentException::class);
        app(Interviews::class)->schedule($application->fresh(), '2026-11-20 11:00', [$panelist->getKey()]);
    }
}
