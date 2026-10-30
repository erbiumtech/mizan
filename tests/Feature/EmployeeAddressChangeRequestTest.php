<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\Pages\ListEmployeeChangeRequests;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The two structured addresses go through the approval queue like every other personal detail.
 *
 * They were missing from EmployeeChangeRequest::ALLOWED_FIELDS, so a self-service edit to either was
 * dropped without a word. They are array casts, which is why they were left out: getDirty() holds their
 * JSON string. The request now carries the array, approval writes it back through the cast, and every
 * screen shows it as one line.
 */
class EmployeeAddressChangeRequestTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $employeeUser;

    private User $manager;

    private Employee $employee;

    private array $old = ['line_1' => 'House 1', 'city' => 'Lahore', 'province' => 'Punjab', 'postal_code' => '54000'];

    private array $new = ['line_1' => 'Flat 7, New Block', 'line_2' => '', 'city' => 'Lahore', 'province' => 'Punjab', 'postal_code' => '54660'];

    protected function setUp(): void
    {
        parent::setUp();
        // Filament sets a tenant for a signed-in user; nobody signed in is a state the panel never has.
        $this->actingAs($this->makeUser('Administrator', 'address-admin@test.local'));
        $this->setCurrentTenant();

        $this->employeeUser = $this->makeUser('Employee', 'address-self@test.local');
        $this->manager = $this->makeUser('Manager', 'address-mgr@test.local');

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'employee_id' => 'EMP-ADDR-01',
            'gender' => 'Female',
            'phone' => '0300-0000001',
            'is_active' => true,
            'current_address' => $this->old,
        ]);
    }

    private function requestNewAddress(): EmployeeChangeRequest
    {
        $this->actingAs($this->employeeUser);
        $this->employee->update(['current_address' => $this->new, 'permanent_address' => ['line_1' => 'Village 5', 'city' => 'Sialkot']]);

        return EmployeeChangeRequest::query()->latest('id')->firstOrFail();
    }

    public function test_a_self_service_address_edit_becomes_a_request_carrying_the_arrays(): void
    {
        $request = $this->requestNewAddress();

        $this->assertSame($this->new, $request->requested_changes['current_address']);
        $this->assertSame(['line_1' => 'Village 5', 'city' => 'Sialkot'], $request->requested_changes['permanent_address']);
        $this->assertSame($this->old, $request->original_values['current_address']);
        $this->assertNull($request->original_values['permanent_address']);

        // Untouched until approved.
        $this->assertSame($this->old, $this->employee->refresh()->current_address);
    }

    public function test_approval_writes_the_addresses_back(): void
    {
        $request = $this->requestNewAddress();

        $this->actingAs($this->manager);
        $request->approve($this->manager);

        $employee = $this->employee->refresh();
        $this->assertSame($this->new, $employee->current_address);
        $this->assertSame('Sialkot', $employee->permanent_address['city']);
        $this->assertSame('Flat 7, New Block, Lahore, Punjab, 54660', $employee->postalAddress());
    }

    public function test_an_address_reads_as_one_line_on_the_queue_and_the_diff(): void
    {
        $request = $this->requestNewAddress();

        $this->assertSame('Flat 7, New Block, Lahore, Punjab, 54660', EmployeeChangeRequest::displayValue($request->requested_changes['current_address']));

        $this->actingAs($this->manager);

        Livewire::test(ListEmployeeChangeRequests::class)
            ->loadTable()
            ->assertSee('current_address: Flat 7, New Block, Lahore, Punjab, 54660');

        // The diff is the View Changes modal's body, which the modal loads on opening rather than with the page.
        $diff = view('filament.employee-change-diff', ['record' => $request])->render();
        $this->assertStringContainsString('House 1, Lahore, Punjab, 54000', $diff);
        $this->assertStringContainsString('Flat 7, New Block, Lahore, Punjab, 54660', $diff);
    }

    public function test_saving_the_same_address_again_files_nothing(): void
    {
        $this->actingAs($this->employeeUser);
        $this->employee->update(['current_address' => $this->old]);

        $this->assertSame(0, EmployeeChangeRequest::query()->count());
    }
}
