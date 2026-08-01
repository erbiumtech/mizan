<?php

namespace Tests\Feature;

use App\Filament\Livewire\CommandPalette;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function labels(array $results): array
    {
        return collect($results)->flatMap(fn ($g) => collect($g['items'])->pluck('label'))->all();
    }

    private function search(string $q): array
    {
        return Livewire::test(CommandPalette::class)->call('search', $q)->effects['returns'][0] ?? [];
    }

    public function test_palette_renders(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        Livewire::test(CommandPalette::class)->assertSuccessful();
    }

    public function test_admin_sees_resources_and_pages(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        $this->assertContains('Dashboard', $this->labels($this->search('dash')));
        $this->assertContains('Users', $this->labels($this->search('user')));
        $this->assertContains('Payslips', $this->labels($this->search('payslip')));

        // Non-matching query yields nothing.
        $this->assertSame([], $this->search('zzz-nope-nothing'));
    }

    public function test_results_are_permission_scoped(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        $user = User::factory()->create();
        $company->users()->attach($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->assignRole('Employee'); // Payslip/EmployeeSetting view only, no UserView/AccountView

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        // Queried per term rather than off an empty search: the Resources group
        // is capped at PER_GROUP_LIMIT, so an unfiltered list can drop entries
        // the role legitimately has.
        $this->assertContains('Payslips', $this->labels($this->search('payslip')));
        $this->assertContains('Employee Settings', $this->labels($this->search('employee setting')));
        $this->assertNotContains('Users', $this->labels($this->search('user')));
        $this->assertNotContains('Chart Of Accounts', $this->labels($this->search('account')));
    }

    public function test_record_search_finds_a_record(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        \App\Modules\Inventory\Models\Product::create([
            'sku' => 'WIDGET-1', 'name' => 'Blue Widget', 'unit' => 'pcs',
            'valuation_method' => 'fifo', 'reorder_level' => 0, 'is_active' => true,
        ]);

        $labels = $this->labels($this->search('Blue Widget'));
        $this->assertContains('Blue Widget', $labels);

        // Short queries do not trigger record search.
        $this->assertNotContains('Blue Widget', $this->labels($this->search('B')));
    }

    public function test_command_group_offers_switch_company_and_logout(): void
    {
        Gate::before(fn () => true);
        $userCompanyA = Company::factory()->create(['name' => 'Alpha Co']);
        $companyB = Company::factory()->create(['name' => 'Beta Co']);

        $user = User::factory()->create();
        $userCompanyA->users()->attach($user);
        $companyB->users()->attach($user);

        $this->actingAs($user);
        $this->setCurrentTenant($userCompanyA);

        $labels = $this->labels($this->search('switch'));
        $this->assertContains('Switch to Beta Co', $labels);
        $this->assertNotContains('Switch to Alpha Co', $labels); // current company excluded

        $this->assertContains('Log out', $this->labels($this->search('log out')));
    }
}
