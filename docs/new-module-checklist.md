# Adding a module: the checklist

> Extracted from `docs/modules-plan.md` §13 (As built) and from the code as it
> stands, so that the HRMS and CRMS plans can specify *what* to build without
> restating *how* a module is wired every time. Every item below is a thing that,
> left out, fails silently or fails at 00:00 in a queue worker rather than in CI.

**A module is a directory and a file.** `app/Modules/{Name}/` plus its
`module.php` manifest is the whole of what a module declares about itself —
registry entry, the classes it owns, its permissions, who gets them, and where
its screens appear in the shell. `App\Support\ModuleManifest` finds every
manifest, merges them and caches the result.

That was not always true, and this document said otherwise until 2026-08-17.
`docs/module-packaging-plan.md` §5 replaced **six** central files that every new
module had to edit — `config/modules.php`, the five hand-written tables in
`ModuleMap`, the literal rows in `PermissionSeeder`, the grants in `RoleSeeder`,
and the claims in `NavigationDomains`. All six are gone. If you are following an
older copy of this list and it tells you to edit one of them, the file no longer
holds what it describes.

The rest of the steps exist for two reasons that have not changed: something in
this application stores a class name in a database column, or Filament resolves
things at boot while the tenant is resolved per request.

Two of them are the commercial boundary itself: the manifest (§1) says the module
exists and who inside a company may use it, and the profile entry (§1a) says
which kind of company is ever sold it. A module missing either is built,
deployed, and reachable by nobody.

## 1. The manifest — `app/Modules/{Name}/module.php`

One file, returning one array. This is the registry entry, the class tables, the
permissions, the role grants and the navigation claims in a single place:

```php
return [
    'key' => 'leave',                       // must match the directory: Str::snake(basename())
    'label' => 'Leave',
    'description' => 'Leave types, entitlements, requests and balances.',
    'requires' => ['employees'],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Leave\LeavePlugin::class,

    'models' => [ /* §4 */ ],
    'resources' => [ /* §4 */ ],
    'pages' => [ /* §4 */ ],
    'widgets' => [ /* §4 */ ],

    'permission_groups' => ['LeaveRequest', 'LeaveType', 'LeaveEntitlement'],
    'permissions' => [ /* §6 */ ],
    'role_grants' => [ /* §6a */ ],

    'navigation' => ['Employee' => 'people'],   // §6b
    'option_lists' => [ /* §6c, if any */ ],
];
```

**A PHP file rather than JSON, deliberately.** The entries in the old
`config/modules.php` carried paragraphs of load-bearing reasoning — why CRM
requires nothing, why Leave omits Payroll — and those paragraphs are the most
valuable content in the file. JSON cannot hold a comment. Keep writing the
reasoning next to what it explains.

`key` must equal the key derived from the directory name, and `ModuleManifest`
throws if it does not: the directory is what `ModuleMap::moduleFor()` reads from
a namespace, so the two disagreeing would make the module unfindable from its own
classes. Directory `App\Modules\PersonalFinance` → key `personal_finance`.

Without a manifest entry `moduleFor()` returns `null`, and what happens next
depends on the surface — worth knowing precisely, because two of the three are
silent:

| Surface | With `moduleFor()` returning null |
|---|---|
| A resource or page using `BelongsToModule` | `module()` throws `RuntimeException` — loud, and the intended behaviour: "better a hard failure than a class that quietly answers available for every company" |
| A class with its own `canAccess()` | **silently ungated** — see §11 |
| `Gate::before` / `ModuleAuthorization` | **silently ungated**: an unmapped class contributes no candidate module, so `blockingModule()` returns null and authorization proceeds as if the module were licensed |

`ModuleCoverageTest` is what catches all three in CI, not the type checker.

`requires` is a *licence* dependency, not an import graph (§13 of the modules
plan is explicit about the difference). Declare it only when the module is
genuinely unsellable without the other one; otherwise guard the call site and
record the coupling in `ModuleBoundaryTest::KNOWN_COUPLINGS`.

**The import graph is acyclic and must stay that way.**
`ModuleBoundaryTest::TANGLED_MODULE_BUDGET` is 0, and at zero it is an invariant
rather than a budget — do not raise it to make a change pass. If your module
needs something from a module that requires *it*, invert the reference: a
contract in `App\Support\Contracts` with a null default bound in
`ContractDefaultsServiceProvider`, or a registry in `App\Support`. Pass the
primitives the far side reads, never a model — a contract that passes a model
passes the module that owns it. §11 of the packaging plan has the worked
examples.

## 1a. Company profiles — `config/company_profiles.php`

A registry entry says the module *exists*. A profile entry says which kind of
company is ever *sold* it. Without one the module is licensed to nobody: it is
absent from every profile preset, so no company provisioned from a profile ever
gets it, and the only route in is a super admin toggling it by hand for one
company at a time.

**This fails CI, which is the point.**
`CompanyProfileTest::test_every_module_is_recommended_by_at_least_one_profile`
asserts every non-locked module appears in at least one profile. It exists
because the alternative — a `profiles` key on each module entry — makes adding a
*profile* an edit to every module, and this way round is the cheaper mistake to
catch.

