<?php

namespace Tests\Feature;

use App\Support\ModuleManifest;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * When the merged manifest cache counts as stale.
 *
 * **Written because a missing rule cost a debugging session in Phase 6.1 of
 * `docs/reports-expansion-plan.md`.** That phase added a `datasets` table to `ModuleManifest` and to nine
 * `module.php` files. Editing the manifests marked the cache stale, so it rebuilt — but any process whose cache
 * predated only the *code* change kept serving a merged array with no `datasets` key at all. The symptom was
 * `ModuleMap::datasets()` returning eleven in one process and nothing in another, which reads as a broken
 * registry rather than a stale file.
 *
 * That direction of failure is the one `ModuleManifest`'s own docblock calls out: a table that is missing looks
 * like a set of modules that own nothing, and nothing throws. So this class's own mtime is now part of
 * staleness, and this is the test that says so.
 *
 * **Deterministic rather than dependent on real mtimes.** The fixture manifest is touched into the past, so the
 * only thing that can make a cache built after it stale is the rule under test. Asserting against the real
 * `app/Modules` would pass or fail depending on which file happened to be edited last.
 */
class ModuleManifestCacheTest extends TestCase
{
    /** A moment comfortably before any source file in this repository. */
    private const LONG_AGO = 946_684_800; // 1 January 2000

    /**
     * `isStale()` against a fixture module tree, with `built_at` under our control.
     *
     * @param  array<string, mixed>  $cached
     */
    private function isStale(array $cached): bool
    {
        $base = sys_get_temp_dir().'/mizan-manifest-cache-'.uniqid('', true);
        @mkdir($base.'/Modules/fixture', 0777, true);

        $manifest = $base.'/Modules/fixture/module.php';
        file_put_contents($manifest, '<?php return '.var_export(['key' => 'fixture', 'label' => 'Fixture'], true).';');

        // Into the past, so the manifest's own mtime can never be the reason a cache is stale.
        touch($manifest, self::LONG_AGO);

        $original = $this->app->path();
        $this->app->useAppPath($base);

        try {
            $method = new ReflectionMethod(ModuleManifest::class, 'isStale');

            return (bool) $method->invoke(null, $base.'/cache.php', [
                'sources' => [$manifest],
                ...$cached,
            ]);
        } finally {
            $this->app->useAppPath($original);
            ModuleManifest::flush();
            exec('rm -rf '.escapeshellarg($base));
        }
    }

    private function classMtime(): int
    {
        return (int) filemtime((new ReflectionClass(ModuleManifest::class))->getFileName());
    }

    /**
     * A cache built after every manifest but before this class last changed is stale.
     *
     * The rule the missing check would have caught: the manifests are untouched — theirs is the year 2000 — so
     * nothing but the class's own mtime can be the reason.
     */
    public function test_a_cache_older_than_the_manifest_code_is_stale(): void
    {
        $this->assertTrue($this->isStale(['built_at' => $this->classMtime() - 1]));
    }

    /** And a cache built after both is not. */
    public function test_a_cache_newer_than_everything_is_fresh(): void
    {
        $this->assertFalse($this->isStale(['built_at' => $this->classMtime() + 1]));
    }

    /** A changed manifest still marks it stale, which is the rule that already existed. */
    public function test_a_changed_manifest_still_marks_the_cache_stale(): void
    {
        $this->assertTrue($this->isStale(['built_at' => self::LONG_AGO - 1]));
    }

    /**
     * A cache that recorded no sources is stale, whatever its timestamp.
     *
     * The empty-discovery case: a cache written when the modules directory could not be read would otherwise
     * be served for ever, and an empty registry reports every class as belonging to no module.
     */
    public function test_a_cache_with_no_sources_is_stale(): void
    {
        $this->assertTrue($this->isStale(['built_at' => $this->classMtime() + 1, 'sources' => []]));
    }

    /** And so is one whose recorded sources are not the ones on disk — a module added or removed. */
    public function test_a_cache_listing_different_sources_is_stale(): void
    {
        $this->assertTrue($this->isStale([
            'built_at' => $this->classMtime() + 1,
            'sources' => ['/nowhere/Modules/gone/module.php'],
        ]));
    }
}
