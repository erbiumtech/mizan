<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\UserManual;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The end-to-end manual: the cross-module walkthroughs that no single screen's
 * Help panel owns.
 *
 * The failure worth guarding is a chapter that exists on disk but is not in
 * UserManual::CHAPTERS — it renders nowhere, and nothing about writing the file
 * says so. The reverse (listed but missing) is guarded too, because the page
 * skips a missing file rather than erroring, which would otherwise let the
 * manual quietly lose a chapter to a rename.
 */
class UserManualTest extends TestCase
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

        return $user;
    }

    public function test_the_chapter_list_and_the_files_on_disk_agree(): void
    {
        $listed = array_keys(UserManual::CHAPTERS);

        $onDisk = array_map(
            fn (string $path) => basename($path, '.md'),
            File::glob(resource_path('markdown/manual/*.md')),
        );

        sort($listed);
        sort($onDisk);

        $this->assertSame($listed, $onDisk, implode("\n", [
            'UserManual::CHAPTERS and resources/markdown/manual/ disagree.',
            'A file not in the list renders nowhere; an entry with no file leaves a',
            'gap in the manual. Both are silent without this test.',
            '',
            'listed:  '.implode(', ', $listed),
            'on disk: '.implode(', ', $onDisk),
        ]));
    }

    public function test_every_chapter_has_a_title_and_some_content(): void
    {
        $thin = [];

        foreach (UserManual::CHAPTERS as $slug => $title) {
            $this->assertNotSame('', trim($title), "Chapter [{$slug}] has no title.");

            $path = UserManual::pathFor($slug);

            // A stub chapter is worse than an absent one: it appears in the
            // contents and promises something.
            if (File::exists($path) && strlen(trim(File::get($path))) < 400) {
                $thin[] = $slug;
            }
        }

        $this->assertSame([], $thin, 'These chapters are stubs: '.implode(', ', $thin));
    }

    public function test_chapters_use_no_h1_because_the_section_supplies_the_title(): void
    {
        $offenders = [];

        foreach (File::glob(resource_path('markdown/manual/*.md')) as $path) {
            foreach (File::lines($path) as $line) {
                if (str_starts_with($line, '# ')) {
                    $offenders[] = basename($path);
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These chapters open an h1, which duplicates the section heading the',
            'page already renders around them:',
            '',
            ...$offenders,
        ]));
    }

    public function test_no_chapter_opens_by_restating_its_own_title(): void
    {
        $duplicated = [];

        foreach (UserManual::CHAPTERS as $slug => $title) {
            $path = UserManual::pathFor($slug);

            if (! File::exists($path)) {
                continue;
            }

            // The page renders the title as the section heading, so a chapter
            // that also opens with it shows the same sentence twice, one line
            // apart. Four chapters did exactly this on the first pass.
            foreach (File::lines($path) as $line) {
                if (! str_starts_with($line, '## ')) {
                    continue;
                }

                if (trim(substr($line, 3)) === $title) {
                    $duplicated[] = $slug;
                }

                break; // only the opening heading matters
            }
        }

        $this->assertSame([], $duplicated, implode("\n", [
            'These chapters open with an h2 repeating the title the page already',
            'renders above them. Drop the heading and keep the paragraph:',
            '',
            ...$duplicated,
        ]));
    }

    public function test_anchors_are_unique_and_survive_renumbering(): void
    {
        $anchors = array_map(
            fn (string $slug) => UserManual::anchorFor($slug),
            array_keys(UserManual::CHAPTERS),
        );

        // The anchor drops the ordering prefix so a link into the manual keeps
        // working when a chapter moves; that only holds if they stay distinct.
        $this->assertSame(
            count($anchors),
            count(array_unique($anchors)),
            'Two chapters produce the same anchor: '.implode(', ', $anchors),
        );

        $this->assertNotContains('01-getting-started', $anchors);
    }

    public function test_the_page_renders_every_chapter_for_an_ordinary_employee(): void
    {
        // Documentation is not permission-bearing: the person waiting on an
        // approval has as much reason to read how it works as the approver.
        $this->actAs('Employee');

        $this->assertTrue(UserManual::canAccess());

        $page = Livewire::test(UserManual::class)->assertSuccessful();

        foreach (UserManual::CHAPTERS as $slug => $title) {
            if (File::exists(UserManual::pathFor($slug))) {
                $page->assertSee($title);
            }
        }
    }

    public function test_a_signed_out_visitor_cannot_reach_it(): void
    {
        $this->assertFalse(UserManual::canAccess());
    }
}
