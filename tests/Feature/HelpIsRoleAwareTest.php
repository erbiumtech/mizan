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

    public function test_sections_the_reader_cannot_act_on_are_hidden(): void
    {
        $html = $this->helpHtmlFor('Accountant');

        // Accountant holds Create and Submit, so those stay.
        $this->assertStringContainsString('Creating one', $html);
        $this->assertStringContainsString('Submitting for approval', $html);

        // They hold none of Approve/Reject/Post/Reverse/Delete.
        $this->assertStringNotContainsString('>Approval<', $html);
        $this->assertStringNotContainsString('>Posting<', $html);
        $this->assertStringNotContainsString('Fixing a posted mistake', $html);
        $this->assertStringNotContainsString('Deleting an entry', $html);
    }

    public function test_a_manager_keeps_the_sections_an_accountant_loses(): void
    {
        $html = $this->helpHtmlFor('Manager');

        // Manager holds approve/reject/post/reverse but not delete.
        $this->assertStringContainsString('Approval', $html);
        $this->assertStringContainsString('Posting', $html);
        $this->assertStringContainsString('Fixing a posted mistake', $html);
        $this->assertStringNotContainsString('Deleting an entry', $html);
    }

    public function test_unannotated_sections_are_always_kept(): void
    {
        $html = $this->helpHtmlFor('Accountant');

        // The safe default, and load bearing: an Accountant still needs to know
        // what an entry is, which entries reach reports, and the troubleshooting
        // — including "Why can't I approve this entry?", which is aimed squarely
        // at the reader who cannot.
        $this->assertStringContainsString('What a journal entry is', $html);
        $this->assertStringContainsString('Where it shows up', $html);
        $this->assertStringContainsString('Quick answers', $html);
        $this->assertStringContainsString("Entries you didn't create yourself", $html);
    }

    public function test_the_reader_is_told_that_something_was_hidden(): void
    {
        $html = $this->helpHtmlFor('Accountant');

        // Otherwise a filtered doc just looks like it is missing steps.
        $this->assertStringContainsString('hidden here because your role cannot', $html);
    }

    public function test_the_annotation_never_leaks_into_the_rendered_page(): void
    {
        foreach (['Accountant', 'Manager', 'Administrator'] as $role) {
            $this->assertStringNotContainsString(
                'requires:',
                $this->helpHtmlFor($role),
                "The requires annotation leaked into the {$role} panel.",
            );
        }
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

    public function test_a_super_admin_with_no_role_here_still_sees_everything(): void
    {
        $company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        // How a super admin normally works: switched into a company without
        // holding any role in it. hasPermissionTo() would say no to everything,
        // while the Gate::before bypass says yes to everything — and the panel
        // has to agree with the Gate, not with the lookup.
        $user = User::factory()->create(['status' => 1, 'is_super_admin' => true]);
        $company->users()->attach($user->getKey());
        $this->actingAs($user);
        $this->setCurrentTenant($company);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame([], $user->roles->pluck('name')->all(), 'precondition: no role in this company');

        $html = Livewire::test(ListJournalEntries::class)
            ->mountAction('help')
            ->getMountedActionModalHtml();

        $this->assertStringContainsString('Approval', $html);
        $this->assertStringContainsString('Posting', $html);
        $this->assertStringContainsString('Deleting an entry', $html);
        $this->assertStringNotContainsString('hidden here because your role cannot', $html);
    }

    public function test_no_section_is_gated_so_tightly_that_nobody_can_see_it(): void
    {
        $company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $held = \Spatie\Permission\Models\Role::query()
            ->where('company_id', $company->getKey())
            ->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->all();

        $orphaned = [];

        foreach (glob(resource_path('markdown/help/*.md')) as $path) {
            preg_match_all('/<!--\s*requires:\s*([A-Za-z0-9, ]+?)\s*-->/', file_get_contents($path), $matches);

            foreach ($matches[1] as $list) {
                $names = array_filter(array_map('trim', explode(',', $list)));

                // Any-of semantics: at least one name must be reachable by some
                // role, or the section is dead text nobody but a super admin
                // will ever read.
                if (array_intersect($names, $held) === []) {
                    $orphaned[] = implode(', ', $names).' — '.basename($path);
                }
            }
        }

        $this->assertSame([], array_unique($orphaned), implode("\n", [
            'These sections are gated on permissions no seeded role holds, so no',
            'ordinary user will ever see them however senior:',
            '',
            ...array_unique($orphaned),
        ]));
    }

    private function accessBlock(string $html): string
    {
        preg_match('/You are signed in as.*?Individual records/s', $html, $m);

        return $m[0] ?? '';
    }
}
