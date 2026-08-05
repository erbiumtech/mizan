<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Platform\Resources\ActivityLogs\PlatformActivityLogResource;
use App\Modules\Core\Filament\Platform\Resources\Permissions\PermissionResource;
use App\Modules\Core\Filament\Platform\Resources\Users\Pages\ListPlatformUsers;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Accounts and the audit trail, seen from the installation rather than from a company.
 *
 * The distinction the whole panel rests on, tested from both sides: the company panel's
 * user list is scoped to its own members and must stay that way, and this one is not
 * scoped, which is what it is for.
 */
class PlatformUsersAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $first;

    private Company $second;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->first = Company::factory()->create(['name' => 'First AG']);
        $this->second = Company::factory()->create(['name' => 'Second AG']);

        $this->superAdmin = User::factory()->create(['is_super_admin' => true, 'name' => 'Platform Admin']);

        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();
    }

    // ---- Users ---------------------------------------------------------------

    public function test_it_lists_accounts_from_every_company(): void
    {
        $here = User::factory()->create(['name' => 'Ali']);
        $there = User::factory()->create(['name' => 'Sana']);
        $this->first->users()->attach($here);
        $this->second->users()->attach($there);

        Livewire::test(ListPlatformUsers::class)
            ->assertCanSeeTableRecords([$here, $there]);
    }

    public function test_it_includes_platform_accounts_which_the_company_list_hides(): void
    {
        // They are the accounts this screen exists to manage. On a company's own list they
        // are somebody else's staff, which is what exceptPlatformAdmins() is for.
        Livewire::test(ListPlatformUsers::class)
            ->assertCanSeeTableRecords([$this->superAdmin]);

        // And the company panel still hides them — asked by somebody who is not one
        // themselves, which is the condition exceptPlatformAdmins() turns on: a platform
        // admin looking at a company's list is allowed to see their own kind.
        $member = User::factory()->create();
        $this->first->users()->attach($member);
        $this->first->users()->attach($this->superAdmin);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant($this->first);

        $this->assertNull(
            UserResource::getEloquentQuery()->find($this->superAdmin->getKey()),
            'the company list still excludes platform accounts',
        );
    }

    public function test_an_account_with_no_company_is_visible_here(): void
    {
        // It can sign in nowhere, which is easy to create by forgetting the last field on
        // the form and invisible from inside any company.
        $orphan = User::factory()->create(['name' => 'Nobody']);

        Livewire::test(ListPlatformUsers::class)->assertCanSeeTableRecords([$orphan]);
    }

    public function test_a_user_can_be_created_with_its_company_access(): void
    {
        Livewire::test(\App\Modules\Core\Filament\Platform\Resources\Users\Pages\CreatePlatformUser::class)
            ->fillForm([
                'name' => 'New Person',
                'email' => 'new@erbium.example',
                'password' => 'password123',
                'companies' => [$this->first->getKey()],
                'status' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'new@erbium.example')->firstOrFail();

        $this->assertTrue($created->companies()->whereKey($this->first->getKey())->exists());
        $this->assertFalse($created->companies()->whereKey($this->second->getKey())->exists());
    }

    public function test_the_company_panels_user_list_is_still_scoped(): void
    {
        // The reason this is a second resource rather than a widened one.
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $this->first->users()->attach($mine);
        $this->second->users()->attach($theirs);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant($this->first);

        $visible = UserResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($visible->contains($mine->getKey()));
        $this->assertFalse($visible->contains($theirs->getKey()));
    }

    // ---- The last platform admin ---------------------------------------------

    /**
     * There is no way back from an installation with no platform admin: this panel admits
     * super admins only, and `is_super_admin` is granted from it. It would have to be
     * fixed in the database.
     */
    public function test_the_last_platform_admin_cannot_be_deleted(): void
    {
        $this->expectExceptionMessage('only platform admin left');

        $this->superAdmin->delete();
    }

    public function test_the_last_platform_admin_cannot_stand_down(): void
    {
        $this->expectExceptionMessage('only platform admin left');

        $this->superAdmin->update(['is_super_admin' => false]);
    }

    public function test_the_last_platform_admin_cannot_be_deactivated(): void
    {
        // An inactive account cannot sign in, so it is not somebody who can appoint a
        // replacement.
        $this->expectExceptionMessage('only platform admin left');

        $this->superAdmin->update(['status' => 0]);
    }

    public function test_one_of_two_platform_admins_can_be_removed(): void
    {
        $second = User::factory()->create(['is_super_admin' => true]);

        $second->delete();

        $this->assertSame(1, User::where('is_super_admin', true)->count());
    }

    public function test_an_inactive_second_platform_admin_does_not_count(): void
    {
        // Otherwise the last two could be removed one after the other, each looking safe.
        User::factory()->inactive()->create(['is_super_admin' => true]);

        $this->expectExceptionMessage('only platform admin left');

        $this->superAdmin->delete();
    }

    public function test_an_ordinary_account_is_unaffected(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertNull(User::find($user->getKey()));
    }

    // ---- Permissions ---------------------------------------------------------

    public function test_permissions_are_administered_here_and_not_by_a_company(): void
    {
        // A permission row means something only because some can('…') names it: inventing
        // one does nothing, and deleting one breaks every company at once.
        $this->assertTrue(PermissionResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(PermissionResource::canAccess());
    }

    // ---- Audit ---------------------------------------------------------------

    public function test_the_audit_trail_shows_every_company(): void
    {
        $this->writeActivity($this->first, 'first-company thing');
        $this->writeActivity($this->second, 'second-company thing');

        Livewire::test(\App\Modules\Core\Filament\Platform\Resources\ActivityLogs\Pages\ListPlatformActivityLogs::class)
            ->assertSee('first-company thing')
            ->assertSee('second-company thing');
    }

    public function test_it_says_which_company_each_entry_belongs_to(): void
    {
        // Without that column a shared list is a pile of events with no context.
        $this->writeActivity($this->first, 'a thing happened');

        Livewire::test(\App\Modules\Core\Filament\Platform\Resources\ActivityLogs\Pages\ListPlatformActivityLogs::class)
            ->assertSee('First AG');
    }

    public function test_a_company_still_has_its_own_audit_trail(): void
    {
        // Kept rather than moved: reading your own trail is legitimate, and taking it away
        // would be a loss of function dressed up as a move.
        $this->writeActivity($this->first, 'mine');
        $this->writeActivity($this->second, 'theirs');

        // Bound rather than makeCurrent(): the switch-tenant pipeline needs a real second
        // connection, which the single-database suite does not have. Company::current()
        // reads this, which is what ActivityLog's scope asks.
        app()->instance('currentTenant', $this->first);

        $descriptions = ActivityLog::query()->pluck('description');

        $this->assertTrue($descriptions->contains('mine'));
        $this->assertFalse($descriptions->contains('theirs'), 'and only its own');

        app()->forgetInstance('currentTenant');
    }

    public function test_the_trail_cannot_be_written_to_or_edited_from_here(): void
    {
        $this->assertFalse(PlatformActivityLogResource::canCreate());
        $this->assertFalse(PlatformActivityLogResource::canEdit(new ActivityLog));
        $this->assertFalse(PlatformActivityLogResource::canDelete(new ActivityLog));
    }

    private function writeActivity(Company $company, string $description): void
    {
        // Written with the query builder: the model stamps company_id from the *current*
        // company, and there is none on this panel.
        DB::table('activity_log')->insert([
            'log_name' => 'Test',
            'description' => $description,
            'company_id' => $company->getKey(),
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
