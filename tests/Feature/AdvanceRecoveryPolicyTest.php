<?php

namespace Tests\Feature;

use App\Modules\Advances\Filament\Settings\AdvanceRecoverySettingsSection;
use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Services\AdvanceApplications;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * B16 and B17: which months an advance is recovered in.
 *
 * B17 — an advance with no start month of its own was recovered in any month not yet raised, so one handed over
 * on 30 October was taken from a September payslip raised afterwards. When a blank start begins is now the
 * company's (Company Settings → Advances); by default never before the month the advance is given.
 *
 * B16 — a pause is a status, read when asked: resume the advance, save the paused month's payslip again, and
 * the month deducted after all. A month raised while paused is now remembered as skipped.
 */
class AdvanceRecoveryPolicyTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'recovery-policy@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'advances'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'recovery-borrower@test.local')->id,
            'employee_id' => 'EMP-RP', 'gender' => 'Male', 'phone' => '0300-2222222',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 100000,
        ]);
    }

    private function advance(string $givenOn, array $attributes = []): Advance
    {
        return Advance::create(array_merge([
            'employee_id' => $this->employee->id, 'total_amount' => 30000, 'monthly_instalment' => 10000,
            'started_on' => $givenOn, 'status' => Advance::STATUS_ACTIVE,
        ], $attributes));
    }

    private function payslip(string $month): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => $month,
            'total_working_days' => 22, 'paid_days' => 22,
        ])->fresh();
    }

    // ── B17 ──

    public function test_by_default_nothing_is_recovered_before_the_month_the_advance_is_given(): void
    {
        $this->advance('2026-10-30');

        $this->assertSame(0.0, (float) $this->payslip('September')->advances, 'September was raised after, but is before the advance');
        $this->assertSame(10000.0, (float) $this->payslip('October')->advances, 'the month it was given');
    }

    public function test_a_company_can_start_recovery_the_month_after(): void
    {
        app(TenantSettings::class)->set(Advance::RECOVERY_STARTS_SETTING, Advance::RECOVERY_FROM_MONTH_AFTER);
        $this->advance('2026-10-30');

        $this->assertSame(0.0, (float) $this->payslip('October')->advances);
        $this->assertSame(10000.0, (float) $this->payslip('November')->advances);
    }

    public function test_a_company_can_recover_from_any_month_as_before(): void
    {
        app(TenantSettings::class)->set(Advance::RECOVERY_STARTS_SETTING, Advance::RECOVERY_FROM_ANY_MONTH);
        $this->advance('2026-10-30');

        $this->assertSame(10000.0, (float) $this->payslip('September')->advances);
    }

    public function test_a_start_month_set_on_the_advance_wins_over_the_setting(): void
    {
        $this->advance('2026-10-30', ['recovery_starts_on' => '2026-12-01']);

        $this->assertSame(0.0, (float) $this->payslip('November')->advances);
        $this->assertSame(10000.0, (float) $this->payslip('December')->advances);
    }

    /** The choice lives in Company Settings → Advances; unset, it reads as the default. */
    public function test_the_settings_section_reads_and_writes_the_choice(): void
    {
        $section = new AdvanceRecoverySettingsSection;
        $this->assertSame(Advance::RECOVERY_FROM_MONTH_GIVEN, $section->fill()['advances_recovery_starts']);

        $section->save(['advances_recovery_starts' => Advance::RECOVERY_FROM_MONTH_AFTER]);
        app(TenantSettings::class)->flush();

        $this->assertSame(Advance::RECOVERY_FROM_MONTH_AFTER, setting(Advance::RECOVERY_STARTS_SETTING));
    }

    // ── B16 ──

    public function test_a_month_raised_while_paused_stays_unrecovered_after_the_resume(): void
    {
        $advance = $this->advance('2026-07-01');
        app(AdvanceApplications::class)->pause($advance, 'Unpaid leave');

        $august = $this->payslip('August');
        $this->assertSame(0.0, (float) $august->advances);
        $this->assertContains('2026-08', $advance->fresh()->skipped_months);

        app(AdvanceApplications::class)->resume($advance->fresh());

        // The paused month, saved again after the resume (a correction after reopening the run).
        $august->update(['bonus' => 500]);
        $this->assertSame(0.0, (float) $august->fresh()->advances, 'the paused month is still not charged');

        $this->assertSame(10000.0, (float) $this->payslip('September')->advances, 'the next month recovers again');
    }

    public function test_a_month_that_recovered_before_the_pause_is_not_marked_skipped(): void
    {
        $advance = $this->advance('2026-07-01');
        $july = $this->payslip('July');
        $this->assertSame(10000.0, (float) $july->advances);

        app(AdvanceApplications::class)->pause($advance->fresh(), 'Unpaid leave');
        $july->update(['bonus' => 500]);

        $this->assertNotContains('2026-07', $advance->fresh()->skipped_months ?? []);
    }
}
