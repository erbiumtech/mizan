<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeChecklist;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Services\HireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Hire starts the onboarding checklist — docs/saas-launch-plan.md §7.3.
 *
 * Exactly one active onboarding template starts; none or two start nothing.
 * Recruitment announces the joiner and Lifecycle answers, so a company without
 * Lifecycle hires exactly as before.
 */
class HireStartsOnboardingTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['recruitment', 'employees', 'lifecycle'] as $module) {
            $this->module($module, true);
        }

        FiscalYear::firstOrCreate(['name' => '2026-2027'], ['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    }

    private function module(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );
        modules()->flush();
    }

    private function offer(): Offer
    {
        $vacancy = Vacancy::create(['code' => 'V-'.Vacancy::count(), 'title' => 'Accountant', 'status' => Vacancy::STATUS_OPEN]);
        $applicant = Applicant::create(['name' => 'Sana Iqbal '.Applicant::count(), 'email' => 'sana'.Applicant::count().'@example.test']);
        $application = Application::create([
            'vacancy_id' => $vacancy->id, 'applicant_id' => $applicant->id, 'applied_on' => '2026-08-01', 'stage' => Application::STAGE_OFFER,
        ]);

        return Offer::create([
            'application_id' => $application->id, 'salary' => 100000, 'joining_date' => '2026-11-16',
            'status' => Offer::STATUS_ISSUED, 'issued_at' => now(),
        ]);
    }

    private function template(string $name, bool $active = true): ChecklistTemplate
    {
        $template = ChecklistTemplate::create(['kind' => ChecklistTemplate::KIND_ONBOARDING, 'name' => $name, 'is_active' => $active]);
        $template->items()->createMany([
            ['title' => 'Order a laptop', 'owner_role' => 'IT', 'due_offset_days' => -7, 'sort' => 1],
            ['title' => 'Sign the contract', 'owner_role' => 'HR', 'due_offset_days' => 0, 'sort' => 2],
        ]);

        return $template;
    }

    public function test_one_active_template_starts_dated_from_the_joining_date(): void
    {
        $this->template('Standard');
        $this->template('Retired', active: false);
        ChecklistTemplate::create(['kind' => ChecklistTemplate::KIND_EXIT, 'name' => 'Exit']);

        $employee = app(HireService::class)->hire($this->offer());

        $checklist = EmployeeChecklist::where('employee_id', $employee->getKey())->first();
        $this->assertNotNull($checklist, 'No onboarding checklist was started.');
        $this->assertSame(ChecklistTemplate::KIND_ONBOARDING, $checklist->kind);
        $this->assertCount(2, $checklist->items);
        $this->assertSame('2026-11-09', $checklist->items[0]->due_on->toDateString(), 'Due dates were not taken from the joining date.');
        $this->assertSame('2026-11-16', $checklist->items[1]->due_on->toDateString());
    }

    public function test_two_templates_start_nothing(): void
    {
        $this->template('Office');
        $this->template('Factory');

        $employee = app(HireService::class)->hire($this->offer());

        $this->assertNotNull($employee->id, 'The hire itself must not fail.');
        $this->assertSame(0, EmployeeChecklist::count());
    }

    public function test_no_template_starts_nothing(): void
    {
        $employee = app(HireService::class)->hire($this->offer());

        $this->assertNotNull($employee->id);
        $this->assertSame(0, EmployeeChecklist::count());
    }

    public function test_without_lifecycle_the_hire_is_exactly_as_before(): void
    {
        $this->template('Standard');
        $this->module('lifecycle', false);

        $employee = app(HireService::class)->hire($this->offer());

        $this->assertNotNull($employee->id);
        $this->assertSame(0, EmployeeChecklist::count());
    }
}
