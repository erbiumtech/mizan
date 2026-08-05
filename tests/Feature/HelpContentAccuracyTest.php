<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The help docs under resources/markdown/help are dense with permission names —
 * they are how a reader works out who to grant what. Those names are factual
 * claims about the seeder, and nothing about renaming a permission prompts
 * anyone to grep 60 markdown files, so the docs would go on naming a permission
 * that no longer exists and quietly send readers looking for it.
 *
 * Structural coverage (does each page offer Help at all) is HelpCoverageTest;
 * this is the one that needs the database, because the seeder is the authority
 * on what a permission is actually called.
 */
class HelpContentAccuracyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Permission names the docs mention *precisely because they do not exist* —
     * "there's no separate `CurrencyView`", "rather than the usual `RoleView`
     * pattern". Telling a reader which name NOT to go looking for is the most
     * useful sentence in some of these docs, so it must not be what breaks the
     * build.
     *
     * Adding to this list is a deliberate act: it asserts the name is absent
     * from the seeder on purpose. If one of these ever becomes a real
     * permission, test_names_cited_as_absent_are_still_absent below fails and
     * the doc needs rewriting, not the allowlist extending.
     *
     * @var array<int, string>
     */
    private const CITED_AS_NONEXISTENT = [
        // currencies.md — Currency reuses the Chart of Accounts permissions.
        'CurrencyView',
        'CurrencyCreate',
        // email-templates.md — Administrator role, no per-permission grant.
        'EmailTemplateView',
        // roles.md — Role really uses viewAnyRole/createRole/… instead.
        'RoleView',
        'RoleCreate',
    ];

    /**
     * A backticked token in the docs is taken to be a permission claim when it
     * is multi-word PascalCase (`AccountView`, `PettyCashReplenish`) or one of
     * the camelCase abilities (`viewAnyRole`).
     *
     * Deliberately shape-based rather than a list of known action suffixes: the
     * seeder holds BankStatementMatch, FixedAssetDispose, InvoiceIssue,
     * PayrollRunLock, StockAdjust and UserImpersonate, none of which share a
     * suffix with the View/Create/Update/Delete majority, so a suffix list
     * silently stops checking exactly the unusual names most likely to be
     * renamed. Single words (`Draft`, `Posted`, `Active`) do not match, which is
     * what keeps status and field names out.
     */
    private const PERMISSION_SHAPE = '/^([A-Z][a-z]+([A-Z][a-z]*)+|(viewAny|view|create|update|delete|restore|forceDelete)[A-Z][A-Za-z]*)$/';

    public function test_every_permission_the_help_docs_name_is_seeded(): void
    {
        $this->seed(PermissionSeeder::class);

        $seeded = Permission::pluck('name')->unique()->all();
        $unknown = [];

        foreach ($this->citedPermissions() as $name => $files) {
            if (in_array($name, self::CITED_AS_NONEXISTENT, true)) {
                continue;
            }

            if (! in_array($name, $seeded, true)) {
                $unknown[] = $name.' — '.implode(', ', $files);
            }
        }

        sort($unknown);

        $this->assertSame([], $unknown, implode("\n", [
            'These help docs name a permission that PermissionSeeder does not create,',
            'so the doc is telling readers to grant something that does not exist.',
            'Either the doc has the name wrong, or the permission was renamed and the',
            'doc was not. If the name is mentioned in order to say it does NOT exist,',
            'add it to CITED_AS_NONEXISTENT instead.',
            '',
            ...$unknown,
        ]));
    }

    public function test_names_cited_as_absent_are_still_absent(): void
    {
        $this->seed(PermissionSeeder::class);

        $seeded = Permission::pluck('name')->unique()->all();

        // The allowlist above is not a mute button — each entry claims the name
        // is absent from the seeder. Once it exists, the prose built on "there
        // is no such permission" has become wrong.
        $nowReal = array_values(array_intersect(self::CITED_AS_NONEXISTENT, $seeded));

        $this->assertSame([], $nowReal, implode("\n", [
            'These names are in CITED_AS_NONEXISTENT — the docs mention them to tell',
            'readers they do not exist — but the seeder now creates them. The prose',
            'saying otherwise is wrong; fix the doc and drop the entry.',
            '',
            ...$nowReal,
        ]));
    }

    public function test_the_scan_actually_finds_permission_names(): void
    {
        // Guards the guard: a regex or glob that quietly matches nothing would
        // leave both tests above passing while checking no claims at all.
        $cited = $this->citedPermissions();

        $this->assertGreaterThan(80, count($cited));
        $this->assertArrayHasKey('AccountView', $cited);
        $this->assertArrayHasKey('viewAnyRole', $cited);
    }

    /**
     * Both bodies of documentation: the per-screen help panels and the
     * cross-module manual chapters. The manual names permissions just as freely,
     * and being a walkthrough rather than a reference makes a wrong name there
     * more misleading, not less.
     *
     * @return array<int, string>
     */
    private static function documentPaths(): array
    {
        return [
            ...File::glob(resource_path('markdown/help/*.md')),
            ...File::glob(resource_path('markdown/manual/*.md')),
        ];
    }

    /**
     * Permission names cited across the documentation, mapped to the files
     * citing them so a failure names the file to open.
     *
     * @return array<string, array<int, string>>
     */
    private function citedPermissions(): array
    {
        $cited = [];

        foreach (static::documentPaths() as $path) {
            preg_match_all('/`([A-Za-z]+)`/', File::get($path), $matches);

            foreach (array_unique($matches[1]) as $token) {
                if (preg_match(self::PERMISSION_SHAPE, $token)) {
                    $cited[$token][] = basename($path);
                }
            }
        }

        ksort($cited);

        return $cited;
    }
}
