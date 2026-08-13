<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\CompanySettings;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Two guarantees the plan asked for that nothing asserted: docs/hrms-plan.md **§10.10** and
 * **§10.20**.
 *
 * §10.10 exists because **Company Settings belongs to Core**, which serves every company.
 * Without a visible() guard, a company that never bought Leave would be offered leave
 * policy to set — settings it can neither see the effect of nor reach the screens for.
 * Nothing else in the stack stops that; the page is not module-gated as a whole, because
 * most of it is Core's.
 *
 * §10.20 is a data-protection obligation rather than a feature. This application holds CVs
 * about people the company never hired, and a pruned row that leaves its file on disk means
 * the company still holds the CV while believing it does not — which is worse than not
 * pruning at all.
 */
class HrSettingsAndRetentionTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Administrator, because CompanySettings::canAccess() requires it.
        $this->admin = User::factory()->create(['status' => 1, 'is_super_admin' => true]);
        $this->actingAs($this->admin);
        $this->setCurrentTenant();
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    // ──────────────────────────────── §10.10 ───────────────────────────────

    /**
     * THE §10.10 test: no Leave module, no leave policy on the settings page.
     *
     * Asserted through the rendered page rather than by reading the schema, because
     * `visible()` is evaluated at render and a schema assertion would pass while the field
     * still appeared.
     */
    public function test_the_leave_section_is_absent_when_the_module_is_not_licensed(): void
    {
        $this->setModule('leave', false);

        Livewire::test(CompanySettings::class)
            ->assertOk()
            ->assertDontSee('When the leave year starts')
            ->assertDontSee('Let unused days carry into the next leave year');
    }

    public function test_the_leave_section_is_present_when_the_module_is_licensed(): void
    {
        $this->setModule('employees', true);
        $this->setModule('leave', true);

        Livewire::test(CompanySettings::class)
            ->assertOk()
            ->assertSee('When the leave year starts')
            ->assertSee('Let unused days carry into the next leave year');
    }

    /**
     * The pay-policy section needs BOTH attendance and payroll.
     *
     * Pro-rating with no work pattern has nothing to divide by, and overtime with no
     * pattern has no rate to derive — so offering either would be offering a switch that
     * cannot work.
     */
    public function test_the_pay_policy_section_needs_both_attendance_and_payroll(): void
    {
        $this->setModule('payroll', true);
        $this->setModule('attendance', false);

        Livewire::test(CompanySettings::class)
            ->assertOk()
            ->assertDontSee('Reduce pay for unpaid absence');

        $this->setModule('attendance', true);

        Livewire::test(CompanySettings::class)
            ->assertOk()
            ->assertSee('Reduce pay for unpaid absence');
    }

    /**
     * Saving with the section hidden writes no leave policy.
     *
     * The keys are absent from the form state when visible() hid them, and the save guards
     * on that — otherwise a company without Leave would end up with settings rows it never
     * chose and cannot see.
     */
    public function test_saving_with_the_section_hidden_writes_no_leave_policy(): void
    {
        $this->setModule('leave', false);

        // A deliberately non-default value, so a stray write would be visible.
        app(TenantSettings::class)->set('leave.carry_forward', true);

        Livewire::test(CompanySettings::class)->call('save')->assertOk();

        $this->assertTrue(
            (bool) setting('leave.carry_forward'),
            'The save touched leave policy for a company that cannot see it.',
        );
    }

    // ──────────────────────────────── §10.20 ───────────────────────────────

    /**
     * THE §10.20 test: pruning deletes the CV, not just the row.
     *
     * A pruned record that leaves its file behind means the company still holds a
     * stranger's CV while believing it does not.
     */
    public function test_pruning_an_applicant_deletes_the_resume_file(): void
    {
        Storage::fake('public');
        $this->setModule('recruitment', true);

        Storage::disk('public')->put('applicant-resumes/cv.pdf', 'a curriculum vitae');
        Storage::disk('public')->assertExists('applicant-resumes/cv.pdf');

        $applicant = $this->rejectedApplicant(monthsAgo: 30, resume: 'applicant-resumes/cv.pdf');

        $this->assertSame(1, Applicant::first()->prunable()->count(), 'The fixture is not prunable, so this proves nothing.');

        Applicant::first()->pruneAll();

        $this->assertSame(0, Applicant::count());
        Storage::disk('public')->assertMissing('applicant-resumes/cv.pdf');
    }

    /** The clock runs from the REJECTION, so somebody still in a process is never pruned. */
    public function test_an_applicant_still_in_a_process_is_never_pruned(): void
    {
        $this->setModule('recruitment', true);

        $vacancy = Vacancy::create(['code' => 'V-1', 'title' => 'Role', 'status' => Vacancy::STATUS_OPEN]);

        $applicant = Applicant::create(['name' => 'Still going', 'email' => 'live@example.test']);

        // Applied three years ago and still at interview: age alone must not prune them.
        Application::create([
            'vacancy_id' => $vacancy->id,
            'applicant_id' => $applicant->id,
            'applied_on' => now()->subYears(3)->toDateString(),
            'stage' => Application::STAGE_INTERVIEW,
        ]);

        Applicant::first()->pruneAll();

        $this->assertSame(1, Applicant::count(), 'Somebody still in a process was pruned on age alone.');
    }

    /** A hired applicant is never pruned: they are an employee's origin record. */
    public function test_a_hired_applicant_is_never_pruned(): void
    {
        $this->setModule('recruitment', true);
        $this->setModule('employees', true);

        $vacancy = Vacancy::create(['code' => 'V-1', 'title' => 'Role', 'status' => Vacancy::STATUS_FILLED]);
        $applicant = Applicant::create(['name' => 'Hired long ago', 'email' => 'staff@example.test']);
        $employee = Employee::create([
            'employee_id' => 'EMP-1', 'name' => 'Hired long ago', 'gender' => 'Male', 'is_active' => true,
        ]);

        Application::create([
            'vacancy_id' => $vacancy->id,
            'applicant_id' => $applicant->id,
            'applied_on' => now()->subYears(5)->toDateString(),
            'stage' => Application::STAGE_HIRED,
            'employee_id' => $employee->id,
        ]);

        Applicant::first()->pruneAll();

        $this->assertSame(1, Applicant::count());
    }

    /** A recent rejection is inside the window and stays. */
    public function test_a_recently_rejected_applicant_stays(): void
    {
        $this->setModule('recruitment', true);

        $this->rejectedApplicant(monthsAgo: 3);

        Applicant::first()->pruneAll();

        $this->assertSame(1, Applicant::count());
    }

    /** The window is a company setting, so shortening it prunes more. */
    public function test_the_retention_window_is_a_company_setting(): void
    {
        $this->setModule('recruitment', true);

        $this->rejectedApplicant(monthsAgo: 12);

        // Twelve months ago is inside the shipped 24-month window.
        Applicant::first()->pruneAll();
        $this->assertSame(1, Applicant::count());

        // A company entitled to keep less says so.
        app(TenantSettings::class)->set('recruitment.retention_months', 6);

        Applicant::first()->pruneAll();
        $this->assertSame(0, Applicant::count());
    }

    private function rejectedApplicant(int $monthsAgo, ?string $resume = null): Applicant
    {
        $vacancy = Vacancy::firstOrCreate(
            ['code' => 'V-REJ'],
            ['title' => 'Closed role', 'status' => Vacancy::STATUS_CLOSED],
        );

        $applicant = Applicant::create([
            'name' => 'Rejected Candidate',
            'email' => 'rejected@example.test',
            'resume_path' => $resume,
        ]);

        Application::create([
            'vacancy_id' => $vacancy->id,
            'applicant_id' => $applicant->id,
            'applied_on' => now()->subMonths($monthsAgo + 1)->toDateString(),
            'stage' => Application::STAGE_REJECTED,
            'rejected_reason' => 'Not a fit',
            'rejected_at' => now()->subMonths($monthsAgo),
        ]);

        return $applicant->fresh();
    }
}
