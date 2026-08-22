<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Services\ReviewCycleService;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Services\HireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Hiring and appraisals — docs/hrms-plan.md §4.4, §4.5, phases 7 and 8.
 *
 * Two assertions carry this file:
 *
 *  - **The hire is all-or-nothing.** A company left with an employee who has no salary
 *    package, or a package with no employee, is worse off than one whose button errored.
 *  - **A rating reaches no payslip.** The suggestion is a suggestion; nothing writes to a
 *    package. §4.5 refuses this for a specific reason — the first disputed rating would
 *    otherwise become a payroll incident.
 */
class RecruitmentAndPerformanceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['recruitment', 'performance', 'employees', 'payroll'] as $module) {
            $this->setModule($module, true);
        }

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30'],
        );
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function makeOffer(array $offerAttributes = []): Offer
    {
        $vacancy = Vacancy::create([
            'code' => 'V-1',
            'title' => 'Accountant',
            'department' => 'Finance',
            'designation' => 'Senior Accountant',
            'employment_type' => 'Permanent',
            'status' => Vacancy::STATUS_OPEN,
        ]);

        $applicant = Applicant::create([
            'name' => 'Sana Iqbal',
            'email' => 'sana@example.test',
            'phone' => '+92 300 1112222',
        ]);

        $application = Application::create([
            'vacancy_id' => $vacancy->id,
            'applicant_id' => $applicant->id,
            'applied_on' => '2026-08-01',
            'stage' => Application::STAGE_OFFER,
        ]);

        return Offer::create(array_merge([
            'application_id' => $application->id,
            'salary' => 150000,
            'joining_date' => '2026-09-01',
            'status' => Offer::STATUS_ISSUED,
            'issued_at' => now(),
        ], $offerAttributes));
    }

    // ---------------------------------------------------------------- recruitment

    /** One person, two applications — the memory the split table buys. */
    public function test_an_applicant_can_apply_to_two_vacancies(): void
    {
        $applicant = Applicant::create(['name' => 'Repeat Candidate', 'email' => 'again@example.test']);

        foreach (['V-1', 'V-2'] as $index => $code) {
            $vacancy = Vacancy::create(['code' => $code, 'title' => "Role {$index}", 'status' => Vacancy::STATUS_OPEN]);

            Application::create([
                'vacancy_id' => $vacancy->id,
                'applicant_id' => $applicant->id,
                'applied_on' => '2026-08-01',
            ]);
        }

        $this->assertSame(2, $applicant->applications()->count());
        $this->assertTrue($applicant->hasAppliedBefore());
    }

    /**
     * THE hire test: employee, package and trail, in one go.
     */
    public function test_hiring_creates_the_employee_the_package_and_the_trail(): void
    {
        $offer = $this->makeOffer();

        $employee = app(HireService::class)->hire($offer);

        $this->assertSame('Sana Iqbal', $employee->name);
        $this->assertSame('Senior Accountant', $employee->designation);
        $this->assertSame('Finance', $employee->department);
        $this->assertSame('2026-09-01', $employee->date_of_joining->toDateString());
        $this->assertNull($employee->user_id, 'A login is opt-in: employees without one are supported.');

        $setting = EmployeeSetting::where('employee_id', $employee->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame(150000.0, (float) $setting->basic_wage);
        // From the joining date, not the fiscal year's start: there is no agreed package
        // for July and August, and one that claimed otherwise would let a payslip be
        // raised for a month before they existed.
        $this->assertSame('2026-09-01', $setting->start_date->toDateString());

        $application = $offer->fresh()->application;
        $this->assertSame(Application::STAGE_HIRED, $application->stage);
        $this->assertSame($employee->id, $application->employee_id);
        $this->assertSame(Offer::STATUS_ACCEPTED, $offer->fresh()->status);
    }

    public function test_the_offer_components_become_salary_components(): void
    {
        $component = PayComponent::create([
            'code' => 'internet_allowance',
            'label' => 'Internet Allowance',
            'kind' => PayComponent::KIND_EARNING,
            // A component with nowhere to post makes a payslip impossible to post, and
            // PayComponent refuses to be saved without one. Reusing the existing
            // bonus_overtime key rather than inventing an account here: this test is
            // about the offer becoming a component, not about the chart of accounts.
            'account_key' => 'bonus_overtime',
        ]);

        $offer = $this->makeOffer(['components' => ['internet_allowance' => 5000]]);

        $employee = app(HireService::class)->hire($offer);
        $setting = EmployeeSetting::where('employee_id', $employee->id)->first();

        $this->assertSame(1, $setting->components()->count());
        $this->assertSame(5000.0, (float) $setting->components()->first()->amount);
        $this->assertSame($component->id, $setting->components()->first()->pay_component_id);
    }

    /**
     * A component the company has since retired does not stop somebody starting work.
     *
     * The package is correctable; a failed hire on somebody's first morning is not.
     */
    public function test_an_unknown_offer_component_is_skipped_rather_than_failing_the_hire(): void
    {
        $offer = $this->makeOffer(['components' => ['a_component_that_was_retired' => 5000]]);

        $employee = app(HireService::class)->hire($offer);

        $this->assertNotNull($employee->id);
        $this->assertSame(0, EmployeeSetting::where('employee_id', $employee->id)->first()->components()->count());
    }

    public function test_a_login_is_created_when_asked_for(): void
    {
        $offer = $this->makeOffer();

        $employee = app(HireService::class)->hire($offer, withLogin: true);

        $this->assertNotNull($employee->user_id);
        $this->assertSame('sana@example.test', $employee->user->email);
    }

    public function test_a_login_needs_an_email(): void
    {
        $offer = $this->makeOffer();
        $offer->application->applicant->update(['email' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A login needs an email address');

        app(HireService::class)->hire($offer, withLogin: true);
    }

    public function test_hiring_twice_is_refused(): void
    {
        $offer = $this->makeOffer();
        app(HireService::class)->hire($offer);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been hired');

        app(HireService::class)->hire($offer->fresh());
    }

    /** The vacancy closes itself when its last opening is taken. */
    public function test_filling_the_last_opening_closes_the_vacancy(): void
    {
        $offer = $this->makeOffer();

        app(HireService::class)->hire($offer);

        $this->assertSame(Vacancy::STATUS_FILLED, $offer->fresh()->application->vacancy->status);
    }

    /**
     * §12-style degradation: the pipeline works without `employees`, and hiring is absent.
     *
     * The whole reason `recruitment` requires nothing — a company hiring its very first
     * person has no employees licence yet.
     */
    public function test_the_pipeline_works_without_employees_and_hiring_is_not_offered(): void
    {
        $this->setModule('employees', false);

        $offer = $this->makeOffer();

        // Everything up to the hire still works.
        $offer->application->update(['stage' => Application::STAGE_INTERVIEW, 'rating' => 4]);
        $this->assertSame(Application::STAGE_INTERVIEW, $offer->fresh()->application->stage);

        $this->assertFalse(app(HireService::class)->isAvailable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the Employees module');

        app(HireService::class)->hire($offer);
    }

    /** Without payroll there is no package, and the hire still succeeds. */
    public function test_hiring_without_payroll_creates_the_employee_and_no_package(): void
    {
        $this->setModule('payroll', false);

        $employee = app(HireService::class)->hire($this->makeOffer());

        $this->assertNotNull($employee->id);
        $this->assertSame(0, EmployeeSetting::where('employee_id', $employee->id)->count());
    }

    // ---------------------------------------------------------------- performance

    public function test_opening_a_cycle_raises_one_review_per_active_employee(): void
    {
        $manager = Employee::create(['employee_id' => 'MGR', 'name' => 'Manager', 'gender' => 'Male', 'is_active' => true]);
        Employee::create(['employee_id' => 'E-1', 'name' => 'One', 'gender' => 'Male', 'is_active' => true, 'manager_id' => $manager->id]);
        Employee::create(['employee_id' => 'E-2', 'name' => 'Two', 'gender' => 'Female', 'is_active' => false]);

        $cycle = ReviewCycle::create([
            'name' => '2026 Annual',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);

        $raised = app(ReviewCycleService::class)->open($cycle);

        $this->assertSame(2, $raised, 'Only active employees.');
        $this->assertSame(ReviewCycle::STATUS_OPEN, $cycle->fresh()->status);

        // The reviewer is stamped from the manager in force now, not looked up later.
        $review = Review::whereHas('employee', fn ($q) => $q->where('employee_id', 'E-1'))->first();
        $this->assertSame($manager->id, $review->reviewer_employee_id);
    }

    public function test_opening_a_cycle_twice_does_not_duplicate_reviews(): void
    {
        Employee::create(['employee_id' => 'E-1', 'name' => 'One', 'gender' => 'Male', 'is_active' => true]);

        $cycle = ReviewCycle::create([
            'name' => '2026 Annual', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        ]);

        app(ReviewCycleService::class)->open($cycle);
        $this->assertSame(0, app(ReviewCycleService::class)->open($cycle), 'Nothing new the second time.');
        $this->assertSame(1, Review::count());
    }

    /** Submitted is not shared: a review is a draft about somebody until a manager decides. */
    public function test_a_review_is_not_visible_to_the_employee_until_shared(): void
    {
        $employee = Employee::create(['employee_id' => 'E-1', 'name' => 'One', 'gender' => 'Male', 'is_active' => true]);
        $cycle = ReviewCycle::create(['name' => 'C', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);

        $review = Review::create([
            'review_cycle_id' => $cycle->id,
            'employee_id' => $employee->id,
            'final_rating' => 4,
            'submitted_at' => now(),
        ]);

        $this->assertFalse($review->isVisibleToEmployee());

        app(ReviewCycleService::class)->share($review);

        $this->assertTrue($review->fresh()->isVisibleToEmployee());
    }

    public function test_an_unsubmitted_review_cannot_be_shared(): void
    {
        $employee = Employee::create(['employee_id' => 'E-1', 'name' => 'One', 'gender' => 'Male', 'is_active' => true]);
        $cycle = ReviewCycle::create(['name' => 'C', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);

        $review = Review::create(['review_cycle_id' => $cycle->id, 'employee_id' => $employee->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to share yet');

        app(ReviewCycleService::class)->share($review);
    }

    /**
     * THE performance test: a rating changes no pay.
     *
     * The suggestion is returned; the package is untouched. §4.5 refuses the automation
     * because the first disputed rating would otherwise become a payroll incident.
     */
    public function test_a_rating_suggests_an_increment_and_changes_nothing(): void
    {
        $employee = Employee::create(['employee_id' => 'E-1', 'name' => 'One', 'gender' => 'Male', 'is_active' => true]);

        $setting = EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => FiscalYear::first()->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 100000,
        ]);

        $cycle = ReviewCycle::create(['name' => 'C', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        $review = Review::create([
            'review_cycle_id' => $cycle->id,
            'employee_id' => $employee->id,
            'final_rating' => 5,
        ]);

        $suggestion = app(ReviewCycleService::class)->suggestedIncrement($review);

        $this->assertSame(100000.0, $suggestion['current']);
        $this->assertSame(115000.0, $suggestion['suggested'], 'A 5 suggests 15% on the shipped scale.');
        $this->assertStringContainsString('suggestion only', $suggestion['note']);

        // And nothing moved.
        $this->assertSame(100000.0, (float) $setting->fresh()->basic_wage);
        $this->assertSame(1, EmployeeSetting::where('employee_id', $employee->id)->count());
    }

    /** Private notes are never readable by the person they are about. */
    public function test_private_notes_are_hidden_from_their_subject(): void
    {
        $subjectUser = User::factory()->create(['status' => 1]);
        $employee = Employee::create([
            'employee_id' => 'E-1', 'name' => 'One', 'gender' => 'Male',
            'is_active' => true, 'user_id' => $subjectUser->id,
        ]);

        $oneToOne = \App\Modules\Performance\Models\OneToOne::create([
            'employee_id' => $employee->id,
            'met_on' => '2026-08-01',
            'notes' => 'Discussed the migration.',
            'private_notes' => 'Flight risk.',
        ]);

        $this->assertFalse(
            $oneToOne->privateNotesVisibleTo($subjectUser),
            'The subject must never read the notes about them, whatever else they hold.',
        );
    }
}
