# Module packaging: breaking the cycles — Plan

**Status:** **All phases 0–9 built (2026-08-17).** Core names no module at all, and the trapped-module count
went 13 → 9 the moment it stopped: **core, inventory, invoicing and projects all left the tangle together**,
which is what "Core is the hub" predicted. What remains is the pay-and-people knot — `[7] accounting,
advances, attendance, employees, expenses, leave, payroll` and `[2] billing, timesheets` — which no section
of this plan addresses and which needs a plan of its own. See "Phase 9 — Core stops receiving nothing".
**Created:** 2026-08-15
**Covers:** what "installable" was decided to mean (§1), the import graph and its cycles (§2–§3), the
safety rails that must land before anything moves (§4), one manifest file per module (§5), the contracts
and registries that break the knots (§6), the three big carves (§7–§9), and what is deliberately left
undone (§10)

Goal: make every module in this application *extractable* into its own composer package — installed by
the operator with `composer require` and discovered by Laravel — **without extracting a single one**.
The output is a codebase whose module graph is provably acyclic, where a module is one directory and one
declarative file rather than thirteen edits to nine shared files, and where three pieces of recorded
architectural debt have been paid. Extraction after that is mechanical, and whether it is worth doing is
a decision this plan deliberately defers.

`docs/modules-plan.md` §1 anticipated this step in one sentence — *"a module could later be extracted
into a package"* — and §13 closes by naming the obstacle: *"Breaking the debt needs interfaces, events
or a registry. Until then the graph cannot get worse without someone deciding to make it worse."* This
is that sequel.

Four findings shape everything below, and the second is the whole difficulty.

