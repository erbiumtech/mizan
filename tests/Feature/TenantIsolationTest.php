<?php

namespace Tests\Feature;

use App\Models\TenantModel;
use App\Modules\Core\Models\CompanyModule;
use App\Support\TenantSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Concerns\UsesRealTenantDatabase;
use Tests\TestCase;

/**
 * The two isolation gates the existing tests do not hold.
 *
 * `TenantConnectionIsolationTest` proves the connections separate and two companies'
 * rows invisible to each other; `TenantConnectionGuardTest` greps the source for
 * queries on the wrong connection. What neither catches:
 *
 *  1. **A new model that forgets to extend TenantModel.** It lands its table on the
 *     landlord connection, shared by every company — and the single-database suite
 *     cannot notice, because there the two connections are the same one. So the
 *     landlord side of the partition is pinned as an exact list, the same way
 *     SettingsSectionsTest pins the settings registry: going landlord must be a
 *     decision recorded in this file, not a default.
 *
 *  2. **Tenant state cached in singletons across a switch.** Octane keeps singletons
 *     alive across requests; config/octane.php flushes TenantSettings and Modules
 *     between them, and both also key their caches on Company::current() — which is
 *     the contract that keeps a switch clean *within* a request too. The VPS notes
 *     called this "untested under a resident framework": here two real tenant
 *     databases switch back and forth and the singletons must follow, with no flush
 *     between the switches. EmployeeAccess holds the same contract but needs an
 *     employee graph to observe; these two cover the pattern.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealTenantDatabase;

    /**
     * Every non-tenant model, and why that is correct. A model belongs here only
     * when its rows genuinely serve all companies (or none): identity, membership,
     * licensing, cross-company preferences. Anything holding one company's business
     * data extends TenantModel instead — update this list only with a reason.
     *
     * @var array<int, class-string>
     */
    private const LANDLORD_MODELS = [
        \App\Modules\Core\Models\ActivityLog::class,   // shared table, company_id stamped on write and scoped on read
        \App\Modules\Core\Models\Company::class,       // the tenant record itself — nothing to be scoped to yet
        \App\Modules\Core\Models\CompanyModule::class, // licensing: which company bought what
        \App\Modules\Core\Models\TableView::class,     // per-user view preferences, company-scoped by its global scope
        \App\Modules\Core\Models\User::class,          // identity and membership across companies
    ];

    public function test_every_model_is_deliberately_tenant_or_landlord(): void
    {
        $landlord = $this->discoverModels()
            ->reject(fn (string $class) => is_subclass_of($class, TenantModel::class))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(self::LANDLORD_MODELS, $landlord, implode("\n", [
            'The landlord/tenant partition changed. A model that does not extend TenantModel',
            'keeps its rows in the landlord database, shared by every company — the single-',
            'database test suite cannot catch that, so the split is pinned here. If the new',
            'model is per-company data, extend App\Models\TenantModel; if it truly serves',
            'all companies, add it to LANDLORD_MODELS with the reason.',
            '',
            'Landlord models found:',
            ...$landlord,
        ]));
    }

    public function test_tenant_state_singletons_follow_a_tenant_switch(): void
    {
        $alpha = $this->bootRealTenant('Alpha');
        $this->assertTenantDatabaseIsSeparate();
        app(TenantSettings::class)->set('isolation.probe', 'alpha');

        $beta = $this->bootRealTenant('Beta');
        app(TenantSettings::class)->set('isolation.probe', 'beta');

        $this->assertNotSame($alpha->database, $beta->database);

        // Explicit rows both ways, so the assertion is about the switch rather than
        // about whatever an absent row defaults to.
        foreach ([[$alpha, true], [$beta, false]] as [$company, $enabled]) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => 'crm'],
                ['licensed' => $enabled, 'enabled' => $enabled],
            );
        }
        modules()->flush();

        // No flushes below: switching the current company must be enough on its own.
        // The return to Alpha is the case that matters — a singleton caching "the
        // last tenant's" values passes the first two reads and fails the third.
        $alpha->makeCurrent();
        $this->assertSame('alpha', setting('isolation.probe'));
        $this->assertTrue(modules()->enabled('crm'));

        $beta->makeCurrent();
        $this->assertSame('beta', setting('isolation.probe'));
        $this->assertFalse(modules()->enabled('crm'));

        $alpha->makeCurrent();
        $this->assertSame('alpha', setting('isolation.probe'));
        $this->assertTrue(modules()->enabled('crm'));
    }

    /**
     * Concrete Eloquent models under app/Models and every module's Models
     * directory — the same discovery ModuleCoverageTest walks for the morph map.
     *
     * @return Collection<int, class-string>
     */
    private function discoverModels(): Collection
    {
        $roots = collect([[app_path('Models'), 'App\\Models']]);

        foreach (File::directories(app_path('Modules')) as $moduleDir) {
            $roots->push([$moduleDir.'/Models', 'App\\Modules\\'.basename($moduleDir).'\\Models']);
        }

        return $roots
            ->filter(fn (array $root) => File::isDirectory($root[0]))
            ->flatMap(fn (array $root) => collect(File::allFiles($root[0]))
                ->filter(fn ($file) => $file->getExtension() === 'php')
                ->map(fn ($file) => $root[1].'\\'.Str::of($file->getPathname())
                    ->after($root[0].DIRECTORY_SEPARATOR)
                    ->replace(DIRECTORY_SEPARATOR, '\\')
                    ->beforeLast('.php')->value()))
            ->filter(fn (string $class) => class_exists($class))
            ->filter(fn (string $class) => is_subclass_of($class, Model::class))
            ->reject(fn (string $class) => (new ReflectionClass($class))->isAbstract());
    }
}
