<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The in-app help for the Journal Entries workflow, opened as a right-hand
 * slide-over from the "Help" header action on the Journal Entries list rather
 * than as its own page. Who may reach the list at all — and therefore this
 * action — is covered by JournalEntryResourceTest.
 */
class JournalEntryHelpTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function actAs(Company $company, string $role): User
    {
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $user->assignRole($role);

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        return $user;
    }

    public function test_the_help_action_renders_the_whole_workflow(): void
    {
        // As an Administrator on purpose: this is the "does the content pipeline
        // work end to end" test, so it wants the unfiltered document. It used to
        // run as an Accountant and assert the posting and reversing sections,
        // which is precisely what the panel now hides from that role — see
        // HelpIsRoleAwareTest for the per-role behaviour.
        $this->actAs(Company::factory()->create(), 'Administrator');

        Livewire::test(ListJournalEntries::class)
            ->mountAction('help')
            ->assertMountedActionModalSee('Submit for Approval')
            ->assertMountedActionModalSee('Post Entry')
            ->assertMountedActionModalSee('Reverse Entry')
            // The rule that is easy to trip over and impossible to guess from
            // the screen alone: content, not just presence, is what matters here.
            ->assertMountedActionModalSeeHtml('cannot approve your own entry');
    }

    public function test_the_journal_entries_list_offers_a_help_action_to_those_who_can_use_it(): void
    {
        $this->actAs(Company::factory()->create(), 'Accountant');

        Livewire::test(ListJournalEntries::class)
            ->assertActionVisible(TestAction::make('help'));
    }
}
