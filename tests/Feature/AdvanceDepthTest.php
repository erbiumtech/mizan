<?php

namespace Tests\Feature;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Models\AdvanceType;
use App\Modules\Advances\Services\AdvanceApplications;
use App\Modules\Advances\Services\AdvanceService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Advances with types, an application, a pause and the three reports — docs/saas-launch-plan.md §14.3.
 */
class AdvanceDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private AdvanceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Notification::fake();
        $this->actingAs($this->makeUser('Administrator', 'adv-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'advances', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->employee = Employee::create(['user_id' => $this->makeUser('Employee', 'adv-emp@test.local')->id, 'employee_id' => 'EMP-ADV', 'name' => 'Hassan', 'gender' => 'Male', 'is_active' => 1]);
        $this->type = AdvanceType::create(['name' => 'Salary advance', 'max_amount' => 100000, 'max_instalments' => 10, 'interest_rate_pct' => 5, 'active_limit' => 1]);
    }

    private function request(float $amount = 50000, int $instalments = 5): Advance
    {
        return Advance::create([
            'employee_id' => $this->employee->id, 'advance_type_id' => $this->type->id,
            'total_amount' => $amount, 'interest_amount' => $this->type->interestOn($amount), 'instalments' => $instalments,
            'monthly_instalment' => round(($amount + $this->type->interestOn($amount)) / $instalments, 2),
            'started_on' => '2026-11-05', 'status' => Advance::STATUS_REQUESTED, 'requested_by' => $this->employee->user_id, 'requested_at' => now(),
        ]);
    }

    public function test_an_application_deducts_nothing_until_approved_and_the_type_checks_its_limits(): void
    {
        $advance = $this->request();
        $this->assertSame(0.0, app(AdvanceService::class)->instalmentFor($this->employee->id, null, '2026-12-01'), 'requested is not active');

        app(AdvanceApplications::class)->approve($advance, auth()->user(), '2026-11-06');
        $this->assertSame(Advance::STATUS_ACTIVE, $advance->fresh()->status);
        $this->assertSame('2026-11-06', $advance->fresh()->started_on->toDateString());
        $this->assertSame(2500.0, (float) $advance->fresh()->interest_amount, '5% of 50,000');
        $this->assertSame(52500.0, $advance->fresh()->totalRecoverable());
        $this->assertSame(10500.0, app(AdvanceService::class)->instalmentFor($this->employee->id, null, '2026-12-01'));

        // A second of the same type while one is active: over the limit.
        $second = $this->request(20000, 2);

        try {
            app(AdvanceApplications::class)->approve($second, auth()->user());
            $this->fail('A second active advance of a limited type was approved.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('allows 1 active advance', $e->getMessage());
        }

        app(AdvanceApplications::class)->refuse($second, auth()->user(), 'One at a time');
        $this->assertSame(Advance::STATUS_REFUSED, $second->fresh()->status);
        $this->assertSame('One at a time', $second->fresh()->refusal_reason);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 100,000');
        $this->type->check(150000, 5, $this->employee->id);
    }

    public function test_pause_stops_deductions_without_writing_anything_off_and_resume_restarts_them(): void
    {
        $advance = $this->request();
        app(AdvanceApplications::class)->approve($advance, auth()->user());

        app(AdvanceApplications::class)->pause($advance->fresh(), 'Unpaid leave');
        $this->assertSame(Advance::STATUS_PAUSED, $advance->fresh()->status);
        $this->assertSame(0.0, app(AdvanceService::class)->instalmentFor($this->employee->id, null, '2026-12-01'));
        $this->assertSame(52500.0, $advance->fresh()->remainingAmount(), 'nothing written off');
        $this->assertSame([], $advance->fresh()->schedule(), 'nothing projected while paused');

        app(AdvanceApplications::class)->resume($advance->fresh());
        $this->assertSame(10500.0, app(AdvanceService::class)->instalmentFor($this->employee->id, null, '2026-12-01'));
    }

    public function test_the_schedule_steps_over_skipped_months_and_arrears_count_what_should_have_come_back(): void
    {
        $advance = $this->request();
        app(AdvanceApplications::class)->approve($advance, auth()->user());
        $advance->fresh()->update(['recovery_starts_on' => '2026-12-01', 'skipped_months' => ['2027-01']]);

        $schedule = $advance->fresh()->schedule('2026-11-05');
        $this->assertSame(['2026-12', '2027-02', '2027-03', '2027-04', '2027-05'], array_column($schedule, 'month'));
        $this->assertSame(10500.0, $schedule[0]['amount']);
        $this->assertSame(52500.0, round(array_sum(array_column($schedule, 'amount')), 2));

        $this->assertSame(0.0, $advance->fresh()->arrearsAt('2026-12-15'), 'the month being asked about has not run');
        $this->assertSame(10500.0, $advance->fresh()->arrearsAt('2027-01-15'), 'December should have come back');
        $this->assertSame(10500.0, $advance->fresh()->arrearsAt('2027-02-15'), 'January was skipped');

        $defaulters = ReportRenderers::render('AdvanceDefaulters', '2027-02-15', false, []);
        $this->assertCount(1, $defaulters['rows']);
        $this->assertSame(10500.0, $defaulters['tiles'][0]['value']);

        $schedule = ReportRenderers::render('AdvanceSchedule', '2026-11-05', false, []);
        $this->assertCount(1, $schedule['rows']);
        $this->assertSame('10,500', $schedule['rows'][0][2], 'December is the second month from November');
        $this->assertSame('', $schedule['rows'][0][3], 'January skipped');

        $disbursed = ReportRenderers::render('AdvanceDisbursements', '2026-11-20', false, []);
        $this->assertCount(1, $disbursed['rows']);
        $this->assertSame(50000.0, $disbursed['tiles'][0]['value']);
    }
}