1. **The chosen install model sidesteps every mechanical blocker, and they are severe.** A true
   WordPress-style runtime install — drop a directory, click activate, no deploy — is impossible here for
   five independently fatal reasons. `filament:cache-components` makes every `discoverResources()`,
   `discoverPages()` and `discoverWidgets()` call **early-return** once
   `bootstrap/cache/filament/panels/admin.php` exists, so a newly dropped plugin registers nothing at
   all — and `hasCachedComponents()` is false in console, so artisan sees the module while web requests
   do not. `config:cache` and `route:cache` freeze `config/modules.php` and every resource route.
   `opcache.validate_timestamps=0` (`deploy/php/opcache.ini:31`) means new PHP files are not read until
   PHP-FPM reloads. `Relation::enforceMorphMap()` (`app/Providers/AppServiceProvider.php:160`) throws for
   any model outside the map. `bootstrap/providers.php` is a literal array. **Every one of those is
   already handled by `deploy/deploy.sh`**, which runs `composer install --optimize-autoloader`,
   `optimize:clear` → `optimize` → `icons:cache` → `filament:optimize`, then an FPM reload. Under a
   composer-package model the entire cache-and-opcache problem is not work; it is the existing deploy.
   Autoloading is not a blocker either — `App\` maps to `app/` and the optimised classmap is not
   authoritative. **So none of the obvious obstacles are the obstacle.**
2. **The import graph has 21 cycles, and composer forbids cycles.** 60 module→module edges plus 21
   `X → core` edges. One strongly-connected component of eight modules holds fifteen of them; six more
   run through Core; two are hidden inside `app/Support` where the lint cannot see them. §2 has the list.
3. **`ModuleBoundaryTest` is blind in two places, and both are load-bearing.** `allowedTargets()` seeds
   `$allowed = ['core']` unconditionally, so no `X → core` edge is ever recorded — which is precisely
   where six cycles live. And `importsIn()` matches `/^use\s+/m`, while `app/Support/ModuleMap.php`
   contains **one** `use` statement and **222 inline `\App\Modules\…::class` references** spanning all 22
   modules. The single largest edge in the codebase is invisible to the test built to find edges.
4. **Central registries are the module system's real cost.** `config/modules.php` (22 literal entries),
   `bootstrap/providers.php` (22 providers), `app/Support/ModuleMap.php` (**279** hand-written entries
   across five tables, of which **112** are model morph aliases), `PermissionSeeder` (245 rows in one
   array), `RoleSeeder` (literal permission lists per role), and the navigation pair
   `NavigationDomains` + `NavigationTree`. `docs/new-module-checklist.md` is thirteen steps long because
   of these six files, and ten of its steps exist only because something stores a class name centrally.

## What already exists and is reusable

Written down because the size of this plan depends on it — most of the enforcement already exists and is
good.

- **The debt is already inventoried, and the inventory is accurate in both directions.**
  `ModuleBoundaryTest::KNOWN_COUPLINGS` (`:86`) lists every cross-module import that no `requires`
  declares, and `test_the_recorded_debt_does_not_hide_a_licence_dependency` (`:237`) **fails on stale
  entries too**. That is the single most useful asset here: every edge this plan deletes must be removed
  from the list in the same commit, or the build fails. The ratchet is already built.
- **An import and a licence dependency are already understood to be different things.** The docblock at
  `ModuleBoundaryTest:51-82` says so outright, and `config/modules.php` is full of the reasoning — why
  `crm` requires nothing, why `leave` deliberately omits `payroll`, why `mpr`'s Employees dependency is
  presentational. **This distinction is what makes the whole plan tractable** (§3).
- **The morph map is already enforced rather than merely declared.** `Relation::enforceMorphMap()` throws
  for an unmapped model rather than writing an FQCN into a column, and the six plain class-string columns
  already normalise through `ModuleMap::alias()` in mutators. `docs/modules-plan.md` §4 records that
  enforcing the map *before* moving anything is what made the last structural change survive; this plan
  copies that discipline exactly (§4).
- **Laravel package discovery already works.** `composer.json` has `"laravel": {"dont-discover": []}`, so
  a module shipped as a composer package with `extra.laravel.providers` would auto-register its provider
  with no host edit. `bootstrap/providers.php` shrinking to four entries is a day-one consequence of
  extraction, not something to build.
- **`bootstrap/cache/` is already the right home for a generated registry.** It holds `packages.php` and
  `services.php`, is git-ignored, and is already writable on every deploy that runs `package:discover`.
- **The gating layer is already dynamic.** The `module:{name}` middleware, `ModuleAuthorization::
  blockingModule()` and `EnsureModuleEnabled` are generic and parameterised — they resolve modules from
  `ModuleMap` and the `permissions.group` column and need no per-module edit. **Only the *registration*
  layer is static.** That is a much smaller problem than it looks from the checklist.
- **`Modules` is already a per-company runtime concern**, reading `licensed && enabled` from the landlord
  `company_modules` table with three-state `enabled`. Nothing in this plan touches licensing semantics.
- **The eight `Module*Test` files already assert the invariants** this plan must not break —
  `ModuleDegradationTest` most of all, since every step here is "A still works with B changed".

## Not doing

- **Extracting any package.** Decided. No `composer require`, no private registry, no version bumps, no
  `vendor/` move. The plan stops at the point where extraction becomes mechanical, and §10 records what
  is known about the step after.
- **Runtime installation.** Finding 1 is why: five independent blockers, of which
  `opcache.validate_timestamps=0` alone ends it. A build that could hot-load a module would have to give
  up the opcache setting, the Filament component cache and route caching — which is to say, give up the
  performance work that makes the panel usable. The operator installs at deploy time and that is the
  right trade.
- **Third-party modules.** First-party only, so there is no sandbox to design and no signing pipeline to
  build. This is worth stating rather than leaving implicit: PHP cannot sandbox a module that shares an
  autoloader and a database connection, and any design that claims otherwise is claiming something it
  cannot enforce. If third-party modules are ever wanted, that is a different plan with a different
  security model, not a later phase of this one.
- **Splitting the 104 tenant migrations.** `docs/modules-plan.md` §2 decided against it and was right;
  many of those files create tables for several modules at once
  (`create_crm_pipeline_tables.php`, `create_lifecycle_tables.php`, the whole `2026_07_15_082837_*`
  batch), and splitting them retroactively means renaming, which means re-running them on every existing
  tenant. §10 records why this becomes the binding constraint at extraction time and how it is handled
  then — as a frozen archive, not a migration.
- **Changing the licensing model.** `company_modules`, three-state `enabled`, `requires` propagation and
  the per-company toggle are untouched. A package boundary and a licence boundary are different things,
  and one package may perfectly well contain more than one licensable module.
- **A dependency-injection rewrite.** The registries in §6 are lists that things push into and one thing
  reads. They are not a plugin API, not a hook system, and not an event bus. Two of the fixes are
  genuinely events; the rest are arrays.
- **Renaming namespaces.** `App\Modules\{Name}\` stays. The namespace rename belongs with extraction, and
  doing it now would break the three places that derive module ownership from a namespace regex for no
  benefit this plan collects.
- **A schema squash.** Dead tables for modules nobody licenses stay. That is already the rule —
  licensing decides what is offered, never what is migrated — and squashing is its own project.

## §1 What "installable" was decided to mean

Four forks, settled before design:

| Fork | Chosen | Consequence |
|---|---|---|
| Mechanism | One composer package per module, auto-discovered | The deploy already does the work (finding 1) |
| Installer | The platform operator, at deploy time | No tenant self-service, no upload UI |
| Trust | First-party only | No sandbox, no signing, no review pipeline |
| Repo layout | One repo per package | Only binds from extraction onward |

One note recorded rather than argued, because it will come up again at extraction time: a monorepo with
read-only subtree splits delivers every benefit of packaging except independent release cadence, and
costs none of the version-bump tax that polyrepo imposes on every cross-package change. Given
first-party code and one deployment serving every company, that is probably the correct end state rather
than a waypoint. Nothing in this plan depends on the answer, and §10 is where it becomes live.

## §2 The graph, and its twenty-one cycles

The real edge set, from scanning `use App\Modules\` across `app/Modules/*` — the same scan
`ModuleBoundaryTest::importsIn()` performs, plus the two blind spots it misses.

**One strongly-connected component of eight modules** — `accounting`, `employees`, `payroll`,
`invoicing`, `inventory`, `projects`, `attendance`, `leave` — containing fifteen elementary cycles. The
shortest are two hops (`accounting → payroll → accounting`, `accounting → invoicing → accounting`,
`attendance → leave → attendance`, `employees → projects → employees`); the longest is six
(`accounting → payroll → leave → attendance → employees → accounting`).

**Six two-cycles through Core**, and the test cannot see any of them. Every one of the 21 non-core
modules imports Core; `KNOWN_COUPLINGS['core']` records that Core imports six of them back. The good
news is that Core's *outward* surface is tiny — only five Core symbols are imported by anyone
(`User` by all 21, `FiscalYear` by 7, `Company` by 6, `HolidayCalendar` by 2, `Setting` by 1) — so Core
is already 95% kernel, and its six outbound edges live in **eight files**.

**Two hidden cycles inside `app/Support`.** `ModuleMap.php` references all 22 modules through 222 inline
`::class` expressions that the `^use` regex never matches — so any future kernel shipping `ModuleMap`
would depend on every module. And `EmployeeAccess`, `EmployeeOptions` and `LandlordUserColumn` import
`Employee`; they are used by eleven modules **including `crm`, which deliberately requires nothing**.
Both are exempted in `SHARED_NAMESPACES` today, which is exactly why neither shows up as debt.

Worth stating precisely: the **licence** graph (`requires` in `config/modules.php`) is already acyclic.
It is only the **import** graph that is not. Those two have been different since the module system
shipped, and `docs/modules-plan.md` §13 says so.

## §3 The minimum feedback arc set — seven edges, one group, about 28 files

Composer forbids cycles, not dependencies. And a composer `require` is not a licence `requires`: Payroll
depending on the Attendance *package* does not make Payroll unsellable without the Attendance *licence*,
because `AttendanceFigures::for()` still guards and still returns zeros. The codebase already draws this
distinction and defends it at length.

So the job is not to break sixty edges. It is to break a feedback arc set — after which every remaining
edge is a plain dependency that needs no work at all.

| Edge | Files | Cycles killed | Why it goes rather than its reverse |
|---|---|---|---|
| `employees → accounting` | 1 | **7** | It is one line: `Employee::bank()` → `Accounting\Models\Bank`, and `Bank` is not accounting (§7) |
| `core → {6 modules}` | 8 | 6 | All eight files are Core *pulling* aggregate lists; every one inverts to Core *receiving* (§9) |
| `accounting → {inventory, invoicing, payroll}` | 11 | 4 | The reverse edges are "a document posts to the ledger" — the principled direction (§8) |
| `attendance → payroll` | 1 | 2 | One `isMonthLocked()` call; Attendance must stay sellable to a factory with no payroll |
| `leave → attendance` | 1 | 1 | One `WorkPatternResolver` call whose docblock already describes the fallback |
| `employees → projects` | 2 | 1 | The relation manager is a Projects surface filed under Employees |
| `app/Support → employees` / `→ all` | 4 | hidden | Shared code must not depend on a module; it never should have |

`payroll → accounting` — 17 imports across 10 files — **stays**. A payslip posts to a ledger. It is
correct, principled, already guarded, and acyclic once its reverse is gone.

## §4 The safety rails, and why they land before anything moves

The discipline that made the last structural change survive was enforcing the invariant *before* moving
the files (`docs/modules-plan.md` §4, "Mitigation: enforce a morph map before moving anything"). Three
rails, in this order, before a single class changes hands.

**A unique index on `permissions(name, guard_name)`.** Today the table is `id, name, group, guard_name,
timestamps` with unique keys only on `roles` and the pivots, and `PermissionSeeder` matches on `name`
*and* `group` — so changing a permission's group creates a **second row with the same name**, roles keep
pointing at the first, and nothing reports it. The fix is the index, plus a pre-flight that fails loudly
on existing duplicates rather than dropping rows, plus `firstOrCreate(['name','group'])` →
`updateOrCreate(['name'], [...])`. Two things make this urgent rather than tidy: §5 moves every one of
the 245 rows, and the day this becomes a per-module contribution, a collision stops being an editing
mistake and becomes a structural possibility. Note the table is **landlord-side** — one table for the
whole installation, so this is one cleanup and not one per tenant.

**`ModuleMap::alias()` throws instead of passing through.** It currently returns an unmapped class
unchanged, which is the mechanism by which a moved class writes its *new* FQCN into
`journal_entries.source_type`, `payments.payable_type`, `stock_movements.source_type`,
`custom_fields.model_type`, `table_views.resource` or `consents.subject_type`. Reads then match nothing —
`unwindForPayslip` quietly finds no entries to reverse — and the data is poisoned with no error anywhere.
Make it throw for anything that is a `TenantModel`, a Filament Resource, Page or Widget; keep
pass-through only for genuinely foreign classes.

**An `alias-lock.json`, checked in.** All **112** current model aliases, verbatim, plus a test that a
shipped alias never changes. This replaces `test_morph_map_aliases_are_the_legacy_class_names` with
something strictly stronger and — importantly — with **no exemption list**, which was that rule's own
stated justification for being unconditional: *"a test with an exemption list is a test that gets edited
to pass."* A lock file is data; a diff to it is visible in review.

## §5 One manifest file per module

`app/Modules/{Name}/module.php` — a plain PHP array, returned. Into it, cut and pasted rather than
rewritten: the module's `config/modules.php` entry, its five `ModuleMap` slices, its `PermissionSeeder`
rows, its `RoleSeeder` grants, and its `NavigationDomains` / `NavigationTree` claims.

```php
return [
    'key' => 'mpr',
    'label' => 'MPR',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => MprPlugin::class,

    'models' => ['App\Models\MPR' => MPR::class],
    'resources' => [...], 'pages' => [], 'widgets' => [],
    'permission_groups' => ['MPR'],
    'permissions' => [['name' => 'MPRView', 'group' => 'MPR'], ...],
    'role_grants' => ['Employee' => ['MPRView', 'MPRCreate', 'MPRUpdate']],
    'navigation' => ['domain' => 'people', 'groups' => ['Employee'], 'branches' => [...]],
];
```

A `ModuleManifest` merges them and writes `bootstrap/cache/mizan-modules.php` — sibling of
`packages.php`, already git-ignored, already writable on every deploy. A provider then does
`config()->set('modules', ModuleManifest::registry())`, which means **all four existing readers of
`config('modules')` change by nothing at all**. That single line is the whole compatibility story and it
is what keeps this step reviewable.

**PHP, not JSON, and not a static method.** The reasoning is not aesthetic. Each entry in
`config/modules.php` carries paragraphs of load-bearing argument — why CRM requires nothing, why Leave
omits Payroll, why MPR's Employees dependency is presentational — and those paragraphs are the most
valuable content in the file. JSON cannot hold them; a static method would preserve them at the cost of
rewriting all 22 entries. Cut-and-paste is what makes this step mechanical, reviewable in an afternoon,
and revertible in a minute. A manifest file is also readable without booting 22 provider classes, which
matters because `moduleFor()` is called from `canAccess()`.

**Keep, for one release, a test asserting the merged arrays are `===` identical to the deleted hardcoded
ones.** That is what makes this step *provably* a no-op rather than assertedly one.

Two seeder rules that are bugs rather than inefficiencies if they are got wrong, both already documented
as traps in `docs/new-module-checklist.md` §6 and both multiplied by 22 here:
`PermissionCache::flushEverywhere()` runs **once at the end of the whole run**, never per module — it
discards and rebuilds the `PermissionRegistrar` singleton and carries the team id across by hand, and
running it mid-sequence is how a *later* seeder throws `RoleDoesNotExist` with a message that says
nothing about caches. And role grants are unioned across all modules and `syncPermissions()`-ed **once
per team**, because syncing per module detaches the previous module's grants.

**One limit, stated rather than papered over.** `ChartOfAccountsSeeder` does not decompose. Its codes
span modules — `1200 Employee Advances`, `1300 Inventory`, `2150 Sales Tax Payable`, `2210 EOBI` — and
they are the *customer's* numbering scheme, not the vendor's. Twenty-two modules contributing account
rows would fight over it. The chart stays whole and Accounting-owned; other modules contribute account
*requirements* — a named role with a suggested code, resolved through the existing `config('accounting.*')`
mapping, created if missing, **never renumbered**. This is the one contribution point where the naive
answer produces a worse product than the central seeder it replaces.

## §6 Contracts and registries

Seven contracts and seven registries, all introduced with null-object defaults bound in
`AppServiceProvider` before anything consumes them — so the step that adds them cannot break anything.

| Contract | Replaces | Default when unbound |
|---|---|---|
| `WorkingDayCalendar` | `Leave` → `Attendance\WorkPatternResolver` | reads `config('leave.weekend_days')` — the stopgap the current docblock already describes |
| `PeriodLock` | `Attendance` → `Payroll\PayrollRun::locked()` | returns false |
| `OwnedByUser` | `Core\CommentPolicy` → `Payroll\Payslip` | — |
| `EmployeeDirectory` | `app/Support/EmployeeOptions` → `Employee` | empty options |
| `ScopesEmployeeAccess` | `app/Support/EmployeeAccess` → `Employee` | privileged-for-everyone, empty id sets |
| `Payee` | `Accounting\Payment` → `Employee` | — |
| `FiscalYearCloseCheck` | `Core\FiscalYearsTable` → `Accounting\FiscalYearClosingService` | no blockers |

Registries — plain accumulators a plugin pushes into and one surface reads: `ReportRegistry`,
`CsvImporterRegistry`, `SettingsSectionRegistry`, `CustomFieldSubjectRegistry`, `DashboardStatRegistry`,
`RelationManagerRegistry`, `FiscalYearCloseChecks`.

`ScopesEmployeeAccess` deserves a note, because it is the clearest case of packaging forcing a fix that
was already owed. `EmployeeAccess` is shared code that imports a module's model and is used by eleven
modules including CRM — which the registry says requires nothing, and which the code has always
contradicted. The whitelist in `SHARED_NAMESPACES` is the record of that contradiction being noticed and
tolerated. A contract with a null default makes the licensing claim structurally true for the first
time.

Two of these are genuinely events rather than contracts: `Core\CreateUser` creating an `Employee`
becomes a `UserCreated` event with the listener in Employees, and the payslip-acceptance read in §8
becomes a `PayslipReviewed` listener. Everything else is a list.

## §7 The banking carve — one edge, seven cycles

`Employee::bank()` returns `Accounting\Models\Bank`. That one line is `employees → accounting`, and it
sits on seven of the fifteen SCC cycles. `Bank` is a 23-line IBFT reference table — `bank_code`,
`bank_name`, `bank_short_code`, `is_active` — and it is not accounting in any meaningful sense.

Move into a shared `App\Banking\` namespace: `Bank`, `BankFileAccount`, `Money`, `PayrollMonth` and the
`SelectsSalaryMonth` concern. Extract the file-format half of `SalaryBankExportService` — the
204-column layout, the column map, the row, header and trailer builders — as an `IPaymentsFileWriter`.
Introduce a `Payee` contract implemented by `Employee` and `Beneficiary`.

**This is also the only way to delete the one edge in the codebase that cannot be guarded at all:**
`Accounting\Services\BankPaymentExportService **extends** Payroll\Services\SalaryBankExportService`
(`BankPaymentExportService.php:19`). Inheritance across a module boundary cannot be wrapped in a licence
check, cannot be inverted at runtime, and cannot degrade. It has to be broken by extraction — and once
seen it is obviously right to break, because a Standard Chartered CSV layout is not payroll logic.

Highest leverage in the plan: one edge removed, seven cycles gone, and the hardest single obstacle to
any future extraction disappears with it.

## §8 Accounting stops reaching sideways

Twenty-four imports across fourteen files, in three groups, and only one group is real domain coupling.

**Group A — host-application code filed inside a module.** `Support/ReportPane.php` (997 lines) renders
*every report in the application*, including three Payroll ones and three Invoicing ones;
`Filament/Widgets/OperationsOverview.php` pulls four unrelated stats from four modules behind four
permission checks. Neither belongs to Accounting, and neither belongs to any package. `ReportPane`
becomes a `ReportRenderer` contract with each module rendering its own; `OperationsOverview` becomes
per-module stat contributions. These two files alone account for the whole of `accounting → inventory`
and most of `accounting → invoicing`.

**Group B — a legacy fallback that is already dead code.** `RegisterEntryService::immutableReason()`
carries an `$owners` array naming five module classes, but `journal_entries.source_type` already exists,
is already alias-normalised in a mutator, and the method already short-circuits on it before reaching the
array. Backfill the column for the five owner types, delete the array, and label through
`ModuleMap::label()`.

**Group C — the salary-payment knot, which is real.** `Payment` carries `payslip_id` and refuses to
release a salary until the payslip is accepted; `PaymentService::generateSalaryPayments()` reads payslips
and writes payments. Carve rather than merge: move `generateSalaryPayments()` and its backfill command
into Payroll, where they belong and where Accounting is already a declared dependency; and denormalise
the acceptance read onto `payments.subject_accepted_at`, written by a `PayslipReviewed` listener, leaving
`payslip_id` as a data-only foreign key. The column stays in every tenant, per the existing rule.

With Group A moved, Group B deleted and Group C carved, **the eight-module SCC dissolves.**

## §9 Core stops enumerating and starts receiving

Thirty-six `use` statements in eight files, every one of them Core *pulling* a list of things other
modules own. Every one inverts.

| Core file | What it pulls | Becomes |
|---|---|---|
| `Filament/Pages/Reports.php` | 12 Accounting pages, 3 Invoicing, 3 Payroll, plus `ReportPane` — **and one inline `\App\Modules\Accounting\Models\Account` this count missed** | `ReportRegistry`; each plugin registers its own reports |
| `Filament/Resources/CustomFields/CustomFieldResource.php` | 6 model classes in a `const MODELS` | `CustomFieldSubjectRegistry` — and since the values already go through `ModuleMap::alias()`, the registry keys on aliases and never loads the class |
| `Services/CsvImportService.php` | 5 classes from 3 modules | `CsvImporterRegistry` — which gains a real feature, because a module shipping an importer then appears automatically |
| `Filament/Pages/CompanySettings.php` | `Account`, `Currency`, `JournalEntryLine` | `SettingsSectionRegistry`; Accounting contributes its own sections and their validation |
| `Filament/Resources/FiscalYears/Tables/FiscalYearsTable.php` | `FiscalYearClosingService` | `FiscalYearCloseCheck`; `blockers()` already returns `string[]`, so the contract is the existing signature |
| `Filament/Resources/Users/Pages/CreateUser.php` | `Employee::create()` in `afterCreate()` | a `UserCreated` event with the listener in Employees |
| `Policies/CommentPolicy.php` | `instanceof Payslip` | `OwnedByUser` — which incidentally gives every commentable model the self-service visibility only Payslip has today |
| ~~`Models/FiscalYear::salarySlabs()`, `Models/User::mprs()`~~ | ~~`SalarySlab`, `MPR`~~ | **already done in phase 1.** Seven files, not eight |

`Reports.php:43-45` says *"Lives in Core, not Accounting, because it spans four modules."* That is the
right instinct arriving at the wrong destination: something spanning four modules belongs **above** all
four, not inside the one that is always licensed.

When these eight land, `KNOWN_COUPLINGS['core']` is deleted, the `'core'` seed comes out of
`allowedTargets()`, and the acyclicity test passes.

> **As built, two corrections to that sentence.** `KNOWN_COUPLINGS['core']` is deleted — that part is done.
> The `'core'` seed **stays**: it is §1's "every module may depend on Core for free" licence, it only ever
> governed the *reach* test, and the acyclicity test is built from `moduleGraph()` and never consulted it.
> And the acyclicity test does not pass — it now reports **9** trapped modules rather than 13, because Core
> was never the only cycle, just the only one this plan could reach. See "Phase 9 — Core stops receiving
> nothing".

## §10 What is deliberately left for extraction

Recorded now because it is known now, and because the next plan should not have to rediscover it.

**Migrations are the binding constraint.** 104 flat files in `database/migrations/tenant/`, the path
hardcoded in `TenantMigrations::PATH:24`, in `CompanyProvisioner` and in five tests, and **no module
anywhere calls `loadMigrationsFrom`**. When packages arrive, the archive should be frozen and host-owned,
running as wave zero, with new migrations going to their package — because many existing files create
tables for several modules at once and splitting them means renaming, which means re-running on every
tenant. And the migrator should run **once per package in dependency order** rather than being handed
every path at once: filename timestamps chosen independently by separate repositories still produce a
total order, but a package released later with an earlier date sorts first and breaks **only fresh tenant
provisioning** — a failure no existing tenant reproduces.

**The first package should be `mpr`.** Fourteen PHP files, `requires: []`, and after the free deletions
below a pure leaf. Its two inbound edges are the two shapes that recur everywhere — a Core model reaching
into a module, and a guarded read-model in Performance — so both get proven on 850 lines instead of on
Payroll's 8,000. It was also the pilot for the original physical move, so the recipe already exists.

**Not every module is worth extracting.** `advances` (12 files), `expenses` (13), `billing` (13),
`timesheets` (10), `quotations` (13), `support` (13) and `personal_finance` (9) each carry the full
per-package tax — repo, CI, versioning, changelog, compatibility matrix — and several can only ever be
installed alongside a module they hard-require. The useful general rule, worth stating before anyone
assumes otherwise: **a package boundary and a licence boundary do not have to coincide.** One package may
contain several licensable modules, which is why 22 modules should not become 22 packages. The test is
"does anyone buy it alone", never "is it small".

**The morph map becomes asymmetric.** Installing is easy; *uninstalling* a package whose aliases are
written into tenant rows is a data-migration event, because a morph read for a deleted class cannot
resolve. Whatever plan does extraction owes an answer — orphaned-alias tombstones that throw on write and
degrade on display is the shape — and owes it before the first package can be removed rather than after.

## Phases

Each phase is one commit or a short series, leaves the suite green, and only ever removes entries from
`KNOWN_COUPLINGS`. The stale-entry assertion makes that self-policing: an edge deleted without its entry
fails the build, and an entry deleted without its edge fails too.

- **Phase 0 — Make the lint honest, and build the progress bar.** Extend `importsIn()` to match inline
  `\App\Modules\X\…::class`. `test_shared_namespaces_do_not_reach_into_modules` then correctly fails on
  `ModuleMap.php` and the three employee helpers; record them in a named `SHARED_DEBT` list — the only
  thing this plan adds rather than removes. Then add `test_the_module_graph_is_acyclic()`, building the
  graph from `KNOWN_COUPLINGS` + `requires` + `X → core`. **Ends with:** a failing test naming 21 cycles.
  That number is the progress bar for everything below.
- **Phase 1 — The free deletions.** `MPR::employee()` is a `belongsTo(Employee::class)` with **no
  `employee_id` column in any migration** — not merely unused but broken if ever called. Delete it, and
  `FiscalYear::salarySlabs()` and `User::mprs()` with it. **Ends with:** 18 cycles, three edges gone, no
  behaviour changed, and the ratchet demonstrated.
- **Phase 2 — The safety rails (§4).** The permissions unique index and its dedupe, the throwing
  `alias()`, and `alias-lock.json`. **Ends with:** a duplicate permission name being impossible, and a
  moved class that loses its alias failing loudly instead of poisoning a column.
- **Phase 3 — The manifests (§5).** One `module.php` per module; `config/modules.php` deleted;
  `ModuleMap`'s five consts gone; `PermissionSeeder` holding no literal rows; navigation claims moved.
  **Ends with:** the byte-identical merged-arrays test passing, and `app/Support` no longer naming a
  module.
- **Phase 4 — Contracts and registries (§6).** All fourteen, with null defaults, consumed by nothing.
  **Ends with:** a step that provably cannot have changed behaviour.
- **Phase 5 — The two one-file cuts.** `LeaveDayGenerator` → `WorkingDayCalendar`;
  `RegularizationService` → `PeriodLock`. Both existing `modules()->enabled(...)` guards collapse into
  container bindings. **Ends with:** 15 cycles, and `ModuleDegradationTest` unchanged and green.
- **Phase 6 — The banking carve (§7).** **Ends with:** 8 cycles, and no `extends` crossing a module
  boundary anywhere in the codebase.
- **Phase 7 — `employees → projects`.** The read-only relation manager moves to Projects and is
  contributed back; `Employee`'s three project relations become string-classname. **Ends with:** 7
  cycles.
- **Phase 8 — Accounting's outbound edges (§8).** **Ends with:** the eight-module SCC dissolved, and
  `KNOWN_COUPLINGS['accounting']` deleted.
- **Phase 9 — Core's eight files (§9), one commit each.** **Ends with:**
  `test_the_module_graph_is_acyclic()` passing, `KNOWN_COUPLINGS['core']` deleted, and the plan complete.

## Risks

Every risk here produces correct-looking output. That is the selection criterion — the ones that throw
will be found.

- **A vacuous green suite.** The most dangerous outcome, because it looks like success, and **it has
  already happened in this repository.** `docs/modules-plan.md` §13 records that after the first module
  move, `ModuleCoverageTest` discovered classes by scanning `app/Models` and `app/Filament`, both of
  which were then empty, so **every invariant passed over nothing**. `sourceRoots()` now enumerates
  `app/Modules/*` and the guard-the-guard floors (`>30` models, `>40` Filament classes, `>80`
  permissions) exist because of it. Mitigation: phases 3, 6 and 7 move classes, and the discovery
  assertions are updated in the *same commit* that moves them — never the next one.
- **`alias()` passing an unmapped class through.** A moved class writes its new FQCN into one of the six
  class-string columns; reads stop matching; `unwindForPayslip` finds nothing to reverse and reports
  success. Mitigation: phase 2 lands before phase 6, which is the first phase that moves a model.
- **A duplicate permission row.** No unique index today, and the seeder matches on name *and* group, so
  grants split silently across two rows and the denial has no error anywhere. Mitigation: phase 2, first
  item, with a pre-flight that fails rather than deletes.
- **`flushEverywhere()` or `syncPermissions()` called per module.** The first resets the registrar's team
  id mid-run, so a *later* seeder throws `RoleDoesNotExist` with a message about roles that says nothing
  about caches — which its own docblock records as having happened. The second detaches the previous
  module's grants, which is trap 2 multiplied by 22. Mitigation: both are union-then-once-at-the-end in
  §5, and `ModulePermissionFilteringTest` is the guard.
- **A navigation group claimed by nobody after the manifest move.** The item keeps its declared label, no
  domain owns that label, and the screen becomes reachable only by URL and the ⌘K palette — the failure
  `NavigationDomains`' own docblock names as its central risk. Branch claims match by **label string**,
  so a cosmetic rename triggers it. Mitigation: allow branches to claim by class-string as well, prefer
  class when both are present, and keep the two-way coverage tests.
- **A stale generated registry**, or a leftover `config/modules.php` baked into `bootstrap/cache/config.php`
  by `config:cache` and read by something before the provider registers. Mitigation: the merged registry
  is rebuilt from `post-autoload-dump` and by `optimize`, and the boot throws if `config/modules.php`
  still exists — converting a class of silent staleness into a one-line failure.
- **A behavioural change smuggled into a mechanical phase.** The whole plan depends on some phases being
  provably no-ops. Mitigation: the rule, applied without exception — **no phase both moves files and
  changes semantics.** Phase 3 has a byte-identical assertion for exactly this reason, and phase 4 exists
  as its own phase rather than being folded into 5 through 9 for the same one.
- **Effort concentrated in two places.** Core's eight files and Accounting's fourteen are most of the
  work, and both touch the report hub, which is the most-used screen in the application. Mitigation: they
  are last, they are one commit per file, and every one of them is guarded by an existing test asserting
  a report page is reachable.

## As built (phases 0–5)

Where this plan was wrong or incomplete, recorded in the same spirit as
`docs/modules-plan.md` §13.

### The graph was worse than the plan measured, and the plan's own instrument was why

The plan said 21 cycles. The first thing phase 0 did was make `importsIn()` see
inline `\App\Modules\X\…::class` references as well as `use` statements, and that
immediately surfaced **nine edges nobody had recorded**:

```
billing   -> timesheets     MonthlyBillingService, via app(BillableHours::class)
payroll   -> advances       Payslip, PayslipService
payroll   -> expenses       Payslip, PayslipService
employees -> payroll        EmployeeSetting::components(), ComponentsRelationManager
```

All of them are guarded — `PayslipService::advanceInstalmentFor()` returns `0.0`
with the module off, `hoursLines()` returns `[]` — so no behaviour was wrong. What
was wrong is *why* the container was used. `KNOWN_COUPLINGS` said Payroll reached
Advances "through the container, with no import — because Advances requires
Payroll and an import would make the pair a cycle." That is the cycle being
avoided in the lint rather than in the code: `app(X::class)` still needs `X` on
disk, so composer would still need the edge.

Measured honestly, the opening position was **14 of 22 modules trapped**, in two
components — `[12] accounting, advances, attendance, core, employees, expenses,
inventory, invoicing, leave, mpr, payroll, projects` and `[2] billing,
timesheets`. `employees -> payroll` is the one that is not even guarded: a plain
`hasMany` across the seam.

### The ratchet measures trapped modules, not cycles

Counting elementary cycles is exponential in a dense graph — the 12-module
component would hang the test long before it reported anything, and the count
would swing wildly on one edge. `TANGLED_MODULE_BUDGET` counts the modules inside
strongly connected components instead: a module is extractable or it is not, and
breaking a component always shrinks or splits it. It fails in both directions, so
a budget left stale after a fix is as loud as a regression.

Its coarseness is real and worth knowing: phase 5 removed two edges and the count
did not move, because Attendance and Leave stay in the 12-module component by
other paths. Per-phase progress shows up in `KNOWN_COUPLINGS` shrinking, which is
already enforced.

### A regex over source invents edges; the scan has to tokenise

The first version of the inline pattern matched
`\App\Modules\Payroll\Models\Payslip::class` **inside a docblock** — ModuleMap's
own comment explaining what aliases are for. That is worse than missing an edge:
it keeps a `KNOWN_COUPLINGS` entry looking alive long after the code was fixed,
which is exactly the staleness the list exists to prevent. `importsIn()` now
strips comments with `token_get_all()` before matching.

### Contract defaults must register before the modules that override them

Phase 5's two bindings went into `AppServiceProvider`, which `bootstrap/providers.php`
lists **after** every module provider — so the default overwrote Attendance's real
calendar and every leave request silently fell back to the configured weekend. One
test caught it (`leave counts saturday for an employee on a six day pattern`) and
nothing else would have. Defaults now live in `ContractDefaultsServiceProvider`,
registered before the modules.

A second bug in the same phase: the first `ConfiguredWeekendCalendar` read
`config('leave.weekend_days')` where the code it replaced read
`setting('leave.weekend_days', [6, 7])` — a per-tenant setting. Every company
would have got the same weekend, correctly, and silently.

### What is in place

| Phase | Built |
|---|---|
| 0 | `importsIn()` sees inline references and strips comments; `SHARED_DEBT` replaces the blanket `employees` exemption, with staleness enforced; `test_the_module_graph_is_acyclic()` with `TANGLED_MODULE_BUDGET` |
| 1 | `MPR::employee()` (a `belongsTo` with no `employee_id` column — it could only ever have thrown), `FiscalYear::salarySlabs()`, `User::mprs()` deleted. **14 → 13** |
| 2 | Unique index on `permissions(name, guard_name)` with a failing pre-flight rather than a silent survivor; seeder matches on `name`; `ModuleMap::alias()` throws for an unmapped model/resource/page/widget; `tests/alias-lock.json` pins all 221 shipped aliases |
| 3 | 22 × `app/Modules/{Name}/module.php`; `ModuleManifest` merges and caches to `bootstrap/cache/`; `config/modules.php` **deleted**; ModuleMap's five `const` tables (423 lines) **deleted**; `PermissionSeeder`'s 245 literal rows **deleted**. Verified byte-identical to the central arrays before the originals were removed. `ModuleMap.php` left `SHARED_DEBT` |
| 4 | `WorkingDayCalendar` + `ConfiguredWeekendCalendar`, `PeriodLock` + `NeverLocked`, bound in `ContractDefaultsServiceProvider` |
| 5 | `LeaveDayGenerator` → `WorkingDayCalendar` (Attendance binds `WorkPatternCalendar`); `RegularizationService` → `PeriodLock` (Payroll binds `PayrollRunPeriodLock`). `leave -> attendance` and `attendance -> payroll` deleted |
| 6a | `Bank` → `App\Modules\Core\Models` (alias unchanged); `Bank::employees()` and `BankResource`'s Employees relation manager deleted; `BankResource`, `BankPolicy` and the `Bank` permission group stay in Accounting. `employees -> accounting` deleted. **Trapped: still 13** |
| 8A | `OperationsOverview` → Core, its four figures registered per module (`App\Support\DashboardStats`); `ReportPane` 1032 → 491 lines, Payroll's and Invoicing's six reports moved to `PayrollReports`/`InvoicingReports` and registered (`App\Support\Reporting\ReportRenderers`), shapes shared via `ReportShapes`. **`accounting -> inventory` and `accounting -> invoicing` both deleted** |
| 8C | `payments.subject_review` + `_reason` + `_reviewed_at`, backfilled; `PayslipReviewed` + `CopyReviewOntoPayment` keep them current; `generateSalaryPayments()` stamps them at creation; `Payment` reads its own columns and declares its own `REVIEW_*`; `payslip()` contributed by Payroll. `accounting -> payroll` **not yet** deleted — see below |
| 8B | The register's five-owner array → `App\Support\JournalEntryOwners`, each module registering what it owns. Behaviour unchanged — deliberately *not* the deletion the plan called for |
| 7 | `employees -> projects` deleted by reversing it: `ProjectsServiceProvider` registers the three project relations on `Employee` via `Model::resolveRelationUsing()` and contributes the Projects tab through `App\Support\ResourceContributions`; `Employee::currentProjects()` deleted (no callers). **Trapped: still 13** |
| 6b | The iPayments layout (204 columns, the column map, `row()`, `formatAmount()`, `escape()`) → `App\Support\Banking\IPaymentsFileWriter`; `BankPaymentExportService` **stops extending** `SalaryBankExportService` and takes the writer by constructor. A bank filter on the Employees list replaces the deleted relation manager. `ModelRelationsResolveTest` added |
| 9 (first five) | `CommentPolicy` → `OwnedByUser`; `CreateUser` → a `UserCreated` event with the listener in Employees; `CustomFieldResource`'s `const MODELS` → `CustomFieldSubjects`, keyed on aliases so no model class is ever loaded; `FiscalYearsTable` → `FiscalYearCloseCheck` (+ `NoFiscalYearClose`); `Reports.php` → `ReportCatalogue` + the `ReportPaneRenderer` contract (+ `NoReportPane`) |
| 9 (last two) | `CsvImportService`'s three imports → one `CsvImporter` each, owned by Invoicing, Inventory and Accounting and registered through `App\Support\CsvImporters`; Company Settings' currency and payroll-posting sections → `CurrencySettingsSection` and `PayrollPostingSettingsSection`, contributed through `App\Support\SettingsSections`. Plus `ReportPane::drillTarget()`, for the one inline reference the `use`-statement reading of §9 had missed. **`KNOWN_COUPLINGS['core']` deleted. Trapped: 13 → 9** |

Not yet done from phase 3: `RoleSeeder`'s grants and the navigation claims are
still central. Neither is on the cycle path — `ModuleMap` was — but both are part
of collapsing the new-module checklist.

### Phase 6a — the Bank model, and what the carve actually cost

The blocker below was answered by not inventing a namespace. §7 proposed `App\Banking\`, and the
objection recorded against it was decisive: `ModuleCoverageTest::sourceRoots()` scans `app/{kind}` and
`app/Modules/*/{kind}` only, so `app/Banking/Models` is invisible to every invariant in that file — the
vacuous-green failure this plan's own risk list names. **Core already solves all of it.** It is scanned,
it is locked so a model there can never be gated off, and it has the documented precedent: `Holiday` and
`FiscalYear` live there because "leave, attendance and any future timesheet validation all ask *is this a
working day*, and none of them owns the answer". `Bank` is that shape exactly — Employees reads it for a
salary account, Accounting for company accounts and beneficiaries, Invoicing for a contact's.

Three things this turned up that the plan did not anticipate:

- **The alias was already `App\Models\Bank`**, so moving the class moved nothing stored.
  `AliasLockTest` is explicit that the class behind an alias may move — that is what the indirection is
  for — so this was a one-line lock update and no data migration. Worth knowing before §8 and §9, which
  move considerably more.
- **The edge was two-way, and the return leg cost a screen.** `Bank::employees()` was
  `accounting → employees` in its own right, and Core may import nothing, so it had to go — taking with it
  the read-only Employees list on `BankResource`. That relation manager's own comment gave its reason as
  "parity with the Nova HasMany field", and Nova was removed entirely
  (`docs/filament-laravel13-migration-plan.md`), so the justification had already expired. The Employees
  list carries `bank_code` and `bank_short_code` as sortable columns, so nothing became unanswerable.
  **A carve is not always free, and this one was paid for in a screen.**
- **A same-namespace reference is invisible to a grep for the FQN.** `Beneficiary::bank()` said
  `belongsTo(Bank::class)` with no import at all, because `Bank` was in `App\Modules\Accounting\Models`
  beside it. Every FQN search came back clean and the model moved with a live reference to a file that no
  longer existed; four tests caught it at runtime. §8 and §9 move classes *within* their own namespaces,
  where this is the normal case rather than the exception — search for the bare `X::class` too.

**The trapped count did not move: still 13.** §7 claims "one edge removed, seven cycles gone" and that is
not what the ratchet reports, for the same reason phase 5's two edges did not move it — Employees stays
inside the twelve-module component through `employees → payroll`, the unguarded `hasMany` that §3 already
names as debt rather than design. The Bank carve is still worth having (`KNOWN_COUPLINGS` shrank, and the
`employees → accounting` edge is gone for good), but **§7's leverage claim should be read as being about
the rest of §7** — `BankFileAccount`, `Money`, `PayrollMonth`, `SelectsSalaryMonth`, the
`IPaymentsFileWriter` extraction and the `BankPaymentExportService extends SalaryBankExportService`
inheritance, none of which this phase touched. The inheritance in particular is still there, and it is
still the one edge in the codebase that cannot be guarded at all.

### Phase 6b — the inheritance, and the guard that should have existed first

**The unguardable edge is gone.** `BankPaymentExportService extends SalaryBankExportService` is now
composition: the three things the child actually used — `row()`, `formatAmount()` and the 204-column map
behind them — were a *file format*, so they moved to `App\Support\Banking\IPaymentsFileWriter`, which
neither module owns and which imports nothing from any module. Verified by mutation: shifting one column
index in the writer fails tests on **both** the salary path and the payment path, which is the evidence
that one writer now serves both rather than one having quietly kept a copy.

Breaking the inheritance also removed something misleading that nobody had noticed: the child inherited
`export()`, `paymentsForMonth()` and `fileName()`, all salary-specific and none of them wanted. Nothing
called them, but they were part of its public surface.

`accounting -> payroll` stays in `KNOWN_COUPLINGS`, and correctly — `Support/ReportPane.php` still
imports Payroll, which is §8's Group A, not this phase's.

### The lesson from 6a was learned twice before it was learned

The same-namespace warning above was written into this document *and then repeated as a mistake in the
same session*. Searching for remaining bare `Bank::class` references, the search filtered out
`CompanyBank` to quieten noise from `CompanyBankAccount` — and so hid the one line in
`CompanyBankAccount::bank()` that said exactly `Bank::class`. It reached a full-suite run before anything
caught it.

So the guard is now a test rather than a note. `ModelRelationsResolveTest` builds every relation on every
model in the morph map and asserts the class on the far end loads — which is the one thing that finds a
dangling same-namespace reference, since there is no import to grep for and no module boundary for the
lint to see. It costs about a tenth of a second, needs no database, and it names the model and the method.
Confirmed against the real bug: restoring it makes the test fail with
`CompanyBankAccount::bank() — include(.../Accounting/Models/Bank.php): Failed to open stream`.

**Run it before §8 and §9.** Both move classes within their own namespaces, where a bare reference to a
neighbour is the normal case rather than the exception.

### Phase 7 — and where this plan told itself to do the wrong thing

Phase 7 as written has two halves: move the relation manager, and make "`Employee`'s three project
relations become string-classname". **The second half was not done, because this document already
explains why it is wrong.** Phase 0's own findings say it outright: Payroll reached Advances through the
container "with no import, because an import would make the pair a cycle", and that is *"the cycle being
avoided in the lint rather than in the code — `app(X::class)` still needs `X` on disk, so composer would
still need the edge"*. `hasMany('App\Modules\Projects\Models\Project')` is the identical trick with
an identical result: the lint goes quiet and the dependency is untouched. A plan is allowed to be wrong
in one section and right in another; what it is not allowed to do is both at once without somebody
noticing.

What was done instead is the reversal the dependency direction already permits. `projects` **requires**
`employees`, so Projects may name Employees all it likes; the cycle was only ever the other way. So:

- The three relations are registered by `ProjectsServiceProvider` through `Model::resolveRelationUsing()`.
  `$employee->projects()` still works — `Model::__call()` consults the relation resolvers before falling
  through to the query builder (`vendor/laravel/framework/.../Model.php:2833`), which was checked in the
  vendor source rather than assumed, because the property form working and the method form not would have
  been a silent break in `TimesheetService`.
- The Projects tab is contributed rather than declared. `EmployeeResource::getRelations()` returns its own
  managers plus `ResourceContributions::relationManagersFor(static::class)`, and Projects fills the slot
  from its provider. **The tab is now present exactly when Projects is**, which it was not before.
- `Employee::currentProjects()` was deleted rather than moved. Nothing called it — a free deletion of the
  same kind phase 1 found three of.

`ProjectsContributionTest` covers what a boot-time relation makes invisible: the relations exist and are
the right kind, the pivot survives, the two manager relations read the columns they are named for (they
are one transposition apart), and the tab both appears *and* is a contribution rather than a declaration —
that last assertion is the one that stops somebody "fixing" a future problem by naming the class in
`EmployeeResource` again and restoring the cycle under a green suite.

**Moving a class needs a repo-wide search for its basename, not its namespace.** The relation manager
moved and `tests/Feature/ProjectAssignmentTest.php` still named the old path; the searches run before the
move covered `app/Modules/Employees` and not `tests/`, so it reached a full-suite run. That is the second
dangling reference in two phases, and the two have *different* shapes: 6a's was a bare same-namespace
reference with no import to find (now caught by `ModelRelationsResolveTest`), this one was a perfectly
ordinary import in a directory nobody searched. Neither guard catches the other's case. The cheap habit
that catches both: `grep -rn ClassBasename app tests database` before moving anything, and again after.

**Trapped: still 13.** Phase 7's stated end state was "7 cycles"; the ratchet does not move, and this time
the components say exactly why — `[11] accounting, advances, attendance, core, employees, expenses,
inventory, invoicing, leave, payroll, projects` and `[2] billing, timesheets`. Projects stays inside the
eleven through `invoicing -> projects`, not through anything Employees does. Three phases have now removed
real edges without moving this number, which is worth saying plainly: **the module-count ratchet is the
right invariant and the wrong progress bar.** `KNOWN_COUPLINGS` shrinking is the progress bar, and it has
shrunk in every one of those phases.

### Phase 9 — Core stops receiving nothing

**The prediction held, and it was the only one that did.** Core now names no module, and the count moved
13 → 9 in a single phase after three phases moved it by nothing at all. Four modules left together:

```
before  [11] accounting, advances, attendance, core, employees, expenses,
             inventory, invoicing, leave, payroll, projects
