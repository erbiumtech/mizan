<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Filament\Resources\Projects\Pages\EditProject;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Filament\Resources\Projects\RelationManagers\EmployeesRelationManager;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\RelationManagers\ChannelSubscriptionsRelationManager;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Related records on a record page load and change without leaving the page.
 *
 * A record's relation managers are Livewire components of their own, lazy by default: the page renders
 * first with a placeholder where each table goes, and the table arrives in its own request. Switching
 * tabs is a Livewire property (`?relation=` in the address) and searching, filtering and paging are the
 * manager's own Livewire state, so none of them reloads the page. These tests hold that in place —
 * a relation manager that set `$isLazy = false`, or a tab strip rebuilt as links, would fail here.
 */
class RelationManagerLoadingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private Employee $current;

    private Employee $former;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'relations@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();

        $this->project = Project::create(['code' => 'PRJ-RM-1', 'name' => 'ERP rollout', 'status' => 'active']);

        $this->current = Employee::create(['employee_id' => 'EMP-21', 'name' => 'Ayesha Karim', 'gender' => 'Female', 'is_active' => true]);
        $this->former = Employee::create(['employee_id' => 'EMP-22', 'name' => 'Bilal Raza', 'gender' => 'Male', 'is_active' => true]);

        $this->project->employees()->attach($this->current->getKey(), [
            'role' => 'Ledger integration',
            'allocation_pct' => 10,
            'from_date' => today()->subDay()->toDateString(),
        ]);
        $this->project->employees()->attach($this->former->getKey(), [
            'role' => 'Mobile shell',
            'allocation_pct' => 50,
            'from_date' => today()->subMonths(3)->toDateString(),
            'to_date' => today()->subMonth()->toDateString(),
        ]);
    }

    /** The page arrives with a placeholder where the table goes; the table's rows are not in it. */
    public function test_the_record_page_renders_its_related_records_lazily(): void
    {
        $this->assertTrue(EmployeesRelationManager::isLazy());
        $this->assertTrue(ChannelSubscriptionsRelationManager::isLazy());

        $this->get(ProjectResource::getUrl('edit', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('fi-loading-section', false)
            // Not the employee code: the form's manager selects preload every employee, so EMP-21 is on
            // the page with or without the table. The project role is only in the team table's rows.
            ->assertDontSee('Ledger integration');
    }

    /** Choosing another tab is a Livewire property change on the same page, not a navigation. */
    public function test_switching_the_related_records_tab_stays_on_the_page(): void
    {
        $page = Livewire::test(EditProject::class, ['record' => $this->project->getKey()])
            ->assertSet('activeRelationManager', '0');

        $this->assertStringContainsString($this->livewireMarker(EmployeesRelationManager::class), $page->html());

        $page->set('activeRelationManager', '1')
            ->assertSuccessful()
            ->assertNoRedirect()
            ->assertSet('activeRelationManager', '1');

        // Only the chosen tab's manager is on the page; the other is not rendered behind it.
        $this->assertStringContainsString($this->livewireMarker(ChannelSubscriptionsRelationManager::class), $page->html());
        $this->assertStringNotContainsString($this->livewireMarker(EmployeesRelationManager::class), $page->html());
    }

    /** The table's own search and filters work inside the manager, with the page untouched. */
    public function test_the_related_table_searches_and_filters_in_place(): void
    {
        $manager = Livewire::test(EmployeesRelationManager::class, [
            'ownerRecord' => $this->project,
            'pageClass' => EditProject::class,
        ]);

        // "Current assignments only" is on by default: the stint that ended is not listed.
        $manager->assertCanSeeTableRecords([$this->current])
            ->assertCanNotSeeTableRecords([$this->former]);

        $manager->removeTableFilter('current_only')
            ->assertCanSeeTableRecords([$this->current, $this->former]);

        $manager->searchTable('EMP-22')
            ->assertCanSeeTableRecords([$this->former])
            ->assertCanNotSeeTableRecords([$this->current])
            ->assertNoRedirect();
    }

    /** How a child component announces itself in its parent's HTML (as Livewire's assertSeeLivewire reads it). */
    private function livewireMarker(string $component): string
    {
        $name = app('livewire.factory')->resolveComponentName($component);

        return trim(htmlspecialchars(json_encode(['name' => $name])), '{}');
    }
}
