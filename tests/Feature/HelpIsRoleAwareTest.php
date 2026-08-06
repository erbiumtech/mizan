<?php

namespace Tests\Feature;

use App\Filament\Support\HelpAccess;
use App\Modules\Accounting\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The help panel used to render byte-identically for every role that could open
 * it: an Accountant read instructions for approving, posting and reversing —
 * none of which they may do — and a five-row table about everybody else.
 *
 * The prose is still shown in full on purpose. An Accountant who cannot approve
 * needs the Approval section to know where the entry goes next. What changes
 * per reader is the banner saying which part is theirs, and the roles table
 * being cut to their own row.
 */
class HelpIsRoleAwareTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function actAs(string $role): User
    {
        $company = Company::factory()->create();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $user->assignRole($role);

        $this->actingAs($user);
        $this->setCurrentTenant($company);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function helpHtmlFor(string $role): string
    {
        $this->actAs($role);

        return Livewire::test(ListJournalEntries::class)
            ->mountAction('help')
            ->getMountedActionModalHtml();
    }

    public function test_an_accountant_is_told_what_they_can_and_cannot_do(): void
    {
        $html = $this->helpHtmlFor('Accountant');

        $this->assertStringContainsString('You are signed in as Accountant', $html);

        // Accountant holds JournalEntryView/Create/Update/Submit, and none of
        // the approve/post/reverse/delete permissions (see RoleSeeder).
        $this->assertStringContainsString('On this screen you can', $html);
        $this->assertStringContainsString('You cannot', $html);
        $this->assertStringContainsString('approve', $html);
    }

    public function test_the_banner_names_who_to_go_to_instead(): void
    {
        $html = $this->helpHtmlFor('Accountant');

        // Not just "you cannot" — the point is knowing who can. Manager and CEO
        // hold JournalEntryApprove; Administrator holds everything. Compared as
        // text because the verb is wrapped in its own element.
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertMatchesRegularExpression(
            '/You cannot approve, reject, post or reverse — that is Administrator, CEO or Manager/',
            $text,
        );
    }

    public function test_a_manager_is_not_told_they_cannot_approve(): void
    {
        $accountant = $this->helpHtmlFor('Accountant');
        $manager = $this->helpHtmlFor('Manager');

        // The regression this guards: a state-dependent policy check would say
        // "cannot post" for everybody, because a blank record can never be
        // posted. Manager genuinely holds the permission and must not be told
        // otherwise.
        $this->assertStringContainsString('You are signed in as Manager', $manager);
        $this->assertNotSame(
            $this->accessBlock($accountant),
            $this->accessBlock($manager),
            'Accountant and Manager were shown the same access banner.',
        );
    }

    public function test_the_roles_table_is_cut_to_the_readers_own_row(): void
    {
        $html = $this->helpHtmlFor('Accountant');

        // journal-entries.md ships a "| Role | … |" table with a row per role.
        $this->assertStringContainsString('Accountant', $html);
        $this->assertStringNotContainsString('<td>Manager / CEO</td>', $html);
        $this->assertStringNotContainsString('<td>Employee</td>', $html);
    }

    public function test_the_explanatory_prose_is_still_shown_in_full(): void
    {
        $html = $this->helpHtmlFor('Accountant');

        // Deliberately NOT filtered: an Accountant needs to know what happens
        // after they submit, even though they cannot do those steps.
        $this->assertStringContainsString('Submit for Approval', $html);
        $this->assertStringContainsString('Post Entry', $html);
        $this->assertStringContainsString('Reverse Entry', $html);
    }

    public function test_two_roles_no_longer_receive_identical_help(): void
    {
        $strip = fn (string $html) => preg_replace(
            ['/wire:(id|snapshot|effects|key)="[^"]*"/', "/livewireId: '[^']*'/", '/\s+/'],
            ['', '', ' '],
            $html,
        );

        $this->assertNotSame(
            $strip($this->helpHtmlFor('Accountant')),
            $strip($this->helpHtmlFor('Manager')),
            'Help content is still identical across roles.',
        );
    }

    public function test_permission_names_are_read_from_the_policy_not_guessed(): void
    {
        $this->actAs('Accountant');

        $abilities = HelpAccess::abilitiesFor(JournalEntry::class);

        // Straight off JournalEntryPolicy — including the state-coupled ones,
        // whose *permission* is what the banner reports.
        $this->assertSame(['JournalEntryView'], $abilities['viewAny'] ?? null);
        $this->assertSame(['JournalEntryApprove'], $abilities['approve'] ?? null);
        $this->assertSame(['JournalEntryPost'], $abilities['post'] ?? null);
    }

    public function test_a_policy_that_reuses_another_models_permissions_is_followed(): void
    {
        $this->actAs('Accountant');

        // CurrencyPolicy checks AccountView/AccountCreate/AccountUpdate. Any
        // approach that guessed "CurrencyView" from the model name would be
        // wrong here, which is why the names come out of the policy source.
        $abilities = HelpAccess::abilitiesFor(\App\Modules\Accounting\Models\Currency::class);

        $this->assertSame(['AccountView'], $abilities['viewAny'] ?? null);
        $this->assertSame(['AccountCreate'], $abilities['create'] ?? null);
    }

    private function accessBlock(string $html): string
    {
        preg_match('/You are signed in as.*?Individual records/s', $html, $m);

        return $m[0] ?? '';
    }
}
