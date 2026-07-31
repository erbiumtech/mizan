<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Beneficiary;
use App\Models\Company;
use App\Models\User;
use App\Support\Impersonation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Signing in as another user, so an administrator can finish work on behalf of
 * staff who cannot reasonably do it themselves.
 *
 * The negative assertions are the point of this file. A feature that hands one
 * person another person's session is only safe if the ways it must refuse are
 * pinned down, so most of what follows is about who cannot be impersonated and
 * what the record says afterwards.
 */
class ImpersonationTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private Impersonation $impersonation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->company = Company::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->impersonation = app(Impersonation::class);
    }

    private function member(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['status' => 1]);
        $this->company->users()->attach($user);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        $user->assignRole($role);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        $this->setCurrentTenant($this->company);
    }

    // --- who may ------------------------------------------------------------

    public function test_an_administrator_can_sign_in_as_a_member_of_their_company(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);

        $this->assertTrue($this->impersonation->allows($admin, $employee));

        $this->impersonation->start($employee);

        $this->assertAuthenticatedAs($employee);
        $this->assertTrue($this->impersonation->isActive());
        $this->assertTrue($this->impersonation->impersonator()->is($admin));
    }

    public function test_an_ordinary_employee_cannot_impersonate(): void
    {
        $employee = $this->member('Employee');
        $colleague = $this->member('Employee');

        $this->actAs($employee);

        $this->assertFalse($this->impersonation->allows($employee, $colleague));
    }

    public function test_a_super_admin_can_impersonate_without_the_permission(): void
    {
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);
        $employee = $this->member('Employee');

        $this->actAs($superAdmin);

        $this->assertTrue($this->impersonation->allows($superAdmin, $employee));
    }

    // --- who may not be impersonated ----------------------------------------

    public function test_nobody_can_impersonate_a_super_admin(): void
    {
        // The escalation that matters: a company Administrator who could sign in
        // as a super admin would own the whole installation.
        $admin = $this->member('Administrator');
        $target = $this->member('Employee', ['is_super_admin' => true]);

        $this->actAs($admin);
        $this->assertFalse($this->impersonation->allows($admin, $target));

        // Not even another super admin, who gains nothing by it.
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);
        $this->actAs($superAdmin);
        $this->assertFalse($this->impersonation->allows($superAdmin, $target));
    }

    public function test_an_administrator_cannot_reach_another_companys_user(): void
    {
        // Users are shared across companies, so without this an Administrator of
        // one company could sign in as somebody who happens to also work for
        // another — and land in that other company's data.
        $admin = $this->member('Administrator');

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->create(['status' => 1]);
        $otherCompany->users()->attach($outsider);

        $this->actAs($admin);

        $this->assertFalse($this->impersonation->allows($admin, $outsider));
    }

    public function test_a_deactivated_user_cannot_be_impersonated(): void
    {
        // canAccessPanel() refuses status 0, so this would hand over a session
        // that is bounced straight back out.
        $admin = $this->member('Administrator');
        $inactive = $this->member('Employee', ['status' => 0]);

        $this->actAs($admin);

        $this->assertFalse($this->impersonation->allows($admin, $inactive));
    }

    public function test_you_cannot_impersonate_yourself(): void
    {
        $admin = $this->member('Administrator');

        $this->actAs($admin);

        $this->assertFalse($this->impersonation->allows($admin, $admin));
    }

    public function test_impersonation_cannot_be_nested(): void
    {
        // The way back is a single stored id, so a chain would make "who is really
        // doing this" unanswerable.
        $admin = $this->member('Administrator');
        $first = $this->member('Administrator');
        $second = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($first);

        $this->assertFalse($this->impersonation->allows($first, $second));
    }

    public function test_starting_an_unauthorized_impersonation_throws(): void
    {
        $employee = $this->member('Employee');
        $colleague = $this->member('Employee');

        $this->actAs($employee);

        $this->expectException(RuntimeException::class);

        $this->impersonation->start($colleague);
    }

    // --- getting back -------------------------------------------------------

    public function test_stopping_returns_to_the_original_account(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        $this->assertTrue($this->impersonation->stop()->is($admin));
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse($this->impersonation->isActive());
    }

    public function test_stopping_when_not_impersonating_does_nothing(): void
    {
        $admin = $this->member('Administrator');
        $this->actAs($admin);

        $this->assertNull($this->impersonation->stop());
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_stop_route_returns_to_the_original_account(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        $this->post(route('impersonate.stop'))->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    // --- the record ---------------------------------------------------------

    public function test_starting_and_stopping_are_both_logged(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);
        $this->impersonation->stop();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Impersonation',
            'description' => "{$admin->email} started signing in as {$employee->email}",
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Impersonation',
            'description' => "{$admin->email} stopped signing in as {$employee->email}",
        ]);
    }

    /**
     * The integrity question this feature raises. Accepting a salary change is a
     * statement of consent, so a record showing only the employee would be a lie
     * about who agreed.
     */
    public function test_work_done_while_impersonating_names_the_real_actor(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        // Any audited change carries the stamp — it is applied by the Auditable
        // trait's log entry, which is the same path a payslip acknowledgement
        // takes, without needing payroll fixtures to prove it.
        $record = Beneficiary::create(['name' => 'Utility Co', 'payment_type' => 'IBFT']);

        $entry = \App\Models\ActivityLog::query()
            ->where('subject_type', Beneficiary::class)
            ->where('subject_id', $record->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'the change itself is audited');

        $stamp = $entry->properties['impersonated_by'] ?? null;

        $this->assertNotNull($stamp, 'and carries who was really at the keyboard');
        $this->assertSame($admin->getKey(), $stamp['id']);
        $this->assertSame($admin->email, $stamp['email']);
    }

    public function test_ordinary_work_is_not_stamped(): void
    {
        $admin = $this->member('Administrator');
        $this->actAs($admin);

        $record = Beneficiary::create(['name' => 'Landlord', 'payment_type' => 'IBFT']);

        $entry = \App\Models\ActivityLog::query()
            ->where('subject_type', Beneficiary::class)
            ->where('subject_id', $record->getKey())
            ->latest('id')
            ->first();

        $this->assertArrayNotHasKey('impersonated_by', $entry->properties->all());
    }

    // --- the banner ---------------------------------------------------------

    public function test_the_panel_shows_a_banner_and_a_way_back_while_impersonating(): void
    {
        // Acting as somebody else without realising it is the whole risk, so the
        // banner is asserted on a real panel response rather than trusted.
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');

        $this->actAs($admin);
        $this->impersonation->start($employee);

        $this->get(\Filament\Facades\Filament::getPanel('admin')->getUrl($this->company))
            ->assertOk()
            ->assertSee('You are signed in as', escape: false)
            ->assertSee($employee->name, escape: false)
            ->assertSee('Stop impersonating', escape: false)
            ->assertSee(route('impersonate.stop'), escape: false);
    }

    public function test_the_banner_is_absent_when_not_impersonating(): void
    {
        $admin = $this->member('Administrator');
        $this->actAs($admin);

        $this->get(\Filament\Facades\Filament::getPanel('admin')->getUrl($this->company))
            ->assertOk()
            ->assertDontSee('Stop impersonating');
    }

    // --- the button ---------------------------------------------------------

    public function test_the_action_is_offered_only_for_rows_that_may_be_impersonated(): void
    {
        $admin = $this->member('Administrator');
        $employee = $this->member('Employee');
        $superAdmin = $this->member('Employee', ['is_super_admin' => true]);
        $inactive = $this->member('Employee', ['status' => 0]);

        $this->actAs($admin);

        Livewire::test(ListUsers::class)
            ->assertActionVisible(TestAction::make('impersonate')->table($employee->getKey()))
            ->assertActionHidden(TestAction::make('impersonate')->table($superAdmin->getKey()))
            ->assertActionHidden(TestAction::make('impersonate')->table($inactive->getKey()))
            ->assertActionHidden(TestAction::make('impersonate')->table($admin->getKey()));
    }
}