Add the module to the profiles whose businesses actually use it:

```php
'services' => [
    ...
    'modules' => [..., 'leave', 'timesheets'],
],
'manufacturing' => [
    ...
    'modules' => [..., 'leave', 'attendance'],
],
```

Two rules the same test enforces, both of which fail silently in production:

- **Closed under `requires`.** `Modules::enabledFor()` recurses into
  requirements, so a profile licensing `leave` without `employees` produces a
  module that is licensed, shows a toggle on the company's own Modules page, and
  can never be switched on. It reads as a broken toggle, not a bad preset.
- **Seeders agree with modules.** A profile's `seeders` list is checked against
  what it licenses — `SalarySlabSeeder` iff `payroll`, and the chart of accounts
  must match the profile's company type. A new module that ships reference data
  adds its seeder to the profiles that license it and to no others.

`docs/company-profiles-plan.md` has the reasoning; §5 there is why a profile is a
preset and never a restriction, so this is about what gets *sold*, never about
what a company is *allowed*.

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

## 4. The class tables — in the manifest, not in `ModuleMap`

`models`, `resources`, `pages`, `widgets` in `module.php`, one entry per class. A
resource with no entry fails `ModuleCoverageTest`.

`ModuleMap`'s five hand-written `const` tables are **gone** — it now reads the
merged manifest, so `ModuleMap::resources()`, `::models()` and the rest still work
and nothing central needs editing. `ModuleManifest` refuses to merge two modules
claiming the same alias, because a duplicate morph alias means rows of one module
resolving to the model of another: a same-shaped table holding the wrong data,
which no error ever reports.

**Morph aliases: `App\Models\{ClassBasename}`, including for models that have
never shipped.**

> **Corrected.** This section previously said to use short keys
> (`'leave_request' => LeaveRequest::class`) for new models, on the reasoning
> that a model with no legacy rows has no legacy string to preserve. That
> reasoning is sound and the instruction was still wrong: **following it fails
> CI on the first run.** `ModuleCoverageTest::test_morph_map_aliases_are_the_legacy_class_names`
> asserts the form unconditionally, for every entry in the map, with no exemption
> for new classes. All 64 model aliases use the legacy form and none is short.
> This was found by hitting it — adding `FbrSubmission` to Invoicing.

So write `'App\Models\LeaveRequest' => \App\Modules\Leave\Models\LeaveRequest::class`
even though `App\Models\LeaveRequest` never existed.

Keeping the uniform rule is the right resolution rather than teaching the test
about new models, for two reasons. The alias is an opaque storage token — its
dishonesty costs nothing at runtime, and nobody reads it except the map. And a
test with an exemption list is a test that gets edited to pass; an unconditional
one cannot be. If the aliases are ever normalised to short keys, that is the
whole map at once as its own migration — `docs/modules-plan.md` §11 phase 6
already scopes it that way — not one convention leaking in per module.

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

In the manifest: `permissions` (`name` + `group`) and `permission_groups`.
`PermissionSeeder` discovers them — its 245 literal rows are gone, and it throws
if discovery finds fewer than 100, because a seeder that silently does nothing
would surface as every policy check throwing.

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

## 6a. Role grants — `role_grants` in the manifest

Who starts with the permissions you just declared:

```php
'role_grants' => [
    'Employee' => ['LeaveRequestView', 'LeaveRequestCreate', 'LeaveRequestUpdate'],
    'Accountant' => [...],
    'Manager' => [...],   // additions only — see below
    'CEO' => [...],       // additions only
],
```

Three rules, all enforced:

- **Administrator is not listed.** It holds every permission that exists, so it
  gains yours the moment they are seeded.
- **`Manager` and `CEO` are additions, not complete lists.** `RoleSeeder`
  composes `Accountant → Manager → CEO`, so a permission already granted to
  Accountant must not be repeated. A module cannot express "Manager gets
  everything Accountant has", which is exactly why the chain stays central.
- **A module may only grant what it declares.** `RoleSeeder` throws and names the
  module otherwise. Granting another module's permission would put back the
  cross-module knowledge that moving these lists out removed.

Omit the key entirely if the module is Administrator-only — seven of them are.
`RoleGrantsTest` asserts each role's size, the superset chain, and that the
Accountant still cannot approve, post or reverse.

Leaving a role *out* is a decision nothing can check for you. CRM is deliberately
absent from `Employee` — a machine operator has no leads, and granting `LeadView`
there would put every prospect in front of every employee. An absence cannot be
declared by the module that would have been granted, so if yours is a deliberate
omission, say so in `RoleSeeder`'s docblock where the others are.

## 6b. Navigation — `navigation` in the manifest

Which of the shell's six domains your screens appear in, keyed on the navigation
group label your resources and pages declare:

```php
'navigation' => ['Employee' => 'people'],
```

The six domains are `App\Support\NavigationDomains` — `home`, `reports`,
`finance`, `people`, `sales`, `admin` — and they stay central because a domain is
shell design, not a module's to invent.

