<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\OptionValue;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Core\Services\CsvImportService;
use App\Modules\Employees\Events\EmployeeActivated;
use App\Modules\Employees\Models\Department;
use App\Modules\Employees\Models\Designation;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use App\Modules\Employees\Services\EmployeeMoves;
use App\Notifications\CompanyInvitation;
use App\Support\CsvImporters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The employee record — docs/saas-launch-plan.md §14.2. One rule: `is_active`
 * keeps its meaning; `status` sits beside it and derives it. The rest: rows for
 * departments and designations that keep the dropdown in step, a pay grade that
 * warns, an importer, activation, probation, and the dated moves.
 */
class EmployeeRecordDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Notification::fake();
        $this->actingAs($this->makeUser('Administrator', 'record-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'leave'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->employee = Employee::create(['employee_id' => 'EMP-1', 'name' => 'Ayesha', 'gender' => 'Female', 'is_active' => true, 'department' => 'Sales', 'designation' => 'Executive', 'date_of_joining' => '2026-08-01', 'personal_email' => 'ayesha@home.test']);
    }

    // ───────────────────────────────────────────────────────────── status ──

    public function test_status_derives_is_active_and_the_flag_keeps_the_status_honest(): void
    {
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee->fresh()->status);

        $this->employee->update(['status' => Employee::STATUS_ON_LEAVE]);
        $this->assertTrue((bool) $this->employee->fresh()->is_active, 'on leave is still on the books');

        $this->employee->update(['status' => Employee::STATUS_SUSPENDED]);
        $this->assertFalse((bool) $this->employee->fresh()->is_active);

        $this->employee->update(['status' => Employee::STATUS_RESIGNED]);
        $this->assertFalse((bool) $this->employee->fresh()->is_active);
        $this->assertSame(0, Employee::query()->where('is_active', true)->count(), 'every scope reading the flag agrees');

        // An older form flips the flag: the status follows.
        $this->employee->update(['is_active' => true]);
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee->fresh()->status);
        $this->employee->update(['is_active' => false]);
        $this->assertSame(Employee::STATUS_RESIGNED, $this->employee->fresh()->status);
    }

    // ───────────────────────────────────────────────────────── rows ──

    public function test_departments_and_designations_are_rows_that_keep_the_dropdown_in_step(): void
    {
        $sales = Department::create(['name' => 'Sales', 'code' => 'S']);
        $this->assertArrayHasKey('Sales', options('employees.department'));

        $sales->update(['name' => 'Sales & Marketing']);
        $this->assertArrayHasKey('Sales & Marketing', options('employees.department'));
        $this->assertArrayNotHasKey('Sales', options('employees.department'));
        $this->assertSame('Sales & Marketing', $this->employee->fresh()->department, 'the rename follows the people who hold it');

        $this->assertFalse((new \App\Modules\Employees\Policies\DepartmentPolicy)->delete(auth()->user(), $sales), 'a department with people in it is not deleted');

        $sales->update(['is_active' => false]);
        $this->assertSame(false, OptionValue::query()->where('list', 'employees.department')->where('value', 'Sales & Marketing')->first()->is_active);

        $exec = Designation::create(['name' => 'Executive', 'level' => 5, 'pay_grade_min' => 60000, 'pay_grade_max' => 90000]);
        $this->assertNull($exec->payGradeWarning(75000));
        $this->assertStringContainsString('below the Executive grade', $exec->payGradeWarning(50000));
        $this->assertStringContainsString('above the Executive grade', $exec->payGradeWarning(95000));
        $this->assertNull(Designation::create(['name' => 'Intern'])->payGradeWarning(1), 'no grade, no warning');
    }

    // ───────────────────────────────────────────────── import and activate ──

    public function test_employees_import_from_a_spreadsheet_and_activate_with_an_invitation(): void
    {
        $this->assertTrue(CsvImporters::has('employees'));

        $csv = "employee_id,name,email,gender,department,designation,date_of_joining,phone,nic,bank_account_no,iban_no,employment_type\n"
            ."EMP-2,Bilal Ahmed,bilal@home.test,male,Accounts,Clerk,2026-11-01,0300-1,35202-1,0011,,permanent\n"
            ."EMP-1,Dup,,,,,,,,,,\n"
            ."EMP-3,,x@home.test,,,,,,,,,\n";

        $preview = app(CsvImportService::class)->preview($csv, 'employees');
        $this->assertSame(1, $preview['ready']);
        $this->assertSame(2, $preview['skipped']);

        $this->assertSame(1, app(CsvImportService::class)->import($csv, 'employees')['imported']);
        $bilal = Employee::query()->where('employee_id', 'EMP-2')->sole();
        $this->assertSame('Male', $bilal->gender);
        $this->assertNull($bilal->user_id, 'no login until activated');
        $this->assertTrue((bool) $bilal->is_active);

        Event::fake([EmployeeActivated::class]);
        $result = app(EmployeeMoves::class)->activate($bilal, auth()->user());

        $this->assertTrue($result['invited']);
        $this->assertNull($result['problem']);
        $this->assertNotNull($bilal->fresh()->user_id);
        $this->assertSame('bilal@home.test', $bilal->fresh()->user->email);
        Notification::assertSentTo($bilal->fresh()->user, CompanyInvitation::class);
        Event::assertDispatched(EmployeeActivated::class, fn (EmployeeActivated $e): bool => $e->employeeId === $bilal->getKey());

        // Nowhere to invite: still activated, and named.
        $noEmail = Employee::create(['employee_id' => 'EMP-4', 'name' => 'Nadia', 'gender' => 'Female', 'is_active' => false]);
        $result = app(EmployeeMoves::class)->activate($noEmail, auth()->user());
        $this->assertSame('no email to invite', $result['problem']);
        $this->assertTrue((bool) $noEmail->fresh()->is_active);
    }

    // ───────────────────────────────────────────────────────── probation ──

    public function test_probation_is_listed_until_confirmed(): void
    {
        $this->employee->update(['probation_ends_on' => '2026-10-31']);
        $this->assertTrue($this->employee->fresh()->isOnProbation());
        $this->assertTrue($this->employee->fresh()->probationOverdue());

        app(EmployeeMoves::class)->confirm($this->employee->fresh(), '2026-11-02');
        $this->assertSame('2026-11-02', $this->employee->fresh()->confirmed_on->toDateString());
        $this->assertFalse($this->employee->fresh()->isOnProbation());

        $this->expectException(InvalidArgumentException::class);
        app(EmployeeMoves::class)->confirm($this->employee->fresh());
    }

    // ──────────────────────────────────────────────── transfer, promote, rehire ──

    public function test_transfers_promotions_and_a_rehire_are_dated_rows_in_the_history(): void
    {
        $lahore = WorkLocation::create(['name' => 'Lahore']);
        $moves = app(EmployeeMoves::class);

        $transfer = $moves->transfer($this->employee, ['department' => 'Accounts', 'location_id' => $lahore->getKey()], '2026-11-10', 'Branch opening');
        $this->assertSame(EmployeeJobHistory::KIND_TRANSFER, $transfer->kind);
        $this->assertSame('Accounts', $transfer->department);
        $this->assertSame($lahore->getKey(), (int) $transfer->location_id);
        $this->assertSame('Executive', $transfer->designation, 'what did not change is carried');

        $promotion = $moves->promote($this->employee->fresh(), 'Manager', '2026-12-01', 'Target met');
        $this->assertSame(EmployeeJobHistory::KIND_PROMOTION, $promotion->kind);
        $this->assertSame('Accounts', $promotion->department);

        try {
            $moves->transfer($this->employee->fresh(), [], '2026-12-02');
            $this->fail('An empty transfer was recorded.');
        } catch (InvalidArgumentException) {
        }

        $this->employee->fresh()->update(['status' => Employee::STATUS_RESIGNED, 'left_on' => '2026-12-31']);
        $rehired = $moves->rehire($this->employee->fresh(), '2027-02-01', 'Came back');

        $this->assertTrue((bool) $rehired->is_active);
        $this->assertNull($rehired->left_on);
        $this->assertSame('2027-02-01', $rehired->date_of_joining->toDateString());
        $this->assertSame(EmployeeJobHistory::KIND_REHIRE, EmployeeJobHistory::query()->where('employee_id', $this->employee->getKey())->orderByDesc('id')->first()->kind);
        $this->assertGreaterThanOrEqual(3, EmployeeJobHistory::query()->where('employee_id', $this->employee->getKey())->count(), 'the old spell stays in the history');

        $this->expectException(InvalidArgumentException::class);
        $moves->rehire($rehired, '2027-03-01');
    }
}
