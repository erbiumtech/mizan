<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Per-company module state: is this module available to this company right now?
 *
 * Available means `licensed && enabled` — a super admin has granted it and the
 * company has it switched on. Callers ask that one question; nothing outside the
 * two admin surfaces should care which of the two flags is false.
 *
 * Resolved from the landlord database (see CompanyModule), which is what makes
 * this usable from commands and queued jobs: pass a company id and no current
 * tenant is required. That was the deciding reason for the landlord table over
 * TenantSettings, whose reads depend on Company::current().
 *
 * Registered as a singleton; the per-company map is loaded once per request, so
 * the many canAccess() calls behind navigation, global search and the command
 * palette cost one query.
 */
class Modules
{
    /** @var array<int|string, array<string, array{licensed: bool, enabled: bool}>> */
    protected array $cache = [];

    /**
     * Is the module available to the current company?
     *
     * With no current company this returns true, deliberately. Nothing outside a
     * tenant reaches tenant data — the tenant connection is not even switched —
     * so there is no licence to check and no data to protect: landlord surfaces
     * (the company list, the licensing section) are all Core, commands resolve
     * their company explicitly via enabledFor(), and every request inside the
     * panel carries a company in the route. Failing closed here would instead
     * break the landlord panel and the single-database test setup while
     * protecting nothing.
     */
    public function enabled(string $module): bool
    {
        return $this->availableTo($this->currentUser(), $module);
    }

