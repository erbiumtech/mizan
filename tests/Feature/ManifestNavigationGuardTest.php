<?php

namespace Tests\Feature;

use App\Support\ModuleManifest;
use App\Support\NavigationDomains;
use Tests\TestCase;

/**
 * The two failures that moving navigation claims into the manifests made possible.
 *
 * Which domain a navigation group belongs to used to be a central map in `NavigationDomains`. It is now
 * declared by each module, which removes the last central edit from the new-module checklist — and creates two
 * ways to get it wrong that a central list could not have. See docs/module-packaging-plan.md §5.
 *
 * Both matter for the same reason `NavigationDomainsTest` is as insistent as it is: **a group in no domain
 * appears in no column**, so its screens are reachable only by typing a URL or remembering ⌘K. Nothing throws
 * and no page 404s. So both are caught at manifest-build time instead, where the message names the module.
 *
 * A guard that has never been seen to fire is not a guard, which is why these assert the throw rather than
 * only the happy path.
 */
class ManifestNavigationGuardTest extends TestCase
{
    /**
     * Build a merged manifest from a fake set of module declarations.
     *
     * Goes through the real `build()` by pointing it at fixtures, because the guard lives there and testing a
     * reimplementation of it would prove nothing.
     *
     * @param  array<string, array<string, mixed>>  $modules
     */
    private function buildFrom(array $modules): array
    {
        // A unique parent per call, with the fixtures in a "Modules" child — ModuleManifest reads
        // app_path('Modules'), so pointing the app path at the parent is what makes it read these.
        $base = sys_get_temp_dir().'/mizan-manifest-'.uniqid('', true);

        foreach ($modules as $name => $manifest) {
            @mkdir($base.'/Modules/'.$name, 0777, true);
            file_put_contents(
                $base.'/Modules/'.$name.'/module.php',
                '<?php return '.var_export($manifest, true).';',
            );
        }

        $original = $this->app->path();
        $this->app->useAppPath($base);

        try {
            return ModuleManifest::build();
        } finally {
            $this->app->useAppPath($original);
            ModuleManifest::flush();
            exec('rm -rf '.escapeshellarg($base));
        }
    }

    /** Every real claim names a domain that exists — the happy path, and a guard on the fixtures below. */
    public function test_every_declared_domain_is_a_real_one(): void
    {
        $known = NavigationDomains::keys();
        $merged = ModuleManifest::all();

        $this->assertNotEmpty($merged['navigation'], 'no module claims a navigation group');

        foreach ($merged['navigation'] as $label => $domain) {
            $this->assertContains($domain, $known, "\"{$label}\" claims a domain that does not exist");
        }

        foreach ($merged['navigation_items'] as $page => $domain) {
            $this->assertContains($domain, $known, "{$page} claims a domain that does not exist");
        }
    }

    /**
     * A shared label claimed for two domains throws, naming both modules.
     *
     * The failure the conflict guard exists for: "Employee" is claimed by ten modules, so the merge cannot
     * tell agreement from disagreement, and without this it would resolve to whichever manifest was read last
     * — alphabetical order — moving a whole group of screens to a different domain silently.
     */
    public function test_one_label_claimed_for_two_domains_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/navigation label "Employee" is claimed for/');

        $this->buildFrom([
            'Alpha' => ['key' => 'alpha', 'navigation' => ['Employee' => 'people']],
            'Beta' => ['key' => 'beta', 'navigation' => ['Employee' => 'finance']],
        ]);
    }

    /** Ten modules agreeing on one label is the normal case and merges without complaint. */
    public function test_many_modules_may_claim_the_same_label_for_the_same_domain(): void
    {
        $merged = $this->buildFrom([
            'Alpha' => ['key' => 'alpha', 'navigation' => ['Employee' => 'people']],
            'Beta' => ['key' => 'beta', 'navigation' => ['Employee' => 'people']],
            'Gamma' => ['key' => 'gamma', 'navigation' => ['Employee' => 'people']],
        ]);

        $this->assertSame(['Employee' => 'people'], $merged['navigation']);
    }

    /** A typo in the domain key is refused, because it would render nowhere. */
    public function test_a_claim_naming_an_unknown_domain_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/claims domain "finanace", which is not one of/');

        $this->buildFrom([
            'Alpha' => ['key' => 'alpha', 'navigation' => ['Ledger' => 'finanace']],
        ]);
    }

    /** Same guard for the per-class claims, which have the same consequence. */
    public function test_an_item_claim_naming_an_unknown_domain_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/claims domain "nowhere", which is not one of/');

        $this->buildFrom([
            'Alpha' => ['key' => 'alpha', 'navigation_items' => ['App\\Pages\\Thing' => 'nowhere']],
        ]);
    }
}
