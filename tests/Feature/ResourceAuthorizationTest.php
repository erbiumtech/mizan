<?php

namespace Tests\Feature;

use App\Filament\Livewire\CommandPalette;
use App\Modules\Accounting\Filament\Resources\Accounts\AccountResource;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource;
use App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Filament treats a model with no registered policy as *allowed*, so forgetting
 * a policy silently opens a resource to every role — via the sidebar, a direct
 * URL, and the ⌘K palette. These tests pin that shut.
 */
class ResourceAuthorizationTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function actAsEmployee(): User
    {
        $this->seed(PermissionSeeder::class);

        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        $user = User::factory()->create();
        $company->users()->attach($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->assignRole('Employee');

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        return $user;
    }

    /** @return array<int, string> */
    private function paletteLabels(string $term): array
    {
        $results = Livewire::test(CommandPalette::class)->call('search', $term)->effects['returns'][0] ?? [];

        return collect($results)
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('label'))
            ->all();
    }

    /**
     * The invariant: no resource may rely on Filament's permissive default.
     * A new resource without a policy fails here rather than in production.
     */
    public function test_every_resource_model_has_a_policy(): void
    {
        $this->actAsEmployee();

        $missing = [];

        foreach (Filament::getResources() as $resource) {
            $model = $resource::getModel();

            if (Gate::getPolicyFor($model) === null) {
                $missing[] = class_basename($resource).' ('.class_basename($model).')';
            }
        }

        $this->assertSame([], $missing, "These resources have no policy, so Filament allows everyone:\n - ".implode("\n - ", $missing));
    }

    /** Privileged areas must be closed to a plain employee. */
    public function test_an_employee_cannot_access_privileged_resources(): void
    {
        $this->actAsEmployee();

        $closed = [
            CompanyResource::class,
            JournalEntryLineResource::class,
            InvoiceResource::class,
            CustomFieldResource::class,
            UserResource::class,
            AccountResource::class,
            RoleResource::class,
        ];

        foreach ($closed as $resource) {
            $this->assertFalse($resource::canAccess(), class_basename($resource).' must be closed to an employee');
            $this->assertFalse($resource::canGloballySearch(), class_basename($resource).' must not be globally searchable by an employee');
        }
    }

    /**
     * The palette used to gate on `canViewAny()`, which skips a resource's own
     * `canAccess()` — so an employee was offered "Companies" and "New Company"
     * even though company management is super-admin only.
     */
    public function test_the_palette_hides_resources_the_role_cannot_access(): void
    {
        $this->actAsEmployee();

        // 'companies', not 'company': the scorer is a substring match and
        // "Companies" does not contain "company" — a wrong term here would make
        // the assertion pass no matter what.
        $this->assertNotContains('Companies', $this->paletteLabels('companies'));
        $this->assertNotContains('New Company', $this->paletteLabels('company'));
        $this->assertNotContains('Journal Entry Lines', $this->paletteLabels('journal'));
        $this->assertNotContains('Invoice Lines', $this->paletteLabels('invoice'));
        $this->assertNotContains('Custom Fields', $this->paletteLabels('custom field'));
        $this->assertNotContains('Users', $this->paletteLabels('user'));
        $this->assertNotContains('Chart Of Accounts', $this->paletteLabels('account'));
    }

    /** …while still offering what the role legitimately has. */
    public function test_the_palette_still_offers_the_roles_own_areas(): void
    {
        $this->actAsEmployee();

        $this->assertContains('Payslips', $this->paletteLabels('payslip'));
        $this->assertContains('Employee Settings', $this->paletteLabels('employee setting'));
        $this->assertContains('Dashboard', $this->paletteLabels('dash'));
    }

    /** A super admin keeps full reach — the fix must not over-restrict. */
    public function test_a_super_admin_may_administer_companies_but_not_from_a_company(): void
    {
        $this->seed(PermissionSeeder::class);

        $company = Company::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $company->users()->attach($admin);

        $this->actingAs($admin);
        $this->setCurrentTenant($company);

        $this->assertTrue(CompanyResource::canAccess());

        // Not from here, though. Companies moved to the platform panel, and the palette
        // offers what the panel you are standing in registers — a super admin inside a
        // company gets that company's work, and administers the installation next door.
        $this->assertNotContains('Companies', $this->paletteLabels('companies'));
        $this->assertNotContains('New Company', $this->paletteLabels('company'));
    }
}
