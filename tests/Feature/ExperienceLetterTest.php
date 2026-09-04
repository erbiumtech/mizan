<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Employees\Services\ExperienceLetter;
use App\Modules\Employees\Services\JobHistory;
use App\Support\TenantSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * "This person worked here, in these roles, for this long" — the letter a next employer asks for.
 *
 * What separates it from the income certificate is what it does *not* say. The salary is off unless somebody
 * asks, because what an employee earned here follows them into their next negotiation. And it states service
 * rather than pay, so it issues for somebody with no salary package on file at all — which the income
 * certificate refuses to do.
 */
class ExperienceLetterTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'hr-exp@test.local'));
        $this->setCurrentTenant();

        $settings = app(TenantSettings::class);
        foreach ([
            'company.legal_name' => 'ERBIUMTECH (SMC-Private) Limited',
            'company.address' => 'Office 5, Gulberg III, Lahore',
            'company.ntn' => '1234567-8',
            'company.signatory_name' => 'Muzafar Ali',
        ] as $key => $value) {
            $settings->set($key, $value);
        }

        $login = $this->makeUser('Employee', 'leaver@test.local');
        $login->update(['name' => 'Muhammad Hammad']);

        $this->employee = Employee::create([
            'user_id' => $login->getKey(),
            'employee_id' => 'EMP-014',
            'name' => 'Muhammad Hammad',
            'gender' => 'Male',
            'is_active' => true,
            'designation' => 'Senior Full Stack Developer',
            'department' => 'IT',
            'employment_type' => 'permanent',
            'date_of_joining' => '2024-03-01',
        ]);
    }

    public function test_it_issues_for_somebody_with_no_salary_package_at_all(): void
    {
        // The income certificate refuses this case; a service letter states no pay, so it has nothing to
        // refuse over.
        $this->assertSame([], app(ExperienceLetter::class)->missingFor($this->employee));
    }

    public function test_without_a_joining_date_it_certifies_nothing_and_says_so(): void
    {
        $this->employee->update(['date_of_joining' => null]);

        $this->assertContains(
            'the date of joining (Employees → edit this employee)',
            app(ExperienceLetter::class)->missingFor($this->employee->fresh()),
        );
    }

    public function test_the_length_of_service_is_stated_in_whole_months(): void
    {
        $this->travelTo('2027-02-15');

        // 1 March 2024 → 15 February 2027 is 2 years and 11 months. Rounding it up to three years on a
        // letter somebody verifies is the small lie that discredits the document.
        $this->assertSame('2 years 11 months', app(ExperienceLetter::class)->serviceLength($this->employee));
    }

    public function test_a_leaver_is_written_in_the_past_tense_and_bounded_by_the_last_working_day(): void
    {
        $this->employee->update(['left_on' => '2026-08-31', 'is_active' => false]);

        $data = app(ExperienceLetter::class)->data($this->employee->fresh());

        $this->assertTrue($data['has_left']);
        // 1 March 2024 to 31 August 2026 is 29 whole months — two years and five, not six. The last month
        // is a day short of complete, and the letter counts completed months only.
        $this->assertSame('2 years 5 months', $data['service_length']);

        $html = app(ExperienceLetter::class)->renderPdf($this->employee->fresh())->html();

        $this->assertStringContainsString('was employed', $html);
        $this->assertStringContainsString('31 August 2026', $html);
        $this->assertStringContainsString('Last Working Day', $html);
        $this->assertStringContainsString('every success in future endeavours', $html);
    }

    /** Somebody job-hunting asks for this before they resign, so it has to read in the present tense. */
    public function test_a_current_employee_is_written_in_the_present_tense(): void
    {
        $html = app(ExperienceLetter::class)->renderPdf($this->employee)->html();

        $this->assertStringContainsString('has been employed', $html);
        $this->assertStringContainsString('the date of this letter', $html);
        $this->assertStringNotContainsString('Last Working Day', $html);
    }

    /** The roles held are the thing that makes the letter worth having. */
    public function test_it_lists_every_role_held_from_the_job_history(): void
    {
        $history = app(JobHistory::class);
        $history->record($this->employee, ['designation' => 'Backend Developer', 'department' => 'IT'], '2024-03-01');
        $history->record($this->employee, ['designation' => 'Senior Full Stack Developer', 'department' => 'IT'], '2025-07-01');

        $letters = app(ExperienceLetter::class);
        $roles = $letters->roles($this->employee->fresh());

        $this->assertCount(2, $roles);
        $this->assertSame('Backend Developer', $roles[0]['designation']);
        $this->assertSame('Senior Full Stack Developer', $roles[1]['designation']);

        $html = $letters->renderPdf($this->employee->fresh())->html();

        $this->assertStringContainsString('The positions held during this period were', $html);
        $this->assertStringContainsString('Backend Developer', $html);
        $this->assertStringContainsString('01 July 2025', $html);
    }

    /** A manager change under an unchanged title is not a second stint in the same job. */
    public function test_consecutive_rows_with_the_same_title_are_one_role(): void
    {
        $history = app(JobHistory::class);
        $history->record($this->employee, ['designation' => 'Backend Developer'], '2024-03-01');
        $history->record($this->employee, ['designation' => 'Backend Developer', 'department' => 'Platform'], '2025-01-01');

        $roles = app(ExperienceLetter::class)->roles($this->employee->fresh());

        $this->assertCount(1, $roles);
        $this->assertSame('2024-03-01', $roles[0]['from']->toDateString(), 'the earliest date for the title');
    }

    public function test_the_salary_is_absent_unless_it_is_asked_for(): void
    {
        EmployeeSetting::create([
            'employee_id' => $this->employee->getKey(),
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'medical_allowance' => 20000,
            'device_allowance' => 5000,
            'petrol_allowance' => 25000,
        ]);

        $letters = app(ExperienceLetter::class);

        $this->assertNull($letters->data($this->employee)['monthly_gross']);
        $this->assertStringNotContainsString('250,000', $letters->renderPdf($this->employee)->html());

        $withSalary = $letters->renderPdf($this->employee, ['include_salary' => true])->html();

        $this->assertStringContainsString('250,000', $withSalary);
        $this->assertStringContainsString('Gross Monthly Salary', $withSalary);
    }

    public function test_the_conduct_wording_comes_from_the_company_list(): void
    {
        $html = app(ExperienceLetter::class)->renderPdf($this->employee, ['conduct' => 'exemplary'])->html();

        $this->assertStringContainsString('found to be exemplary', $html);
        $this->assertContains('exemplary', array_keys(options('employees.conduct')));
    }

    public function test_an_employee_can_download_their_own_letter(): void
    {
        $this->actingAs(User::find($this->employee->user_id));

        Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->assertActionVisible('experienceLetter')
            ->callAction(TestAction::make('experienceLetter'), ['conduct' => 'satisfactory'])
            ->assertHasNoActionErrors();
    }

    /** The two letters must not carry the same reference — a recipient files by it. */
    public function test_its_reference_is_distinct_from_the_income_certificate(): void
    {
        $experience = app(ExperienceLetter::class)->data($this->employee)['reference'];
        $income = app(\App\Modules\Employees\Services\IncomeCertificate::class)->reference($this->employee);

        $this->assertNotSame($income, $experience);
        $this->assertStringContainsString('EMP-014/EXP', $experience);
    }
}
