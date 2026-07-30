<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Companies\CompanyResource;
use App\Modules\Core\Filament\Resources\Companies\Pages\ListCompanies;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_only_super_admin_can_access_company_resource(): void
    {
        $normal = User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($normal);
        $this->assertFalse(CompanyResource::canAccess());

        $super = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($super);
        $this->assertTrue(CompanyResource::canAccess());
    }

    public function test_super_admin_can_switch_into_any_company(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $super = User::factory()->create(['is_super_admin' => true]);

        // Not a member of either, yet can access both.
        $this->assertTrue($super->canAccessTenant($a));
        $this->assertTrue($super->canAccessTenant($b));
        $this->assertCount(2, $super->getTenants(app(\Filament\Panel::class, [])->id('admin') ?? \Filament\Facades\Filament::getPanel('admin')));
    }

    public function test_assign_admin_attaches_user_and_grants_role(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = Company::factory()->create();
        app()->instance('currentTenant', $company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        $super = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create();

        $this->actingAs($super);
        $this->setCurrentTenant($company);

        Livewire::test(ListCompanies::class)
            ->callTableAction('assignAdmin', $company, ['user_id' => $target->id]);

        $this->assertTrue($company->users()->whereKey($target->id)->exists());
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $this->assertTrue($target->fresh()->hasRole('Administrator'));
    }
}
