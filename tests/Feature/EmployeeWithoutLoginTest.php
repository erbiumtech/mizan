<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Staff who are employed but never sign in — a household's driver or cook.
 *
 * Until now an employee was by definition somebody with a user account, because
 * employees were only ever created through Users. Inventing an email address
 * and a password for a cook to never use is worse than letting the record stand
 * on its own, so user_id is nullable and the name can live on the employee.
 */
class EmployeeWithoutLoginTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->admin = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($this->admin->getKey());
        $this->actingAs($this->admin);
        $this->admin->assignRole('Administrator');
        $this->setCurrentTenant($this->company);
    }

    private function cook(): Employee
    {
        return Employee::create([
            'employee_id' => 'DOM-1',
            'name' => 'Rashid the cook',
            'designation' => 'Cook',
            'gender' => 'Male',
            'is_active' => true,
        ]);
    }

    public function test_an_employee_can_exist_with_no_user_account(): void
    {
        $cook = $this->cook();

        $this->assertNull($cook->user_id);
        $this->assertFalse($cook->hasLogin());
        $this->assertSame('Rashid the cook', $cook->fullName());
    }

    public function test_their_name_shows_wherever_employees_are_listed(): void
    {
        $cook = $this->cook();

        // display_label used to read the linked user's name and would have shown
        // a bare "DOM-1" with nothing after it.
        $this->assertSame('DOM-1 - Rashid the cook', $cook->display_label);
    }

    public function test_a_linked_user_still_wins_over_the_stored_name(): void
    {
        $user = User::factory()->create(['name' => 'Ayesha Karim', 'status' => 1]);
        $this->company->users()->syncWithoutDetaching([$user->getKey()]);

        // Both set: the user record is the one source of truth for anybody who
        // signs in, so a stale copy on the employee must not override it.
        $employee = Employee::create([
            'user_id' => $user->id,
            'name' => 'Stale copy',
            'employee_id' => 'EMP-9',
            'gender' => 'Female',
            'is_active' => true,
        ]);

        $this->assertSame('Ayesha Karim', $employee->fullName());
        $this->assertTrue($employee->hasLogin());
    }

    public function test_staff_without_a_login_never_leak_into_accessible_user_ids(): void
    {
        $this->cook();

        // pluck('user_id')->map(fn ($id) => (int) $id) turned a null into user 0
        // — a real id-shaped value that would then be matched against.
        $ids = app(EmployeeAccess::class)->accessibleUserIds($this->admin);

        $this->assertNotContains(0, $ids->all());
    }

    public function test_looking_up_an_employee_by_user_still_works(): void
    {
        $this->cook();

        // A null user_id must never match a real user, however the lookup is
        // phrased.
        $this->assertNull(Employee::forUser($this->admin->id));
    }
}