after    [7] accounting, advances, attendance, employees, expenses, leave, payroll
         [2] billing, timesheets   (unchanged)
```

`inventory`, `invoicing` and `projects` were never knotted to anything in their own right — they were held
in only by the two-cycles through Core. That is the shape of a hub, and it is worth stating as a general
lesson rather than a fact about this codebase: **in a graph with a universally-depended-on node, every edge
*out* of that node is worth more than any number of edges between the leaves.** Phases 6–8 deleted real
couplings and were right to; they simply could not show up in this metric, and the metric was not wrong
either. Both were measuring what they said they measured.

**Three of §9's eight rows were not what the table said.**

- `FiscalYear::salarySlabs()` and `User::mprs()` were already deleted in phase 1, as the previous note
  recorded. Seven files, not eight.
- The **last reference was not a `use` statement at all.** `Reports.php:392` reached `Account` through a
  fully-qualified inline `\App\Modules\Accounting\Models\Account::query()`, so it survived every grep for
  `^use App\Modules\` and only surfaced when the lint failed after the seventh file landed. Phase 0's whole
  point was making the scan see inline references, and the plan's own §9 count was still taken with the
  reading that misses them. **Trust the lint's list, never a grep, when deciding a file is finished.**
- §9 also says *"the `'core'` seed comes out of `allowedTargets()`"*. It should not, and it did not.
  That seed is the "every module may depend on Core for free" licence from §1 — it is what makes the
  *reach* test tolerate twenty modules importing Core models — and the acyclicity test never used it, being
  built from `moduleGraph()` instead. Removing it would have flagged most of the codebase to prove nothing.

**What the two remaining inversions each needed beyond a list.** `CsvImporters` is a registry of behaviour,
not data: an import is columns *and* a validator *and* a writer, so it is an interface (`CsvImporter`) with
the registry holding class names resolved through the container — the opening-balances importer takes
`JournalEntryService` by constructor. `SettingsSections` needed three hooks rather than a list of setting
keys, and the reason is the base currency: it is a *row* in the currencies table, not a setting, so
"read the keys back" would never have covered it. Hence `components()`, `fill()` and `save()`.

**Both registries sort explicitly, and the first attempt did not.** Registration order is provider boot
order, which is alphabetical accident — so the import page's default type came out as *opening balances*
(Accounting boots first) instead of contacts, and it would have opened showing a date field. `DashboardStats`
had already learned this and says so in its docblock: *reading order is a decision, not discovery order*.
Any registry whose output a human reads in sequence needs a sort argument from the start.

**Two things got better rather than merely moving.** With no importer registered the CSV page is
unreachable rather than an empty dropdown, and with no Accounting module Company Settings stops writing
`accounting.payroll_accounts` on every save — a setting nothing could ever read. Neither was asked for;
both fall out of a module having to *offer* what it owns instead of Core assuming it is there.

### Core is the hub, and §9 was the only lever left

`accounting -> payroll` is now gone — §7's leftovers went with it: `SelectsSalaryMonth` and the
fiscal-month arithmetic moved to `App\Support\Banking` (the trait's Payslip default became a null hook
that Payroll's two pages now declare), and `generateSalaryPayments()` became
`Payroll\Services\SalaryPaymentGenerator`, triggered through `App\Support\PaymentGenerators` because the
*caller* is an Accounting page — moving the method alone would only have inverted the edge. Accounting's
debt list is `['employees']`, down from four modules.

**And the trapped count did not move. Again — 13, with the identical eleven-module component.** Four
consecutive phases have now deleted real edges without shifting it, and the reason is finally clear
enough to write down:

```
[11] accounting, advances, attendance, core, employees, expenses,
     inventory, invoicing, leave, payroll, projects
