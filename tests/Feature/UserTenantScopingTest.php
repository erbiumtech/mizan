<?php

namespace Tests\Feature;

use App\Filament\Livewire\CommandPalette;
use App\Modules\Core\Filament\Resources\Companies\Schemas\CompanyForm;
use App\Modules\Core\Filament\Resources\Users\Pages\CreateUser;
use App\Modules\Core\Filament\Resources\Users\Pages\EditUser;
use App\Modules\Core\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\EditEmployee;
use App\Modules\Employees\Models\Employee;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Every other resource is isolated by its database — one per company — so
 * `Resource::scopeToTenant(false)` is set panel-wide and a query cannot reach
 * another company's rows. `users` is the exception: it lives in the landlord
 * database, shared, with membership held in the `company_user` pivot. These
 * tests pin the boundary that has to be drawn by hand there, because without it
 * a newly registered company's administrator listed, searched, filtered and
 * could open every user account in the system.
 */
class UserTenantScopingTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $mine;

    private Company $theirs;

    private User $admin;

    private User $colleague;

    private User $stranger;

    private User $orphan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->mine = Company::factory()->create(['name' => 'Mine']);
        $this->theirs = Company::factory()->create(['name' => 'Theirs']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->mine->id);
        (new RoleSeeder)->run();

        $this->admin = User::factory()->create(['name' => 'Our Admin']);
        $this->mine->users()->attach($this->admin);

        $this->colleague = User::factory()->create(['name' => 'Our Colleague']);
        $this->mine->users()->attach($this->colleague);

        $this->stranger = User::factory()->create(['name' => 'Other Company User']);
        $this->theirs->users()->attach($this->stranger);

        // Belongs to no company at all — the state a user is left in when their
        // last membership is revoked. Nobody's list.
        $this->orphan = User::factory()->create(['name' => 'Unattached User']);
    }

    private function actAsCompanyAdmin(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->mine->id);
        $this->admin->assignRole('Administrator');

        $this->actingAs($this->admin);
        $this->setCurrentTenant($this->mine);

        return $this->admin;
    }

    public function test_list_shows_only_users_of_the_current_company(): void
    {
        $this->actAsCompanyAdmin();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->admin, $this->colleague])
            ->assertCanNotSeeTableRecords([$this->stranger, $this->orphan]);
    }

    /**
     * The same thing over HTTP, middleware and all, because that is the report
     * this came from: a screenshot of a freshly registered company's Users page
     * listing every account in the system, addresses included.
     */
    public function test_the_rendered_page_does_not_name_another_companys_users(): void
    {
        $this->actAsCompanyAdmin();

        $response = $this->get(UserResource::getUrl('index', ['tenant' => $this->mine]))
            ->assertOk();

        $response->assertSee('Our Colleague');
        $response->assertDontSee('Other Company User');
        $response->assertDontSee($this->stranger->email);
        $response->assertDontSee($this->orphan->email);
    }

    /**
     * The platform's own account is attached to the companies it creates — the
     * provisioner does that, and it holds Administrator there — but it is not one
     * of the company's people. Listed, it came with Deactivate and Edit: a
     * company administrator could lock the installation's owner out of every
     * company in it.
     */
    public function test_the_platform_super_admin_is_not_one_of_a_companys_users(): void
    {
        $platform = User::factory()->create(['name' => 'Platform Owner', 'is_super_admin' => true]);
        $this->mine->users()->attach($platform);

        $this->actAsCompanyAdmin();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->admin, $this->colleague])
            ->assertCanNotSeeTableRecords([$platform]);

        $names = UserResource::table(
            \Filament\Tables\Table::make(Livewire::test(ListUsers::class)->instance())
        )->getFilter('name')->getOptions();

        $this->assertNotContains('Platform Owner', $names);

        // Not by URL either — no Deactivate, no Edit, no delete.
        $this->get(UserResource::getUrl('edit', [
            'record' => $platform->getKey(),
            'tenant' => $this->mine,
        ]))->assertNotFound();
    }

    /** One super admin still administers another; the hiding is for companies. */
    public function test_a_super_admin_still_sees_platform_accounts(): void
    {
        $platform = User::factory()->create(['name' => 'Platform Owner', 'is_super_admin' => true]);
        $this->mine->users()->attach($platform);

        $viewer = User::factory()->create(['name' => 'Second Owner', 'is_super_admin' => true]);
        $this->mine->users()->attach($viewer);

        $this->actingAs($viewer);
        $this->setCurrentTenant($this->mine);

        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$platform]);
    }

    /**
     * The list is only the visible half. A hidden record is still reachable by
     * id unless the resource query is what resolves it — so assert the edit page
     * refuses rather than trusting the table.
     */
    public function test_another_companys_user_cannot_be_opened_by_id(): void
    {
        $this->actAsCompanyAdmin();

        $this->get(UserResource::getUrl('edit', [
            'record' => $this->stranger->getKey(),
            'tenant' => $this->mine,
        ]))->assertNotFound();
    }

    public function test_own_company_user_can_still_be_opened(): void
    {
        $this->actAsCompanyAdmin();

        Livewire::test(EditUser::class, ['record' => $this->colleague->getKey()])
            ->assertOk()
            ->assertSchemaStateSet(['name' => 'Our Colleague'], 'form');
    }

    /**
     * Searching and filtering are their own leaks: the name/email filter
     * options are built from a plain User query, which would list every
     * account in the system as a dropdown option even with the rows hidden.
     */
    public function test_search_and_filter_options_do_not_reach_other_companies(): void
    {
        $this->actAsCompanyAdmin();

        Livewire::test(ListUsers::class)
            ->searchTable('Other Company User')
            ->assertCanNotSeeTableRecords([$this->stranger])
            ->assertCanNotSeeTableRecords([$this->orphan]);

        $names = UserResource::table(
            \Filament\Tables\Table::make(Livewire::test(ListUsers::class)->instance())
        )->getFilter('name')->getOptions();

        $this->assertContains('Our Colleague', $names);
        $this->assertNotContains('Other Company User', $names);
        $this->assertNotContains('Unattached User', $names);
    }

    public function test_command_palette_does_not_surface_other_companies_users(): void
    {
        $this->actAsCompanyAdmin();

        $results = Livewire::test(CommandPalette::class)
            ->call('search', 'User')
            ->effects['returns'][0] ?? [];

        $labels = collect($results)
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('label'))
            ->all();

        $this->assertNotContains('Other Company User', $labels);
        $this->assertNotContains('Unattached User', $labels);
    }

    /**
     * The counterpart to scoping reads: a user created here has to become a
     * member, or the administrator who just created them could not see them.
     */
    public function test_creating_a_user_attaches_them_to_the_current_company(): void
    {
        $this->actAsCompanyAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Fresh Hire',
                'email' => 'fresh.hire@example.test',
                'password' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->acrossCompanies()->where('email', 'fresh.hire@example.test')->sole();

        $this->assertTrue($this->mine->users()->whereKey($created->getKey())->exists());
        $this->assertFalse($this->theirs->users()->whereKey($created->getKey())->exists());

        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$created]);

        // Their employee record belongs in this company, since they do.
        $this->assertTrue(Employee::query()->where('user_id', $created->getKey())->exists());
    }

    /**
     * The other half of that: an account created here *for another company* — a
     * super admin can, the Company Access field is theirs — gets no employee
     * record in this company's database. Creating one anyway is how a company
     * ends up with an employee belonging to someone who works elsewhere: no name
     * in the list, and a form that will not save.
     */
    public function test_a_user_created_for_another_company_gets_no_employee_here(): void
    {
        $superAdmin = User::factory()->create(['name' => 'Super', 'is_super_admin' => true]);
        $this->mine->users()->attach($superAdmin);

        $this->actingAs($superAdmin);
        $this->setCurrentTenant($this->mine);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Their Hire',
                'email' => 'their.hire@example.test',
                'password' => 'password123',
                'companies' => [$this->theirs->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->acrossCompanies()->where('email', 'their.hire@example.test')->sole();

        $this->assertFalse(
            Employee::query()->where('user_id', $created->getKey())->exists(),
            'An employee row here would belong to someone who does not work here.'
        );
    }

    /**
     * And the employee left behind by the version of that bug already in the
     * database stays editable — the picker is read-only, so hiding its own value
     * only breaks the form.
     */
    public function test_an_employee_whose_user_left_the_company_can_still_be_edited(): void
    {
        $this->actAsCompanyAdmin();

        $employee = Employee::create([
            'user_id' => $this->stranger->getKey(),
            'employee_id' => 'EMP-LEGACY',
            'is_active' => 1,
        ]);

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->assertOk()
            ->assertFormSet(['user_id' => $this->stranger->getKey()]);
    }

    /**
     * Assigning a company its first administrator means picking someone who is
     * not a member yet, so the Companies resource — super admin only — has to
     * see past the boundary. If the scope swallowed this list, no company could
     * ever be handed to anyone.
     */
    public function test_super_admin_company_admin_picker_still_lists_every_user(): void
    {
        $superAdmin = User::factory()->create(['name' => 'Super', 'is_super_admin' => true]);
        $this->mine->users()->attach($superAdmin);

        $this->actingAs($superAdmin);
        $this->setCurrentTenant($this->mine);

        $options = CompanyForm::configure(
            \Filament\Schemas\Schema::make(Livewire::test(ListUsers::class)->instance())
        )->getComponent(fn ($component) => $component instanceof \Filament\Forms\Components\Field
            && $component->getName() === 'admin_user_id')
            ->getOptions();

        $this->assertContains('Other Company User', $options);
        $this->assertContains('Unattached User', $options);
    }

    /**
     * Membership is granted from the Companies side. Leaving it on the user form
     * for a company administrator both named every company in the system and let
     * them hand an account access to one.
     */
    public function test_company_access_field_is_hidden_from_company_admins(): void
    {
        $this->actAsCompanyAdmin();

        $this->assertNull(
            Livewire::test(EditUser::class, ['record' => $this->colleague->getKey()])
                ->instance()
                ->getSchema('form')
                ->getComponent(fn ($component) => $component instanceof \Filament\Forms\Components\Field
                    && $component->getName() === 'companies'
                    && $component->isVisible())
        );
    }

    /**
     * Login happens before any company is current, and it has to find the
     * account whatever company they end up in — the scope must not reach it.
     */
    public function test_authentication_still_finds_users_before_a_company_is_current(): void
    {
        Company::forgetCurrent();
        \Filament\Facades\Filament::setTenant(null);

        $this->assertTrue(\Illuminate\Support\Facades\Auth::attempt([
            'email' => $this->stranger->email,
            'password' => 'password',
        ]), 'Credentials must resolve before any company is current.');

        $this->assertAuthenticatedAs($this->stranger);
    }
}
