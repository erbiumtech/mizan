<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Modules\Core\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Modules\Core\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The Activity Log screens name the person behind each entry, and how they get
 * that name is the whole of this file.
 *
 * `causer` is a morphTo, and both screens read it out of a state closure —
 * `$record->causer?->name ?? 'System'` — rather than through a `causer.name`
 * column. Filament eager-loads a column's relation only when the column is named
 * for it (a name without a dot is not a relation as far as
 * HasCellState::hasRelationship is concerned), so nothing loaded it and the page
 * ran one query per row. With lazy loading disabled outside production that is
 * not a slow page but a 500 on the first row, which is how it was found.
 *
 * The fix is an eager load on the resource's query, and the thing that would
 * quietly undo it is somebody removing `getEloquentQuery()` as redundant. Hence
 * a test that turns the guard on explicitly rather than trusting the
 * environment: the suite already runs with it on, but this says why it matters
 * here.
 */
class ActivityLogScreensTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);
        $this->company->users()->syncWithoutDetaching([$this->user->getKey()]);
        $this->user->assignRole('Administrator');

        $this->actingAs($this->user);
        $this->setCurrentTenant($this->company);

        Model::preventLazyLoading();
    }

    /**
     * Several rows, so a per-row load is a per-row query rather than a single
     * one that happens to look fine.
     *
     * @return array<int, ActivityLog>
     */
    private function entries(int $count = 3): array
    {
        $entries = [];

        for ($i = 1; $i <= $count; $i++) {
            activity()
                ->causedBy($this->user)
                ->log("change {$i}");
        }

        // And one with nobody behind it: a console command or a queued job logs
        // without a causer, and the screens print "System" for it. Without a
        // record like this the null branch of the closure is never taken.
        activity()->log('by nobody');

        return ActivityLog::query()->orderBy('id')->get()->all();
    }

    public function test_the_listing_renders_with_lazy_loading_disabled(): void
    {
        $this->entries();

        Livewire::test(ListActivityLogs::class)
            ->assertSuccessful()
            ->assertSee($this->user->name)
            ->assertSee('System');
    }

    /**
     * A row opens the entry in a modal rather than navigating to its page.
     *
     * Two separate things make that true, so both are asserted: the row must not carry a link to the view
     * page — a resource table points rows there by default whenever the resource has one — and the view
     * action must still be mountable, since that is what the row now triggers.
     */
    public function test_a_row_opens_the_entry_in_a_modal(): void
    {
        $entries = $this->entries();
        $entry = $entries[0];

        $page = Livewire::test(ListActivityLogs::class)->assertSuccessful();

        // No link to the view page anywhere in the rendered table.
        $page->assertDontSee(ActivityLogResource::getUrl('view', ['record' => $entry]), escape: false);

        // And the action the row is wired to opens, carrying that entry's own detail.
        $page->mountTableAction('view', $entry)
            ->assertSuccessful()
            ->assertSee($entry->description);
    }

    /**
     * The deep link still works.
     *
     * The view page stays registered on purpose — global search links to it, and one audit entry is a
     * reasonable thing to send somebody. Making the list open a modal must not take that away.
     */
    public function test_the_entry_still_has_a_page_of_its_own(): void
    {
        $entry = $this->entries()[0];

        $this->get(ActivityLogResource::getUrl('view', ['record' => $entry]))
            ->assertOk()
            ->assertSee($entry->description);
    }

    public function test_one_entry_renders_with_lazy_loading_disabled(): void
    {
        $entries = $this->entries();

        Livewire::test(ViewActivityLog::class, ['record' => $entries[0]->getKey()])
            ->assertSuccessful()
            ->assertSee($this->user->name);
    }

    /**
     * The subject is printed from `subject_type` and `subject_id`, which the row
     * already carries. Loading the model to print a name it does not use would
     * be a query per entry over every audited model in the application — some in
     * a tenant database, some since deleted.
     */
    public function test_an_entry_about_a_deleted_record_still_opens(): void
    {
        activity()
            ->causedBy($this->user)
            ->performedOn($this->company)
            ->log('touched something');

        $entry = ActivityLog::query()->latest('id')->firstOrFail();

        $this->company->delete();

        Livewire::test(ViewActivityLog::class, ['record' => $entry->getKey()])
            ->assertSuccessful()
            ->assertSee('Company #'.$entry->subject_id);
    }

    /**
     * `causer` has no column behind it, so the default sort would order by one
     * that does not exist. It was marked sortable until the eager-loading fix,
     * and clicking the header was a SQL error.
     */
    public function test_the_causer_column_is_not_offered_as_a_sort(): void
    {
        $column = ListActivityLogs::getResource()::table(
            app(\Filament\Tables\Table::class, ['livewire' => new ListActivityLogs]),
        )->getColumn('causer');

        $this->assertNotNull($column, 'the Causer column is gone; this test is about it');
        $this->assertFalse($column->isSortable());
    }
}