[2]  billing, timesheets
```

Every module may depend on Core for free, and `KNOWN_COUPLINGS['core']` lists Core depending on
**accounting, payroll, invoicing, inventory and employees**. That is five two-cycles through the one module
nothing can avoid, and they transitively bind everything that touches any of the five. No amount of
tidying between the leaves can break a knot tied at the root.

So the earlier reading of this plan — that `accounting -> payroll` and `employees -> payroll` were "what
the count turns on" — was wrong, and measurably so. **§9 is the lever.** *(Written before phase 9; it was,
and the section above records what happened.)* Its own closing sentence already
said as much (*"when these eight land, `KNOWN_COUPLINGS['core']` is deleted, the `'core'` seed comes out of
`allowedTargets()`, and the acyclicity test passes"*); what was not obvious until four phases had been
spent elsewhere is that nothing *before* §9 can move the number at all. Phases 6–8 were still worth doing
— they removed the unguardable inheritance, three module edges and a false data-integrity risk — but their
value was never going to show up in this metric.

One correction to §9's own table while we are here: its last row asks to delete
`FiscalYear::salarySlabs()` and `User::mprs()`, and **phase 1 already deleted both**. Seven files remain,
36 imports, of which `Reports.php` alone holds 19.

### Phase 8 Group C — three columns, not one, and the edge does not close yet

**§8's single `subject_accepted_at` cannot carry what the screen says.** A blocked salary shows one of two
different things — `BLOCK_REJECTED` with the employee's rejection reason, or `BLOCK_UNACCEPTED` with
"has not accepted yet" — and a timestamp collapses both into "not accepted", losing the reason and the
category the bank-file screen colours rows by. So `subject_review`, `subject_review_reason` and
`subject_reviewed_at` all move, and `Payment` declares its own `REVIEW_ACCEPTED`/`REVIEW_REJECTED` so it
need not read Payroll's constants.

**The listener alone is not enough, which the plan does not mention.** A review recorded *after* a payment
exists is the listener's case; the common order is the opposite — the payslip is accepted long before
anybody opens the bank file — so `generateSalaryPayments()` stamps the copy at creation too. Without that
second write every generated payment starts life looking unaccepted and the whole batch is held back.

**`belongsTo` inside `resolveRelationUsing` needs its foreign key named.** `Payment::payslip()` is now
contributed by Payroll, as the Projects tab was in phase 7, and `belongsTo()` infers its key from the
*calling method's* name — which inside a closure is `{closure}`. Thirteen tests failed on a query for
`payments.app\_modules\_payroll\{closure}_id`. Phase 7's relations escaped this only because they passed
their keys anyway; §9 should assume every contributed relation needs them explicit.

**What this bought, and what it cost.** Accounting no longer reads a Payroll *rule*: the release gate is
answered from the payment's own row, so it holds with Payroll absent. The cost is a denormalisation that
can go stale, and the only thing standing between it and a wrongly-released salary is two writers and a
backfill — which is why `PaymentReleaseGateTest` asserts the event fires, the listener is registered *on
the real dispatcher*, both writers copy, the two vocabularies have not drifted, and a payment with no
payslip is releasable rather than blocked. Both staleness paths were mutation-verified.

**`accounting -> payroll` is still there, and Group C as written was never going to close it.** §8 names
`Payment` and `generateSalaryPayments()`; the remaining imports are in
`Accounting\Filament\Pages\BankPaymentFile`, which uses Payroll's `SelectsSalaryMonth` concern and
`SalaryBankExportService` — and those are **§7's leftovers**, the part of the banking carve that moved
`IPaymentsFileWriter` but not `SelectsSalaryMonth`, `Money` or `PayrollMonth`. So the SCC does not
dissolve at the end of phase 8 as §8 predicts; it dissolves when §7 is finished. Worth fixing in the plan
rather than discovering again.

### Phase 8 Group B — the array was not dead code, and deleting it would have been a data bug

§8 Group B calls the `$owners` array in `RegisterEntryService::immutableReason()` "a legacy fallback that
is already dead code", on the stated grounds that `journal_entries.source_type` is set first and
short-circuits ahead of it. **That is true of one of the five owners.** `DepreciationService` stamps
`source_type` for `FixedAsset`; `PaymentService`, `InvoiceService` (for the sale entry) and
`PettyCashService` stamp nothing at all, and `RegisterEntryEditTest` asserts the array's message for a
payment and for a petty cash voucher specifically. Backfilling and deleting would have made every entry
owned by a payment, an invoice, a petty cash voucher or a stock movement **editable from the register** —
which is the desynchronisation the method exists to prevent, arriving as a lint improvement.

So the lookup became a registry instead: Accounting registers its three owners, Invoicing registers the
invoice, Inventory registers the stock movement, and `immutableReason()` asks. Same two edges removed,
same behaviour, no migration and no backfill to get wrong. `JournalEntryOwnersTest` pins the five
registrations *and* the premise — it asserts that only depreciation stamps `source_type`, so if that ever
changes for all of them, Group B's deletion becomes possible and the test says to reopen it rather than
sitting there asserting the past.

### Phase 8 Group A — host code moved out, and a near-miss worth recording

Both files §8 names were misfiled rather than coupled, and both moved:

- **`OperationsOverview`** → Core, with its four figures registered by the modules that own them
  (`App\Support\DashboardStats`). It gained something in the move: a stat now disappears with its module
  rather than being hidden by a permission check that happens to be false.
- **`ReportPane`** 1032 → 491 lines. Payroll's three reports and Invoicing's three moved to
  `PayrollReports` and `InvoicingReports`, registered through `App\Support\Reporting\ReportRenderers`;
  the four shapes they share moved to `App\Support\Reporting\ReportShapes`, so the look is unchanged
  because it is literally the same code. Accounting's eleven stay where they are — moving those too would
  be indirection for its own sake.

Together these delete **`accounting -> inventory` and `accounting -> invoicing` entirely**, which is what
§8 predicted for Group A alone.

**The near-miss:** cutting the moved methods out of `ReportPane` by matching from a method signature to
the next section marker silently took eight of Accounting's *own* adapters with it —
`contractorPayments`, `budgetVsActual`, `pettyCash`, `revaluation`, `accountRegister`,
`findTransactions`, `fiscalYear`, `drillable`. Nine tests failed immediately, so nothing shipped; what
made it recoverable was that all eight were committed and unmodified, so they came back from `HEAD`
verbatim. Then Pint's `no_unused_imports` had already removed the imports they needed, which failed a
*second* time with a container error rather than a syntax one. **Extracting code by text boundaries needs
a method-list diff before and after** — `grep 'function ' | sort | comm` took ten seconds and would have
caught both.

### What phase 6 needed decided first

The banking carve is blocked on a question the plan did not anticipate. Moving
`Bank` out of Accounting changes **which module gates it**: `ModuleCoverageTest`
discovers models only under `app/Models` and `app/Modules/*/Models`, so a new
`app/Banking/Models` would be silently invisible to every invariant in that file —
the vacuous-green failure this plan's own risk list names. Putting it in
`app/Models` works and makes the legacy alias `App\Models\Bank` true again, but
then some module must declare it, and whichever does becomes its gate while
`BankResource` stays gated by Accounting's permission group. That split needs to
be deliberate, not discovered.

## Suggested first slice

**Phases 0–2.** The honest lint, the free deletions and the safety rails. Two of those three are worth
shipping even if the rest of this document is never built: the permissions unique index closes a live
data-integrity hole that has nothing to do with packaging, and the throwing `alias()` closes a silent
data-poisoning path that any future refactor would walk into. Phase 0's acyclicity test then makes the
debt visible as a number in CI, which is the cheapest possible way to stop it growing.

**Phases 3–4** are the next natural slice, and phase 3 is where the day-to-day benefit lands: after it,
adding a module is one directory and one file rather than thirteen edits to nine shared files, and
`docs/new-module-checklist.md` gets much shorter. That is worth having whether or not a package is ever
extracted.

Phases 5–9 are the cycle-breaking proper, in strict order of leverage per file touched — and phase 6
alone kills seven of the fifteen SCC cycles by moving a reference table that was never accounting.

One honest note on where this ends. **Nothing here produces a package**, and that is deliberate: the
question "should these be packages" is much easier to answer from a codebase whose graph is acyclic and
whose registries are already decomposed, and much of the value — enforced boundaries, a shorter
checklist, three pieces of debt paid — arrives without ever answering it.
