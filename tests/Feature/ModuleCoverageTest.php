<?php

namespace Tests\Feature;

use App\Models\TenantModel;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Support\ModuleMap;
use App\Support\Modules;
use Database\Seeders\PermissionSeeder;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The invariant tests. Everything else about the module system is behaviour;
 * these are the ones that stop a *new* resource, page, widget or model from
 * shipping ungated or unmapped, which is the failure mode that has no symptom
 * until a customer sees a module they did not buy.
 *
 * Deliberately filesystem-driven rather than map-driven: reading the map and
 * checking the map agrees with itself would prove nothing.
 */
class ModuleCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Classes that legitimately have no module: abstract bases and the module
     * state model itself. Anything else must be assigned.
     *
     * @var array<int, class-string>
     */
    /**
     * How a permission name reaches Spatie. Group 1 is the argument list, which
     * is then mined for string literals.
     *
     * @var array<int, string>
     */
    private const PERMISSION_CHECK_PATTERNS = [
        // Spatie's own API. These throw PermissionDoesNotExist on an unknown name.
        "/(?:hasPermissionTo|hasDirectPermission|hasAnyPermission|hasAllPermissions|checkPermissionTo)\(([^)]*)\)/",
        // Single-argument can()/cannot(), which routes to Spatie's Gate::before.
        // Nothing else it could be: a policy ability always passes the model or
        // class as a second argument, so the one-argument form is a permission.
        "/->(?:can|cannot)\(\s*('[A-Za-z]+')\s*\)/",
    ];

    private const UNMAPPED_MODELS = [
        TenantModel::class,   // abstract base for tenant-connection models
        CompanyModule::class, // the module system's own landlord state
    ];

    public function test_every_model_is_in_the_morph_map(): void
    {
        $mapped = array_map(fn (string $class) => ltrim($class, '\\'), ModuleMap::models());
        $missing = array_values(array_diff($this->discoverModels(), $mapped, self::UNMAPPED_MODELS));

        $this->assertSame([], $missing, implode("\n", [
            'These models are not in App\Support\ModuleMap.',
            'Because Relation::enforceMorphMap() is on, using one of these in a',
            'polymorphic relation throws at runtime — and until then, the model has',
            'no module, so it is ungated. Add it to the owning module.',
            '',
            ...$missing,
        ]));
    }

    public function test_morph_map_aliases_are_the_legacy_class_names(): void
    {
        // The aliases are what is already stored in comments.commentable_type,
        // payments.payable_type, activity_log.subject_type, custom_fields.model_type
        // and model_has_roles.model_type. Renaming one silently orphans those rows,
        // so the alias must stay `App\Models\<ClassBasename>` even after the class
        // has moved into app/Modules.
        foreach (ModuleMap::morphMap() as $alias => $class) {
            $this->assertSame(
                'App\Models\\'.class_basename($class),
                $alias,
                "Morph alias [{$alias}] must stay the legacy App\\Models name for [{$class}] "
                .'— existing rows in customer data hold that string.'
            );
        }
    }

    public function test_the_enforced_morph_map_is_the_module_map(): void
    {
        // Guards against the map being built but never enforced, which would let
        // raw FQCNs leak back into the data.
        $this->assertNotEmpty(Relation::morphMap());
        $this->assertTrue(Relation::requiresMorphMap());
        $this->assertSame(ModuleMap::morphMap(), Relation::morphMap());
    }

    public function test_every_model_has_a_policy(): void
    {
        // Laravel guesses App\Models\X -> App\Policies\XPolicy. Phase 5 moves
        // models out of App\Models, which kills that guess for all of them — so
        // this test is what stands between the move and 33 silently open
        // resources. It has already caught one real hole (MPR).
        $unpoliced = [];

        foreach (ModuleMap::models() as $class) {
            if (in_array($class, self::UNMAPPED_MODELS, true)) {
                continue;
            }

            if (! $this->hasResource($class)) {
                continue;
            }

            if (Gate::getPolicyFor($class) === null) {
                $unpoliced[] = $class;
            }
        }

        $this->assertSame([], $unpoliced, implode("\n", [
            'These models back a Filament resource but have no policy registered.',
            'Filament falls back to "allowed" when no policy exists, so each of',
            'these is open to every authenticated user.',
            '',
            ...$unpoliced,
        ]));
    }

    public function test_every_resource_page_and_widget_belongs_to_exactly_one_module(): void
    {
        $unassigned = [];
        $duplicated = [];

        foreach ($this->discoverFilamentClasses() as $class) {
            $owners = $this->modulesOwning($class);

            if ($owners === []) {
                $unassigned[] = $class;
            } elseif (count($owners) > 1) {
                $duplicated[] = $class.' => '.implode(', ', $owners);
            }
        }

        $this->assertSame([], $unassigned, implode("\n", [
            'These Filament classes are not assigned to a module in App\Support\ModuleMap,',
            'so nothing gates them: they stay visible for every company regardless of',
            'what that company has licensed.',
            '',
            ...$unassigned,
        ]));

        $this->assertSame([], $duplicated, "Assigned to more than one module:\n".implode("\n", $duplicated));
    }

    public function test_every_permission_group_belongs_to_a_module(): void
    {
        // A group no module claims would keep showing in the Roles form after the
        // owning module is switched off (phase 4 filters by module).
        $this->seed(PermissionSeeder::class);

        $groups = Permission::query()
            ->distinct()
            ->pluck('group')
            ->filter()
            ->all();

        $orphans = array_values(array_filter(
            $groups,
            fn (string $group) => ModuleMap::moduleForPermissionGroup($group) === null,
        ));

        $this->assertSame([], $orphans, 'Permission groups with no owning module: '.implode(', ', $orphans));
    }

    public function test_every_permission_the_code_checks_is_seeded(): void
    {
        // This has already gone wrong once. AdvancePolicy checked AdvanceView
        // while PermissionSeeder had not been re-run against the database, and
        // hasPermissionTo() *throws* PermissionDoesNotExist instead of denying —
        // so a missing row did not hide the Advances resource, it 500'd every
        // page, because Filament asks every resource whether it may appear in the
        // sidebar. The can() form does not throw, but an unseeded name there is
        // its own kind of bad: it denies everybody, silently, forever.
        $this->seed(PermissionSeeder::class);

        $seeded = Permission::pluck('name')->all();
        $checked = $this->discoverCheckedPermissions();

        $missing = array_diff_key($checked, array_flip($seeded));
        ksort($missing);

        $this->assertSame([], array_keys($missing), implode("\n", [
            'These permissions are checked in code but are not in PermissionSeeder,',
            'so no role can ever hold one. Add them to the seeder (and to whichever',
            'roles should have them in RoleSeeder).',
            '',
            ...array_map(
                fn (string $name, array $sites) => $name.' — '.implode(', ', array_unique($sites)),
                array_keys($missing),
                $missing,
            ),
        ]));
    }

    public function test_every_registry_entry_has_a_label_and_resolvable_requirements(): void
    {
        $names = Modules::names();
        $this->assertNotEmpty($names);

        foreach ($names as $module) {
            $this->assertNotSame($module, Modules::label($module), "Module [{$module}] has no label.");

            foreach (Modules::requirements($module) as $required) {
                $this->assertContains(
                    $required,
                    $names,
                    "Module [{$module}] requires [{$required}], which is not in the registry."
                );
            }
        }
    }

    public function test_the_dependency_graph_has_no_cycles(): void
    {
        // dependents() recurses, so a cycle would hang the admin form rather than
        // report a blocker.
        foreach (Modules::names() as $module) {
            $this->assertNotContains(
                $module,
                Modules::dependents($module),
                "Module [{$module}] depends on itself transitively."
            );
        }
    }

    public function test_core_is_locked_and_cannot_be_switched_off(): void
    {
        $this->assertTrue(Modules::isLocked('core'));

        // Even with an explicit off row, Core stays available — it holds the
        // Modules page, Users and Roles, so a company could otherwise lock
        // itself out of its own administration.
        $company = Company::factory()->create();
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'core'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertTrue(modules()->enabledFor($company->getKey(), 'core'));
        $this->assertTrue(modules()->licensedFor($company->getKey(), 'core'));
    }

    /**
     * @return array<int, class-string>
     */
    private function discoverModels(): array
    {
        return $this->sourceRoots('Models')
            ->flatMap(fn (array $root) => $this->classesUnder($root[0], $root[1]))
            ->filter(fn (string $class) => is_subclass_of($class, Model::class))
            ->reject(fn (string $class) => (new ReflectionClass($class))->isAbstract())
            ->values()
            ->all();
    }

    /**
     * @return array<int, class-string>
     */
    private function discoverFilamentClasses(): array
    {
        return collect(['Resources', 'Pages', 'Widgets'])
            ->flatMap(fn (string $dir) => $this->sourceRoots("Filament/{$dir}"))
            ->flatMap(fn (array $root) => $this->classesUnder($root[0], $root[1]))
            ->filter(function (string $class) {
                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract()) {
                    return false;
                }

                return $reflection->isSubclassOf(\Filament\Resources\Resource::class)
                    || $reflection->isSubclassOf(Page::class)
                    || $reflection->isSubclassOf(Widget::class);
            })
            // Resource sub-pages (ListAccounts, EditAccount, …) are reached only
            // through their resource, which is already gated.
            ->reject(fn (string $class) => Str::contains($class, '\Resources\\') && Str::contains($class, '\Pages\\'))
            ->values()
            ->all();
    }

    /**
     * Every permission name the application checks at runtime, mapped to the
     * places it is checked.
     *
     * Line-based on purpose: all the call sites are single-line, and reporting
     * file:line is what makes a failure fixable without a search. A check built
     * from a variable has no literal to find, so it is simply not covered.
     *
     * @return array<string, array<int, string>>
     */
    private function discoverCheckedPermissions(): array
    {
        $found = [];

        foreach ($this->permissionCheckFiles() as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $index => $line) {
                foreach (self::PERMISSION_CHECK_PATTERNS as $pattern) {
                    if (! preg_match_all($pattern, $line, $calls)) {
                        continue;
                    }

                    foreach ($calls[1] as $arguments) {
                        preg_match_all("/'([A-Za-z]+)'/", $arguments, $names);

                        foreach ($names[1] as $name) {
                            $found[$name][] = Str::after($file, base_path().DIRECTORY_SEPARATOR).':'.($index + 1);
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @return Collection<int, string>
     */
    private function permissionCheckFiles(): Collection
    {
        return collect(File::allFiles(app_path()))
            ->merge(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $file->getPathname())
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function modulesOwning(string $class): array
    {
        return array_values(array_filter(
            Modules::names(),
            fn (string $module) => in_array($class, [
                ...ModuleMap::resources($module),
                ...ModuleMap::pages($module),
                ...ModuleMap::widgets($module),
            ], true),
        ));
    }

    private function hasResource(string $model): bool
    {
        foreach (ModuleMap::resources() as $resource) {
            if ($resource::getModel() === $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every place a given kind of class can live: the app-level directory and the
     * same subdirectory inside each module.
     *
     * This has to enumerate app/Modules/* or these tests quietly stop testing
     * anything. Once every class had moved, scanning only app/Models and
     * app/Filament left both directories empty — the suite stayed green while the
     * invariants covered nothing at all, which is a worse failure than any of the
     * bugs they exist to catch.
     *
     * @return Collection<int, array{0: string, 1: string}>
     */
    private function sourceRoots(string $subdirectory): Collection
    {
        $namespaceSuffix = str_replace('/', '\\', $subdirectory);

        $roots = collect([[app_path($subdirectory), 'App\\'.$namespaceSuffix]]);

        foreach (File::directories(app_path('Modules')) as $moduleDir) {
            $module = basename($moduleDir);

            $roots->push([
                $moduleDir.'/'.$subdirectory,
                'App\\Modules\\'.$module.'\\'.$namespaceSuffix,
            ]);
        }

        return $roots;
    }

    public function test_the_class_discovery_actually_finds_classes(): void
    {
        // Guards the guard. Every assertion in this file is driven by the two
        // discovery helpers, so an empty scan would turn the whole suite into a
        // no-op that still reports success.
        $this->assertGreaterThan(30, count($this->discoverModels()));
        $this->assertGreaterThan(40, count($this->discoverFilamentClasses()));
        $this->assertGreaterThan(80, count($this->discoverCheckedPermissions()));
        $this->assertNotEmpty(File::directories(app_path('Modules')));
    }

    /**
     * @return Collection<int, class-string>
     */
    private function classesUnder(string $path, string $namespace): Collection
    {
        if (! File::isDirectory($path)) {
            return collect();
        }

        return collect(File::allFiles($path))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(function ($file) use ($path, $namespace) {
                $relative = Str::of($file->getPathname())
                    ->after($path.DIRECTORY_SEPARATOR)
                    ->replace(DIRECTORY_SEPARATOR, '\\')
                    ->beforeLast('.php');

                return $namespace.'\\'.$relative;
            })
            ->filter(fn (string $class) => class_exists($class))
            ->values();
    }
}