    /**
     * Is the module available, judged from a request's own signals?
     *
     * Prefers the company being served (panel tenant, then spatie's current
     * tenant). Falls back to the user's membership, which is the only signal an
     * API request has: the API does no tenant resolution at all —
     * config('multitenancy.tenant_finder') is null — so without this fallback
     * every API check would fail open.
     *
     * A user in several companies makes the request unattributable to one, so
     * the module counts as available if any of their companies has it rather
     * than guessing. A caller with no company is not gated.
     */
    public function availableTo(?Authenticatable $user, string $module): bool
    {
        if (static::isLocked($module)) {
            return true;
        }

        $companyIds = $this->companyIdsFor($user);

        if ($companyIds === []) {
            return true;
        }

        foreach ($companyIds as $companyId) {
            if ($this->enabledFor($companyId, $module)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate companies whose licence could apply, most authoritative first.
     * An empty list means "nothing to check".
     *
     * @return array<int, int|string>
     */
    public function companyIdsFor(?Authenticatable $user = null): array
    {
        if (($companyId = $this->currentCompanyId()) !== null) {
            return [$companyId];
        }

        if (! $user instanceof User) {
            return [];
        }

        try {
            return $user->companies()->pluck('companies.id')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The authenticated user, without forcing the auth stack to resolve where
     * there is none (console, boot, queued work).
     */
    protected function currentUser(): ?Authenticatable
    {
        try {
            return auth()->user();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Is the module available to a specific company? Use this from commands,
     * queued jobs and anywhere else without a current tenant.
     */
    public function enabledFor(int|string|null $companyId, string $module): bool
    {
        if ($this->isLocked($module)) {
            return true;
        }

        if ($companyId === null) {
            return true;
        }

        $state = $this->stateFor($companyId)[$module] ?? null;

        if ($state === null) {
            return false;
        }

        return $state['licensed'] && $state['enabled'];
    }

    public function licensed(string $module): bool
    {
        return $this->licensedFor($this->companyIdsFor($this->currentUser())[0] ?? null, $module);
    }

    /**
     * The company whose licence applies to this request.
     *
     * Filament's tenant comes first, and it is the one that matters inside the
     * panel: it is resolved from the {tenant} route segment, so it is exactly the
     * company being served. spatie's "current tenant" is the fallback for
     * everything outside the panel (report routes, the status page, the API).
     *
     * They are not interchangeable. SyncSpatieTenant only calls makeCurrent()
     * when a dedicated tenant connection is configured, so in a single-database
     * setup — the whole test suite — Company::current() stays null while
     * Filament's tenant is set. Reading only the latter would have made every
     * gate fail open there, which is how this was first written and why
     * ModuleGatingTest exists.
     */
    protected function currentCompanyId(): int|string|null
    {
        try {
            if (Filament::getCurrentPanel() !== null) {
                $tenant = Filament::getTenant();

                if ($tenant instanceof Company) {
                    return $tenant->getKey();
                }
            }
        } catch (Throwable) {
            // No panel context (console, queue, non-panel route) — fall through.
        }

        return Company::current()?->getKey();
    }

    public function licensedFor(int|string|null $companyId, string $module): bool
    {
        if ($this->isLocked($module)) {
            return true;
        }

        if ($companyId === null) {
            return true;
        }

        return $this->stateFor($companyId)[$module]['licensed'] ?? false;
    }

    /**
     * Every module's state for a company, config defaults filled in for modules
     * with no row yet — so a module shipped in a later release appears with the
     * default it shipped with instead of being absent for existing companies.
     *
     * @return array<string, array{licensed: bool, enabled: bool}>
     */
    public function stateFor(int|string|null $companyId): array
    {
        $key = $companyId ?? 'none';

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->load($companyId);
        }

        return $this->cache[$key];
    }

    /**
     * Requirements of the given module that are *not* available to the company —
     * i.e. the reasons it may not be switched on. Empty means it may.
     *
     * @return array<int, string>
     */
    public function missingRequirements(int|string|null $companyId, string $module): array
    {
        return array_values(array_filter(
            static::requirements($module),
            fn (string $required) => ! $this->enabledFor($companyId, $required),
        ));
    }

    /**
     * Modules that depend on the given one, directly or transitively — what
     * breaks if it is switched off.
     *
     * @return array<int, string>
     */
    public static function dependents(string $module): array
    {
        $dependents = [];

        foreach (array_keys(static::registry()) as $candidate) {
            if (in_array($module, static::requirements($candidate), true)) {
                $dependents[] = $candidate;
                $dependents = array_merge($dependents, static::dependents($candidate));
            }
        }

        return array_values(array_unique($dependents));
    }

    /** @return array<int, string> */
    public static function requirements(string $module): array
    {
        return static::registry()[$module]['requires'] ?? [];
    }

    public static function isLocked(string $module): bool
    {
        return (bool) (static::registry()[$module]['locked'] ?? false);
    }

    public static function label(string $module): string
    {
        return static::registry()[$module]['label'] ?? $module;
    }

    /** @return array<string, array<string, mixed>> */
    public static function registry(): array
    {
        return config('modules', []);
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(static::registry());
    }

    /**
     * Modules a company can be shown on its own activation page: licensed, and
     * not Core (which has no toggle at all — see config/modules.php).
     *
     * @return array<int, string>
     */
    public function activatable(int|string|null $companyId): array
    {
        return array_values(array_filter(
            static::names(),
            fn (string $module) => ! static::isLocked($module) && $this->licensedFor($companyId, $module),
        ));
    }

    /**
     * Write the shipped defaults for a brand-new company — Core alone, since
     * `licensed_by_default` is true only for Core. A super admin grants the rest.
     *
     * Existing companies are not touched by this: they were backfilled to
     * all-on by the create_company_modules_table migration.
     */
    public function seedDefaults(int|string $companyId): void
    {
        foreach (static::registry() as $module => $definition) {
            $default = (bool) ($definition['licensed_by_default'] ?? false);

            CompanyModule::updateOrCreate(
                ['company_id' => $companyId, 'module' => $module],
                // enabled stays NULL for anything not licensed by default: the
                // company has not chosen yet, so the first grant switches it on.
                ['licensed' => $default, 'enabled' => $default ?: null],
            );
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<string, array{licensed: bool, enabled: bool}>
     */
    protected function load(int|string|null $companyId): array
    {
        $rows = collect();

        if ($companyId !== null) {
            try {
                $rows = CompanyModule::query()
                    ->where('company_id', $companyId)
                    ->get()
                    ->keyBy('module');
            } catch (Throwable) {
                // Table not yet migrated (a landlord mid-upgrade, or a test that
                // does not build the schema). Defaults below then apply, which
                // for a locked module is "on" and for everything else follows
                // config — the same answer as before this feature existed.
                $rows = collect();
            }
        }

        $state = [];

        foreach (static::registry() as $module => $definition) {
            $row = $rows[$module] ?? null;
            $default = (bool) ($definition['licensed_by_default'] ?? false);
            $licensed = $row?->licensed ?? $default;

            $state[$module] = [
                'licensed' => $licensed,
                // A licensed module the company has never made a choice about
                // reads as on: a grant is meant to light the module up, and only
                // an explicit false means they switched it off themselves.
                'enabled' => $row?->enabled ?? ($licensed ? true : $default),
            ];
        }

        return $state;
    }
}