**Group labels are shared.** "Employee" is claimed by ten modules; agreement is
the normal case and merges silently. Two modules claiming one label for
*different* domains throws at manifest-build time, naming both — without that it
would resolve to whichever manifest was read last and move a whole group of
screens silently. A claim naming a domain that does not exist throws too.

For a page that registers no navigation group at all, use `navigation_items`
keyed by class: Filament collects every ungrouped page into one unlabelled group,
so those cannot be placed by label.

**Left out, the screens are reachable only by URL.** Filtering navigation makes
anything unmapped invisible — nothing throws and no page 404s, the entries are
simply not drawn. `NavigationDomainsTest` asserts coverage in both directions and
`ManifestNavigationGuardTest` asserts the two throws.

## 6c. Dropdown options — `option_lists` in the manifest

Any dropdown whose contents are *this company's words* rather than the
application's — designations, plant categories, NCR categories. Declared with the
values it ships with; `App\Support\OptionLists` merges the declarations,
`OptionListSeeder` writes them into each tenant, and the Dropdown Options screen
(Settings → Company) lets an administrator add and retire entries:

```php
'option_lists' => [
    'employees.designation' => [
        'label' => 'Designations',
        'help' => 'Job titles. Shown on the employee record and on a vacancy.',
        'values' => ['Backend Developer', 'Cook'],   // a list: the value is the label
    ],
    'employees.employment_type' => [
        'values' => ['permanent' => 'Permanent'],    // a map: the column stores the key
    ],
],
```

Read it in a form with `options('employees.designation', $record?->designation)`.
**Always pass the record's own value.** A list is editable, so an entry can be
retired after rows were saved with it, and a Select whose current value is not
among its options renders blank and writes that blank back on the next save.

Declared by the module so the screen can hide the lists of a module the company
has not licensed, and so a duplicate list key across two modules fails at
manifest-build time like any other duplicate alias.

**Most dropdowns do NOT belong here, and this is the decision to get right.**
Workflow vocabulary is code: services branch on the value, reports key off it,
and the column is usually `enum(...)` in the migration — so an entry an
administrator adds would be ignored by the code and rejected by the database. A
list qualifies only when all three hold: the column is a plain `string`, nothing
in `app/` compares the value to a constant, and two companies would genuinely
write different lists. Reference data with its own screen (lead sources, leave
types, ticket categories) stays a table of its own; this is for the lists that
were literal arrays inside a form.

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
| `ModuleBoundaryTest` | a cross-module `use` **or inline `\App\Modules\…::class`** not in `requires` or `KNOWN_COUPLINGS` fails, in both directions — stale entries fail too. Also: `TANGLED_MODULE_BUDGET` is 0 and any new cycle fails it |
| `ModuleGatingTest` / `ModuleEnforcementTest` | enabled → reachable, disabled → 403/404, including for a super admin |
| `ModuleStateTest` | licensed AND enabled; `NULL` enabled means "never chosen" |
| `ModuleDegradationTest` | with a soft dependency off, the write still succeeds and skips the optional part |
| `ModulePermissionFilteringTest` | trap 2 above |
| `CompanyProfileTest` | the module must appear in at least one profile, every profile stays closed under `requires`, and its seeders must agree with what it licenses — §1a |
| `RoleGrantsTest` | each role's size is asserted, so a grant that widens one fails until the count is updated deliberately — §6a |
| `NavigationDomainsTest` | every declared group belongs to exactly one domain and every claimed label is one the panel declares, **in both directions** — §6b |
| `ManifestNavigationGuardTest` | a label claimed for two domains, or a claim naming a domain that does not exist, throws at manifest-build time |

The suite runs one in-memory SQLite database with tenant migrations auto-loaded,
so **anything that needs a real per-tenant connection cannot be covered** —
per-company command fan-out included. Those paths are verified by hand.

## The five-minute version

Two files are yours, three are central, and the rest is code in your own
directory:

```
app/Modules/{Name}/module.php    EVERYTHING the module declares about itself:
                                   key/label/description/requires/plugin
                                   models + resources + pages + widgets
                                   permission_groups + permissions
                                   role_grants
                                   navigation (+ navigation_items)
                                   option_lists (the company's own dropdowns)
config/company_profiles.php      which kinds of company are sold it
bootstrap/providers.php          list the provider

app/Modules/{Name}/
  {Name}Plugin.php               discover resources/pages/widgets
  {Name}ServiceProvider.php      policies (explicit!), routes, commands
  routes/console.php             schedules, class constants, licence guard
database/migrations/tenant/      tables

then: php artisan db:seed --class=PermissionSeeder && ... RoleSeeder
      PermissionCache::flushEverywhere()
tests                            update the Module* tests
```

If you find yourself editing `config/modules.php`, `ModuleMap`'s const tables,
`PermissionSeeder`'s rows, `RoleSeeder`'s grants or `NavigationDomains`' claims —
stop. None of those hold that any more; the manifest does.
