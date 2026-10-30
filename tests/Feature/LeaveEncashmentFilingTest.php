<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Filament\Resources\LeaveEncashments\Pages\CreateLeaveEncashment;
use App\Modules\Leave\Models\LeaveEncashment;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveEncashments;
use App\Modules\Leave\Services\LeaveEntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An encashment asking for more than the balance is refused when it is filed.
 *
 * It used to be accepted and refused only at approval, so the approver's queue held requests the balance
 * never covered. What can be asked for is the balance less what is already asked for and waiting —
 * approval still checks the balance again, because it can fall in between.
 */
class LeaveEncashmentFilingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $ayesha;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Notification::fake();
        $this->actingAs($this->makeUser('Administrator', 'encash-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'leave', 'payroll'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->ayesha = Employee::create(['employee_id' => 'EMP-ENC-1', 'name' => 'Ayesha', 'gender' => 'Female', 'is_active' => 1, 'date_of_joining' => '2020-01-01']);
        $this->annual = LeaveType::create(['code' => 'annual', 'label' => 'Annual Leave', 'days_per_year' => 14, 'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT, 'is_encashable' => true]);
        app(LeaveEntitlementService::class)->open($this->ayesha, $this->annual);
    }

    private function file(float $days)
    {
        return Livewire::test(CreateLeaveEncashment::class)
            ->fillForm(['employee_id' => $this->ayesha->getKey(), 'leave_type_id' => $this->annual->getKey(), 'days' => $days, 'amount' => 1000, 'reason' => 'test'])
            ->call('create');
    }

    public function test_within_the_balance_it_is_filed(): void
    {
        $this->file(10)->assertHasNoFormErrors();

        $this->assertSame(1, LeaveEncashment::query()->count());
    }

    public function test_more_than_the_balance_is_a_field_error_and_nothing_is_filed(): void
    {
        $this->file(20)->assertHasFormErrors(['days']);

        $this->assertSame(0, LeaveEncashment::query()->count());
    }

    public function test_days_already_asked_for_count_against_the_next_request(): void
    {
        $this->file(10)->assertHasNoFormErrors();
        $this->assertSame(4.0, app(LeaveEncashments::class)->available($this->ayesha, $this->annual));

        $this->file(6)->assertHasFormErrors(['days']);
        $this->file(4)->assertHasNoFormErrors();
    }

    public function test_with_no_leave_year_open_there_is_nothing_to_encash(): void
    {
        $casual = LeaveType::create(['code' => 'casual', 'label' => 'Casual', 'days_per_year' => 10, 'is_encashable' => true]);

        Livewire::test(CreateLeaveEncashment::class)
            ->fillForm(['employee_id' => $this->ayesha->getKey(), 'leave_type_id' => $casual->getKey(), 'days' => 1, 'amount' => 1])
            ->call('create')
            ->assertHasFormErrors(['days']);
    }
}
