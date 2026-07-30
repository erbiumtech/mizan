<?php

namespace Tests\Feature;

use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Models\Company;
use App\Models\TableView;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class TableViewTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function current(?Company $c): void
    {
        $c ? app()->instance('currentTenant', $c) : app()->forgetInstance('currentTenant');
    }

    protected function tearDown(): void
    {
        $this->current(null);
        parent::tearDown();
    }

    public function test_views_are_scoped_per_company(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();

        $this->current($a);
        TableView::create(['user_id' => 1, 'resource' => 'x', 'name' => 'A view', 'state' => []]);

        $this->current($b);
        $this->assertSame(0, TableView::count());

        $this->current($a);
        $this->assertSame(1, TableView::count());
    }

    public function test_policy_blocks_editing_others_private_view(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();
        $this->current($company);

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $other->assignRole('Employee');

        $view = TableView::create(['user_id' => $owner->id, 'resource' => 'x', 'name' => 'Owned', 'state' => []]);

        $this->assertTrue($owner->can('update', $view));
        $this->assertFalse($other->can('update', $view));
    }

    public function test_saving_and_applying_a_view_on_a_list_page(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = $this->setCurrentTenant();
        $this->current($company);

        Livewire::test(ListPayslips::class)
            ->set('tableSearch', 'ACME')
            ->callAction('saveView', ['name' => 'My search', 'is_favorite' => true])
            ->assertHasNoActionErrors();

        $view = TableView::where('name', 'My search')->firstOrFail();
        $this->assertSame('ACME', $view->state['search']);
        $this->assertTrue($view->is_favorite);
    }
}
