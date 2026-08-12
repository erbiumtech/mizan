# Adding a module: the checklist

> Extracted from `docs/modules-plan.md` §13 (As built) and from the code as it
> stands, so that the HRMS and CRMS plans can specify *what* to build without
> restating *how* a module is wired every time. Every item below is a thing that,
> left out, fails silently or fails at 00:00 in a queue worker rather than in CI.

A module is a commercial boundary here, not a folder. Ten of the twelve steps
exist because something in this application stores a class name in a database
column, or because Filament resolves things at boot while the tenant is resolved
per request.

## 1. Registry entry — `config/modules.php`

```php
'leave' => [
    'label' => 'Leave',
    'description' => 'Leave types, entitlements, requests and balances.',
    'requires' => ['employees'],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Leave\LeavePlugin::class,
],
```

Not optional and not cosmetic: `ModuleMap::moduleFor()` derives a module from the
`App\Modules\{Name}\` namespace and then checks the derived key exists in
`config('modules')`. Without an entry it returns `null`, and what happens next
depends on the surface — worth knowing precisely, because two of the three are
silent:

| Surface | With `moduleFor()` returning null |
|---|---|
| A resource or page using `BelongsToModule` | `module()` throws `RuntimeException` — loud, and the intended behaviour: "better a hard failure than a class that quietly answers available for every company" |
| A class with its own `canAccess()` | **silently ungated** — see §11 |
| `Gate::before` / `ModuleAuthorization` | **silently ungated**: an unmapped class contributes no candidate module, so `blockingModule()` returns null and authorization proceeds as if the module were licensed |

`ModuleCoverageTest` is what catches all three in CI, not the type checker.

Directory `App\Modules\PersonalFinance` → key `personal_finance` (`Str::snake`),
so multi-word module names work as long as the key matches.

`requires` is a *licence* dependency, not an import graph (§13 of the modules
plan is explicit about the difference). Declare it only when the module is
genuinely unsellable without the other one; otherwise guard the call site and
record the coupling in `ModuleBoundaryTest::KNOWN_COUPLINGS`.

## 2. Plugin — `app/Modules/{Name}/{Name}Plugin.php`

Registers resources, pages and widgets by discovery. **Unconditional** — one
deployment serves every company, plugins register at boot, the company is
resolved per request. Copy `ProjectsPlugin`; the reasoning is in `MprPlugin`.

## 3. Service provider — and `bootstrap/providers.php`

Carries what Filament does not: policies, routes, commands, config merge. Add it
to `bootstrap/providers.php` or none of that runs.

**Register every policy explicitly.** Laravel's auto-discovery guesses
`App\Models\X` → `App\Policies\XPolicy`; models under `App\Modules\…\Models`
never resolve, and an unresolved policy means an open resource. This has
happened in this codebase before (`AppServiceProvider.php:55`) and
`ModuleCoverageTest` asserts every model has one.

## 4. `ModuleMap` — four tables plus permission groups

`app/Support/ModuleMap.php`, one entry per class in `MODELS`, `RESOURCES`,
`PAGES`, `WIDGETS`, and the module's permission group names in
`PERMISSION_GROUPS`. A resource with no entry fails `ModuleCoverageTest`.

**Morph aliases for new models: use short keys.** The existing keys are legacy
`App\Models\…` strings because that is what is already stored in customer data.
A model that has never shipped has no legacy rows to preserve, so inventing
`App\Models\LeaveRequest` would be a lie that the eventual normalisation pass has
to migrate anyway. Write `'leave_request' => LeaveRequest::class` and update the
`ModuleMap` docblock to say the map holds two conventions and why.

`Relation::enforceMorphMap(ModuleMap::morphMap())` runs at
`app/Providers/AppServiceProvider.php:123`. *Enforce*, not `morphMap`: a model
missing from the map throws rather than writing an FQCN into a column. That is
the safety net — do not remove it to make a test pass.

## 5. Anywhere a class name is written to a column: `ModuleMap::alias()`

`journal_entries.source_type`, `stock_movements.source_type`,
`payments.payable_type`, `custom_fields.model_type`, `table_views.resource` are
plain column writes that `enforceMorphMap()` does not cover. Normalise in a
mutator, read through `whereMorphedTo()` or the model's own `forSource()`-style
scope. New polymorphic columns follow the same rule.

## 6. Permissions — and the two traps

Add rows to `database/seeders/PermissionSeeder.php` (`name` + `group`), grant
them in `RoleSeeder.php` to the roles that should hold them, and list the group
in `ModuleMap::PERMISSION_GROUPS`.

**Trap 1 — the `permissions` table has no unique index.** It is
`id, name, group, guard_name, timestamps` (`create_permission_tables.php`); the
unique keys in that migration are on `roles` and the pivots. `PermissionSeeder`
calls `firstOrCreate(['name' => …, 'group' => …])` — matching on *both* columns.
So **changing an existing permission's group creates a second row with the same
name** instead of throwing. Roles keep pointing at the old row while new grants
attach to the new one, and nothing reports it. Renaming a group is a data
migration, not a seeder edit.

**Trap 2 — `sync()` and hidden groups.** `SyncsGroupedPermissions` collects ids
from the groups `RoleForm::groupedPermissions()` returns and syncs. A module
whose permissions are filtered out of that list is missing from the sync array,
so saving any role detaches them. Covered by `ModulePermissionFilteringTest` —
keep it passing.

Finish the seeder run with `PermissionCache::flushEverywhere()`. A permission
added but not visible in a company is not a stale menu: policies call
`hasPermissionTo()`, which throws for an unknown name, and the panel 500s.

## 7. Tenant migrations

New tables go in `database/migrations/tenant/`, applied with
`php artisan tenants:migrate`. Never `tenants:artisan migrate` — with no
`--path` it migrates the landlord folder and reports "Nothing to migrate".

Licensing decides what is *offered*, never what is *migrated*: every tenant gets
every table, and an unlicensed module's tables simply stay empty
(`ModuleBoundaryTest`'s note on `invoicing -> projects` says this outright).

## 8. Scheduled work — `app/Modules/{Name}/routes/console.php`

Loaded by the module's provider. Guard on the licence inside the command
(`TenantAware`, skipping companies with the module off), and **name model classes
as class constants, never as strings** — copy the `model:prune` entry in
`app/Modules/Projects/routes/console.php`, whose comment records why.

## 9. Routes

Web and API routes in the module's `routes/` directory, each with the
`module:{name}` middleware. Direct URLs bypass `canAccess()`.

## 10. Model conventions

Extend `App\Models\TenantModel`. Add `Auditable` for the activity trail and
`HasCustomFields` where a company will want fields we did not think of.
Polymorphic `comments` already work on any model — reuse them rather than adding
a per-module notes table.

Balances and totals are **computed, not stored**, unless there is a stated reason
otherwise (the chart of accounts is the precedent). A stored balance drifts and
nothing tells you when.

## 11. Filament surfaces

Resources and pages get module gating from the `BelongsToModule` trait, which
reads the module from the namespace; widgets need `canView()`. Navigation, global
search and the ⌘K palette all follow `canAccess()`, so one method closes four
surfaces.

**The trap: a class defining its own `canAccess()` silently shadows the trait's,
and most pages here do.** Adding the trait is not the same as being gated. Such a
page must call `moduleIsAvailable()` itself:

```php
public static function canAccess(): bool
{
    return static::moduleIsAvailable() && auth()->user()?->can('ReportView');
}
```

This is why `ModuleGatingTest` asserts the *behaviour* of every resource, page and
widget rather than the presence of the trait. A new page that forgets the call is
reachable by anyone whose company never bought the module, and nothing else in the
stack will say so — `canAccess()` returning true is the answer navigation, search
and the palette all trust.

Scope every employee-facing query through `App\Support\EmployeeAccess` so a
manager sees their downline and no further.

## 12. Tests that must be updated, not just added

| Test | Why it fails on a new module |
|---|---|
| `ModuleCoverageTest` | every model/resource/page/widget must map to exactly one module and have a policy |
| `ModuleBoundaryTest` | a cross-module `use` not in `requires` or `KNOWN_COUPLINGS` fails, **in both directions** — stale entries fail too |
| `ModuleGatingTest` / `ModuleEnforcementTest` | enabled → reachable, disabled → 403/404, including for a super admin |
| `ModuleStateTest` | licensed AND enabled; `NULL` enabled means "never chosen" |
| `ModuleDegradationTest` | with a soft dependency off, the write still succeeds and skips the optional part |
| `ModulePermissionFilteringTest` | trap 2 above |

The suite runs one in-memory SQLite database with tenant migrations auto-loaded,
so **anything that needs a real per-tenant connection cannot be covered** —
per-company command fan-out included. Those paths are verified by hand.

## The five-minute version

```
config/modules.php              registry entry
{Name}Plugin.php                discover resources/pages/widgets
{Name}ServiceProvider.php       policies (explicit!), routes, commands
bootstrap/providers.php         list the provider
ModuleMap.php                   models + resources + pages + widgets + permission groups
PermissionSeeder / RoleSeeder   permissions, then flushEverywhere()
database/migrations/tenant/     tables
routes/console.php              schedules, class constants, licence guard
tests                           update the eight Module* tests
```
