<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Services\ReviewCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The increment record — docs/saas-launch-plan.md §7.10. A finalised appraisal
 * proposes a new package through the change-request path; the review never
 * writes the package, an approver does.
 */
class IncrementRecordTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    private EmployeeSetting $current;

    private Review $review;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['performance', 'employees', 'payroll'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $fiscalYear = FiscalYear::firstOrCreate(['name' => '2026-2027'], ['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);

        $this->employee = Employee::create(['employee_id' => 'EMP-1', 'name' => 'Ayesha', 'gender' => 'Female', 'is_active' => true, 'date_of_joining' => '2020-01-01']);

        $this->current = EmployeeSetting::create([
            'employee_id' => $this->employee->getKey(), 'fiscal_year_id' => $fiscalYear->getKey(),
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 100000, 'medical_allowance' => 10000,
        ]);

        $component = PayComponent::create(['code' => 'internet_allowance', 'label' => 'Internet Allowance', 'kind' => PayComponent::KIND_EARNING, 'account_key' => 'bonus_overtime']);
        $this->current->components()->create(['pay_component_id' => $component->getKey(), 'amount' => 5000]);

        $cycle = ReviewCycle::create(['name' => 'H1 2026', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31', 'status' => ReviewCycle::STATUS_CALIBRATING]);
        $this->review = Review::create(['review_cycle_id' => $cycle->getKey(), 'employee_id' => $this->employee->getKey(), 'status' => Review::STATUS_SHARED, 'final_rating' => 4]);
    }

    private function service(): ReviewCycleService
    {
        return app(ReviewCycleService::class);
    }

    public function test_a_proposal_is_a_change_request_and_the_package_is_untouched_until_approval(): void
    {
        $this->assertSame(10.0, $this->service()->suggestedPercent($this->review));

        $request = $this->service()->proposeIncrement($this->review, 10, '2026-12-01', $this->actor);

        $this->assertSame(EmployeeChangeRequest::TARGET_NEW_SETTING, $request->target_type);
        $this->assertSame(EmployeeChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame($this->review->getKey(), (int) $request->review_id);
        $this->assertNull($request->target_id);
        $this->assertSame(110000.0, (float) $request->requested_changes['basic_wage']);
        $this->assertSame(10000.0, (float) $request->requested_changes['medical_allowance']);
        $this->assertSame('2026-12-01', $request->requested_changes['start_date']);
        $this->assertSame('2027-06-30', $request->requested_changes['end_date']);
        $this->assertSame('New salary package', $request->targetLabel());

        // Nothing has changed: a rating is an opinion, a package is approved.
        $this->assertSame(1, EmployeeSetting::count());
        $this->assertSame(100000.0, (float) $this->current->fresh()->basic_wage);
        $this->assertSame('2027-06-30', $this->current->fresh()->end_date->toDateString());

        $this->assertTrue($this->review->fresh()->incrementRequest->is($request));

        // One open proposal per review.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has a proposal');
        $this->service()->proposeIncrement($this->review->fresh(), 10, '2026-12-01', $this->actor);
    }

    public function test_approving_creates_the_version_closes_the_old_one_and_carries_the_components(): void
    {
        $request = $this->service()->proposeIncrement($this->review, 12.5, '2026-12-01', $this->actor);

        $request->approve(User::factory()->create(['status' => 1]));

        $this->assertSame(EmployeeChangeRequest::STATUS_APPROVED, $request->fresh()->status);

        $new = EmployeeSetting::where('employee_id', $this->employee->getKey())->orderByDesc('start_date')->first();
        $this->assertNotSame($this->current->getKey(), $new->getKey());
        $this->assertSame('2026-12-01', $new->start_date->toDateString());
        $this->assertSame('2027-06-30', $new->end_date->toDateString());
        $this->assertSame(112500.0, (float) $new->basic_wage);
        $this->assertSame(10000.0, (float) $new->medical_allowance);
        $this->assertSame($new->getKey(), (int) $request->fresh()->target_id, 'The request does not point at what it made.');

        // The previous package ends the day before, so the two never overlap.
        $this->assertSame('2026-11-30', $this->current->fresh()->end_date->toDateString());

        // The data-driven allowance survives the increment.
        $this->assertSame(1, $new->components()->count());
        $this->assertSame(5000.0, (float) $new->components()->first()->amount);

        // The lookup payroll uses now finds the new one from December.
        $this->assertSame($new->getKey(), EmployeeSetting::getActiveSettingForDate($this->employee->getKey(), '2026-12-15', $new->fiscal_year_id)?->getKey());
        $this->assertSame($this->current->getKey(), EmployeeSetting::getActiveSettingForDate($this->employee->getKey(), '2026-11-15', $new->fiscal_year_id)?->getKey());
    }

    public function test_a_rejected_proposal_can_be_made_again_and_the_guards_hold(): void
    {
        $request = $this->service()->proposeIncrement($this->review, 10, '2026-12-01', $this->actor);
        $request->reject(User::factory()->create(['status' => 1]), 'Not this year.');

        $again = $this->service()->proposeIncrement($this->review->fresh(), 5, '2027-01-01', $this->actor);
        $this->assertSame(105000.0, (float) $again->requested_changes['basic_wage']);

        // Rejected once; the guards below are then the only thing in the way.
        $again->reject(User::factory()->create(['status' => 1]));

        foreach ([
            ['2026-07-01', 'has to start after'],
            ['2030-01-01', 'No fiscal year covers'],
        ] as [$start, $message]) {
            try {
                $this->service()->proposeIncrement($this->review->fresh(), 10, $start, $this->actor);
                $this->fail("Accepted a start of {$start}.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->review->update(['final_rating' => null]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Finalise the rating');
        $this->service()->proposeIncrement($this->review->fresh(), 10, '2027-02-01', $this->actor);
    }
}
