<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Users\Pages\EditUser;
use App\Modules\Core\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ListEmployees;
use App\Modules\Employees\Models\Employee;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A user account is a landlord row shared by every company the person works for,
 * so a company's Users page cannot delete one: that would take the person out of
 * companies whose administrators never asked, and strand the payslips, MPRs and
 * audit entries pointing at them. What it can end is this company's claim — the
 * `company_user` pivot and the roles held here.
 */
class UserRemovalFromCompanyTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $mine;

    private Company $theirs;

    private User $admin;

    private User $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->mine = Company::factory()->create(['name' => 'Mine']);
        $this->theirs = Company::factory()->create(['name' => 'Theirs']);

        foreach ([$this->mine, $this->theirs] as $company) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
            (new RoleSeeder)->run();
        }

        $this->admin = User::factory()->create(['name' => 'Our Admin']);
        $this->mine->users()->attach($this->admin);

        // Works for both companies — the case a delete would have got wrong.
        $this->shared = User::factory()->create(['name' => 'Shared Person']);
        $this->mine->users()->attach($this->shared);
        $this->theirs->users()->attach($this->shared);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->mine->id);
        $this->admin->assignRole('Administrator');
        $this->shared->assignRole('Manager');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->theirs->id);
        $this->shared->assignRole('Accountant');

        $this->actingAs($this->admin);
        $this->setCurrentTenant($this->mine);
    }

    /**
     * Straight at the pivot table. Reading it back through Company::users() would
     * be read through the very membership scope under test, which narrows to the
     * current company and would report the other one as gone.
     */
    private function isMemberOf(Company $company, User $user): bool
    {
        return DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }

    private function rolesIn(Company $company, User $user): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        return $user->fresh()->roles()->pluck('name')->all();
    }

    public function test_removing_a_member_ends_this_companys_claim_and_no_others(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->shared->getKey()])
            ->callAction('removeFromCompany');

        $this->assertDatabaseHas('users', ['id' => $this->shared->getKey()]);
        $this->assertFalse($this->isMemberOf($this->mine, $this->shared));

        // acrossCompanies(): Company::users() reads User, which carries the
        // membership scope for the company being served, so asking about another
        // company's members has to step past it. This is the legitimate case the
        // scope exists to be opted out of.
        $this->assertTrue(
            $this->theirs->users()->acrossCompanies()->whereKey($this->shared->getKey())->exists()
        );

        // Roles are per-company: the ones held here go, the ones held there stay.
        $this->assertSame([], $this->rolesIn($this->mine, $this->shared));
        $this->assertSame(['Accountant'], $this->rolesIn($this->theirs, $this->shared));
    }

    public function test_a_removed_member_drops_out_of_the_list_and_off_the_panel(): void
    {
        $this->shared->removeFromCompany($this->mine);

        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$this->shared]);

        $this->assertFalse($this->shared->fresh()->canAccessTenant($this->mine));
        $this->assertTrue($this->shared->fresh()->canAccessTenant($this->theirs));
    }

    /**
     * Their history is this company's, not theirs to take away: the employee row
     * and everything hanging off it stays, and still reads with a name.
     */
    public function test_removal_leaves_the_employee_record_and_its_name_intact(): void
    {
        $employee = Employee::create([
            'user_id' => $this->shared->getKey(),
            'employee_id' => 'EMP-SHARED',
            'is_active' => 1,
        ]);

        $this->shared->removeFromCompany($this->mine);

        $this->assertTrue(Employee::query()->whereKey($employee->getKey())->exists());
        $this->assertSame('Shared Person', $employee->fresh()->user?->name);

        Livewire::test(ListEmployees::class)->assertCanSeeTableRecords([$employee]);
    }

    /** The one removal that would lock the person doing it out of this company. */
    public function test_you_cannot_remove_yourself(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->assertActionHidden('removeFromCompany');

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$this->admin->getKey(), $this->shared->getKey()])
            ->callAction(TestAction::make('removeFromCompany')->table()->bulk());

        $this->assertTrue($this->isMemberOf($this->mine, $this->admin));
        $this->assertFalse($this->isMemberOf($this->mine, $this->shared));
    }

    /** Deleting the account outright is a platform decision, not a company's. */
    public function test_only_a_super_admin_is_offered_the_real_delete(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->shared->getKey()])
            ->assertActionHidden('delete');

        // Already attached on create by the panel's tenancy observer, hence sync.
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->mine->users()->syncWithoutDetaching([$superAdmin->getKey()]);
        $this->actingAs($superAdmin);

        Livewire::test(EditUser::class, ['record' => $this->shared->getKey()])
            ->assertActionVisible('delete');
    }
}
