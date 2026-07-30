<?php

namespace Tests\Feature;

use App\Modules\Employees\Filament\Resources\Employees\Pages\EditEmployee;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Accounting\Models\Bank;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Secondary phone and date of birth: both are employee-editable personal
 * details, so both must survive a self-service edit as a change request.
 *
 * A field missing from EmployeeChangeRequest::ALLOWED_FIELDS is dropped from a
 * self-service save without any error — and if it is the only change, no request
 * is filed either. That is how secondary_phone was silently lost.
 */
class EmployeeProfileFieldsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private function employee(User $user, string $employeeId = 'EMP-1'): Employee
    {
        return Employee::create([
            'user_id' => $user->id,
            'employee_id' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
            'phone' => '0300-1111111',
        ]);
    }

    /** An employee complete enough to pass the edit form's required fields. */
    private function completeEmployee(string $email, string $employeeId): Employee
    {
        Storage::fake('public');
        Storage::disk('public')->put('nic/front.jpg', 'front');
        Storage::disk('public')->put('nic/back.jpg', 'back');

        $bank = Bank::create([
            'bank_code' => 'TSTB',
            'bank_name' => 'Test Bank',
            'bank_short_code' => 'TST',
        ]);

        return Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => $employeeId,
            'designation' => 'Backend Developer',
            'department' => 'IT',
            'gender' => 'Male',
            'phone' => '0300-1111111',
            'secondary_phone' => '0301-2222222',
            'nic' => '12345-1234567-1',
            'nic_front' => 'nic/front.jpg',
            'nic_back' => 'nic/back.jpg',
            'date_of_joining' => '2025-01-01',
            'bank_id' => $bank->id,
            'bank_account_no' => '0001112223334',
            'iban_no' => 'PK00TSTB0001112223334',
            'is_active' => true,
        ]);
    }

    public function test_an_approver_saves_both_fields_directly(): void
    {
        $this->actingAs($this->makeUser('Administrator', 'admin@test.local'));
        $employee = $this->employee(User::factory()->create());

        $employee->update([
            'secondary_phone' => '0301-2222222',
            'date_of_birth' => '1994-05-17',
        ]);

        $employee->refresh();
        $this->assertSame('0301-2222222', $employee->secondary_phone);
        $this->assertSame('1994-05-17', $employee->date_of_birth->toDateString());
        $this->assertSame(0, EmployeeChangeRequest::count());
    }

    public function test_a_self_service_edit_of_only_the_secondary_phone_files_a_request(): void
    {
        $self = $this->makeUser('Employee', 'self@test.local');
        $employee = $this->employee($self);
        $this->actingAs($self);

        $employee->update(['secondary_phone' => '0399-9999999']);

        // Still pending, so the record is untouched…
        $this->assertNull($employee->fresh()->secondary_phone);

        // …but the change was captured rather than dropped.
        $request = EmployeeChangeRequest::sole();
        $this->assertSame(['secondary_phone' => '0399-9999999'], $request->requested_changes);
    }

    public function test_a_self_service_edit_of_only_the_date_of_birth_files_a_request(): void
    {
        $self = $this->makeUser('Employee', 'self2@test.local');
        $employee = $this->employee($self);
        $this->actingAs($self);

        $employee->update(['date_of_birth' => '1990-01-02']);

        $request = EmployeeChangeRequest::sole();
        $this->assertArrayHasKey('date_of_birth', $request->requested_changes);
    }

    public function test_both_fields_are_included_alongside_other_requested_changes(): void
    {
        $self = $this->makeUser('Employee', 'self3@test.local');
        $employee = $this->employee($self);
        $this->actingAs($self);

        $employee->update([
            'phone' => '0300-8888888',
            'secondary_phone' => '0301-7777777',
            'date_of_birth' => '1988-12-31',
        ]);

        $changes = EmployeeChangeRequest::sole()->requested_changes;

        $this->assertArrayHasKey('phone', $changes);
        $this->assertArrayHasKey('secondary_phone', $changes);
        $this->assertArrayHasKey('date_of_birth', $changes);
    }

    public function test_the_form_exposes_both_fields(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'admin2@test.local'));
        $this->setCurrentTenant();

        $employee = $this->employee(User::factory()->create(), 'EMP-FORM');

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->assertFormFieldExists('secondary_phone', fn ($field): bool => $field->getLabel() === 'Secondary Phone')
            ->assertFormFieldExists('date_of_birth', fn ($field): bool => $field->getLabel() === 'Date of Birth');
    }

    public function test_the_form_saves_both_fields(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'admin3@test.local'));
        $this->setCurrentTenant();

        // The form requires NIC/bank details, so saving needs a complete record.
        $employee = $this->completeEmployee('save@test.local', 'EMP-SAVE');

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->fillForm([
                'secondary_phone' => '0302-3333333',
                'date_of_birth' => '1992-03-04',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee->refresh();
        $this->assertSame('0302-3333333', $employee->secondary_phone);
        $this->assertSame('1992-03-04', $employee->date_of_birth->toDateString());
    }

    public function test_a_future_date_of_birth_is_rejected(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'admin4@test.local'));
        $this->setCurrentTenant();

        $employee = $this->employee(User::factory()->create(), 'EMP-FUTURE');

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->fillForm(['date_of_birth' => now()->addYear()->toDateString()])
            ->call('save')
            ->assertHasFormErrors(['date_of_birth']);
    }

    public function test_the_view_page_shows_both_fields(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = $this->setCurrentTenant();
        app()->instance('currentTenant', $company);

        $employee = $this->employee($user, 'EMP-VIEW');
        Employee::withoutApprovalRouting(fn () => $employee->update([
            'secondary_phone' => '0345-4444444',
            'date_of_birth' => '1991-07-09',
        ]));

        Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
            ->assertSuccessful()
            ->assertSee('Secondary Phone')
            ->assertSee('0345-4444444')
            ->assertSee('Date of Birth')
            ->assertSee('09-07-1991');
    }

    public function test_the_pdf_includes_both_fields(): void
    {
        $this->actingAs($this->makeUser('Administrator', 'admin5@test.local'));
        $employee = $this->employee(User::factory()->create(), 'EMP-PDF');

        $employee->update(['secondary_phone' => '0346-5555555', 'date_of_birth' => '1993-02-08']);

        $html = view('pdfs.employee', ['employee' => $employee->fresh()->load('user', 'bank', 'manager.user')])->render();

        $this->assertStringContainsString('0346-5555555', $html);
        $this->assertStringContainsString('Date of Birth', $html);
        $this->assertStringContainsString('08-02-1993', $html);
    }
}
