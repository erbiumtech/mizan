# Module system: plan

> **Built.** All seven phases are implemented across 16 commits (`afb4b48`…`1e1825e`),
> 565 tests passing. Read **§13 As built** first — it records where reality
> differed from this plan, including one finding that contradicts §7.

Two things, decided:

1. **Physical modules.** Code moves into `app/Modules/{Module}/`, one service
   provider and one Filament plugin per module.
2. **Licensed per company.** A super admin grants a module to a company; that
   company's Administrator switches it on or off within the grant.

Because modules are sold, a disabled module is a security boundary, not a UI
preference.

## 1. What the physical move does and does not buy

It buys real structure: a module's resources, models, services, policies, routes
and tests sit in one directory, ownership is obvious, and a module could later be
extracted into a package.

It does **not** buy enforcement, and this is the most common misreading. Filament
registers panel plugins and generates resource routes at **boot**; the current
company is resolved **per request**, from the `{tenant}` route segment. One
deployment serves every company, so plugins cannot be registered conditionally
per company — and `route:cache` would bake in whichever company's state existed
at cache time. **Every enforcement point in §7 is still required, unchanged.**
Directory layout and runtime gating are independent problems.

Scope, measured rather than estimated: 246 files under `app/Filament`, 39 models,
33 policies, 27 services, 11 controllers, 93 tests — roughly 370 files touched,
every namespace rewritten. It invalidates open diffs (including the current
`fet-account-register-edit` work and the live worktree) and moves `git blame` one
commit down for the whole app. Do it on a clean tree, with a short merge freeze,
module by module — never as one commit.

## 2. Target layout

```
app/Modules/Accounting/
├── AccountingServiceProvider.php     # routes, commands, config, views, policies
├── AccountingPlugin.php              # Filament: discovers the three dirs below
├── Filament/
│   ├── Resources/                    # Accounts/, JournalEntries/, …
│   ├── Pages/                        # TrialBalance, ProfitAndLoss, …
│   └── Widgets/
├── Models/
├── Policies/
├── Services/
├── Http/Controllers/
├── routes/{web.php,api.php,console.php}
├── config/accounting.php
└── tests/{Feature,Unit}/
```

`App\` already maps to `app/` in composer's PSR-4, so
`app/Modules/Accounting/Models/Account.php` is `App\Modules\Accounting\Models\Account`
with **no composer change** — only `composer dump-autoload`. A separate
`Modules\` root would need an autoload entry and buys nothing; stay under `App\`.

### What stays put

| Stays | Why |
|---|---|
| `database/migrations/{landlord,tenant}` | The landlord/tenant split is orthogonal to modules and the paths are hardcoded in 7 places (`TenantMigrations::PATH`, `CompanyProvisioner`, five tests). Splitting them per module breaks `migrate --path` and buys nothing. |
| `database/factories` | Only 3 files; moving them breaks Laravel's factory-name guess for no gain. |
| `resources/views/filament/pages/*` | 9 pages point at `filament.pages.*` blade paths. Per-module view namespaces are possible later; not part of the move. |
| `app/Support`, `app/Multitenancy`, `app/Providers` | Genuinely shared infrastructure, not module-owned. |

## 3. Module map

Each row is a directory under `app/Modules/`.

| Module | Resources | Pages | Widgets | Other |
|---|---|---|---|---|
| **Core** *(never disableable, never unlicensed)* | User, Role, Permission, Company, TableView, CustomField, ActivityLog, Comment, FiscalYear | CompanySettings, Modules, Dashboard | — | auth, tenancy |
| **Employees** | Employee, EmployeeChangeRequest, EmployeeSetting | — | — | `/api/my-profile` |
| **Payroll** | Payslip, SalarySlab, AnnualTax | SalaryBankFile, FbrTaxFile | PayrollByEmployeeChart | `/api/my-payslips`, PayrollPostingService |
| **Accounting** | Account, JournalEntry, JournalEntryLine, TransactionType, Bank, CompanyBankAccount, Beneficiary, Payment, FixedAsset, BankStatement, BankStatementLine | AccountRegister, TrialBalance, ProfitAndLoss, GnuCashImport, PettyCashBook, BankPaymentFile | AccountBalancesOverview, CashFlowChart, OperationsOverview | `/api/reports/*`, `/api/accounts/*`, `/reports/*` |
| **Invoicing** | Contact, Invoice, InvoiceLine | — | — | — |
| **Inventory** | Product, StockMovement | — | — | — |
| **Projects** | Project | — | MyProjectsOverview, EnvironmentHealthOverview, EnvironmentIncidentsTable, CertificateExpiryTable | `/status/{company}/{token}`, `projects:check-health`, `projects:check-certificates`, prune schedule |
| **MPR** | MPR | — | — | `/api/my-mprs/*` |

Placements settled: **FiscalYear → Core** (Payroll and Accounting both use it,
neither owns it); **Comment / ActivityLog → Core** (audit surfaces on other
modules' records); **Contact → Invoicing** (checked — only `Invoice` and its own
resource reference it, so not shared); **PettyCashBook → Accounting**;
**MPR → its own module**.

Core is a module directory like any other, but it is always licensed, always
enabled, and its toggle is absent rather than merely disabled.

## 4. The five things that break silently

This is the real cost of the physical move, and none of it is caught by the type
checker: **class names are stored as strings in customer data.** Move the class
and the stored string points at nothing.

| Where | Column / usage | Blast radius |
|---|---|---|
| `comments` (tenant) | `commentable_type` | Every comment ever written |
| `payments` (tenant) | `payable_type` — Employee or Beneficiary | Every payment |
| `activity_log` (landlord) | `subject_type` | The whole audit trail |
| `custom_fields` (tenant) | `model_type` — migration literally says *"e.g. `App\Models\Contact`"* | Every custom field definition and value |
| `table_views` (landlord) | `resource` — the Filament resource key | Every saved table view, for every user |

Plus three code-level breakages:

- **Policy auto-discovery dies.** Laravel guesses `App\Models\X` →
  `App\Policies\XPolicy`. Once models live in `App\Modules\…\Models`, the guess
  never resolves and **every policy must be registered explicitly**. This exact
  failure has already happened here once — see the comment at
  `AppServiceProvider.php:55`: the MPR policy was not found on Linux and *"the
  resource is open to everyone"*. Doing this to 33 policies at once, silently, is
  the single worst outcome available in this project.
- **String class references in config and schedules.** `config/auth.php`
  (`App\Models\User`), `config/multitenancy.php` (`tenant_model`),
  `config/activitylog.php` (`activity_model`), and `routes/console.php`, which
  schedules `model:prune --model=App\Models\ProjectEnvironmentCheck` as a
  **string argument** — no IDE rename or static analyser will catch that one, and
  it fails at 00:00 in a queue worker, not in CI.
- **Nothing enforces a morph map today.** `rg enforceMorphMap` returns nothing,
  which is why the four `*_type` columns above hold raw FQCNs.

### Mitigation: enforce a morph map *before* moving anything

```php
// Phase 0, before a single file moves.
Relation::enforceMorphMap([
    // Aliases are the *legacy* FQCNs, so existing rows keep resolving and new
    // rows are written identically. No tenant-data migration, no per-database
    // UPDATE, fully reversible.
    'App\Models\Employee' => \App\Modules\Employees\Models\Employee::class,
    'App\Models\Payment'  => \App\Modules\Accounting\Models\Payment::class,
    // … one line per model with a polymorphic or stored reference
]);
```

Ugly aliases, correct behaviour: old and new rows agree, and nothing has to be
rewritten across N tenant databases. Normalising to short keys (`employee`,
`payment`) is a separate, optional pass later.

`custom_fields.model_type` and `table_views.resource` are read by app code rather
than Eloquent, so route them through the same map (`Relation::getMorphedModel()`)
instead of comparing FQCNs directly. One mapping governs everything.

`enforceMorphMap` also throws on any model *not* in the map — which turns "we
forgot one" from silent data corruption into a loud failure. That is the point.

## 5. Module provider and plugin

```php
final class AccountingPlugin implements Plugin
{
    public function getId(): string { return 'accounting'; }

    public function register(Panel $panel): void
    {
        // Registration is unconditional — see §1. Gating happens in canAccess().
        $panel->discoverResources(in: __DIR__.'/Filament/Resources', for: __NAMESPACE__.'\Filament\Resources')
              ->discoverPages(in: __DIR__.'/Filament/Pages',        for: __NAMESPACE__.'\Filament\Pages')
              ->discoverWidgets(in: __DIR__.'/Filament/Widgets',    for: __NAMESPACE__.'\Filament\Widgets');
    }

    public function boot(Panel $panel): void {}
}
```

`AdminPanelProvider` replaces its three `discoverResources`/`Pages`/`Widgets`
calls with `->plugins(Modules::plugins())`, driven off the registry.

The service provider carries everything Filament does not:

```php
final class AccountingServiceProvider extends ServiceProvider
{
    /** Explicit because auto-discovery cannot guess module namespaces (§4). */
    private const POLICIES = [Account::class => AccountPolicy::class, /* … */];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/accounting.php', 'accounting');
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) { Gate::policy($model, $policy); }

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        $this->commands([CheckPayrollAccounts::class]);
    }
}
```

Config keys stay as they are (`accounting.*`, `projects.*`, `petty_cash.*`) —
`mergeConfigFrom` preserves them, so no call site changes and a published
`config/accounting.php` still overrides the module's defaults.

## 6. Licensing state

**Landlord database, two flags per company per module.**

| Flag | Who sets it | Meaning |
|---|---|---|
| `licensed` | Super admin only | The company has bought this module. |
| `enabled` | Company Administrator | The company wants it visible right now. |

`Modules::enabled($module)` returns `licensed && enabled`. Every hook in §7 checks
that effective state, never one flag alone.

```php
// database/migrations/landlord/…_create_company_modules_table.php
$table->foreignId('company_id')->constrained()->cascadeOnDelete();
$table->string('module');
$table->boolean('licensed')->default(false);
$table->boolean('enabled')->default(false);
$table->unique(['company_id', 'module']);
```

`App\Models\CompanyModule` needs no `$connection`: the default connection *is*
the landlord database (`config/database.php:45` — only `tenant` is switched per
request), same as `Company`, `User` and the permission tables. That is why the
landlord DB beat `TenantSettings` here: `Modules::enabled()` resolves from a
company id, so commands and queued jobs — where no tenant is current — work
without the `Company::current()` dance that catches out the tenant filesystem and
the landlord/tenant query split.

A missing row means "use the shipped default", so a module added in a later
release appears for existing companies with its default rather than being
silently absent.

`config/modules.php` is registry only, never written to:

```php
'accounting' => [
    'label' => 'Accounting',
    'requires' => [],
    'licensed_by_default' => false,
    'provider' => \App\Modules\Accounting\AccountingServiceProvider::class,
    'plugin'   => \App\Modules\Accounting\AccountingPlugin::class,
],
```

Cache the per-company map once per request so the many `canAccess()` calls cost
one landlord query.

**Starting state.** Existing companies: every module licensed and enabled —
nobody loses a feature the day this ships. New companies: **Core only**; a super
admin grants the rest. Revoking a licence **keeps** the company's `enabled`
preference, so a re-grant restores it the way they had it; that flag is their
setting, not ours to reset.

## 7. Dependencies

```
Core
 ├── Employees
 │    ├── Payroll   (needs Employees; posts through Accounting)
 │    ├── Projects  (Project.manager_employee_id → Employee)
 │    └── MPR       (keys on user_id, not employee — soft)
 └── Accounting
      ├── Invoicing (issues journal entries)
      └── Inventory (COGS / stock valuation)
```

Enforced twice, deliberately:

1. **Hard, at toggle time.** Cannot enable a module without its dependencies;
   disabling offers to disable dependents or refuses and lists them. Report all
   blockers at once, the way `FiscalYearClosingService::blockers()` does.
2. **Soft, at runtime.** Licences get revoked out from under live data, so
   cross-module *writes* degrade instead of throwing. `PayrollPostingService`
   skips posting when Accounting is off and must not fail payslip creation; same
   for `InvoiceService` → journal entries. Grep `JournalEntryService` for the
   call sites.

Rule 1 makes the inconsistent state hard to reach; rule 2 makes it survivable.

Physical structure adds a check the registry could not: a module directory must
not `use` another module's namespace except through its declared `requires`. That
is a lint rule (§10.3) and it is the one genuinely new benefit of the move.

## 8. Enforcement points

Miss one and a disabled module is still reachable. All of these test the
effective state from §6.

| # | Surface | Hook | Notes |
|---|---|---|---|
| 1 | Resources | `canAccess()` | via a `BelongsToModule` trait |
| 2 | Pages | `canAccess()` | same trait |
| 3 | Widgets | `canView()` | separate hook |
| 4 | **Navigation** | — | **automatic**: `HasNavigation:50` and `Pages\Page:130` check `canAccess()` |
| 5 | **Global search** | — | **automatic**: `canGloballySearch()` calls `canAccess()` |
| 6 | **⌘K palette** | — | **automatic**: `ResourceProvider`/`PageProvider` gate on `canAccess()` |
| 7 | Authorization | `Gate::before`, **ahead of the admin bypass** | see below |
| 8 | Web routes | middleware `module:accounting` | `/reports/*`, `/status/*` — direct URLs bypass 1–3 |
| 9 | API routes | same middleware | applied in each module's `routes/api.php` |
| 10 | Scheduled work | guard in the module's `routes/console.php` | `projects:check-health` skips companies with Projects off |
| 11 | Permission lists | filter `RoleForm::groupedPermissions()` | a disabled module's permissions leave the Roles form |
| 12 | Cross-module services | explicit guards | §7 rule 2 |
| 13 | Migrations & data | **no change** | disabling hides features; it never drops tables or deletes rows |

With the module owning its directory, 1–3 collapse to one line per module: the
trait reads the module from the class namespace, so a new resource dropped into
`app/Modules/Payroll/Filament/Resources` is gated by where it sits. That is worth
having — but it is a convenience, not the enforcement itself (§1).

### 7 — the authorization bypass must be fixed first

`AppServiceProvider.php:66` short-circuits authorization:

```php
Gate::before(function ($user, $ability) {
    if ($user->isSuperAdmin()) return true;
    if ($user->hasRole('Administrator') && $ability !== 'create') return true;
});
```

A module check inside a policy therefore never runs for the two roles most likely
to hit it. The check goes **inside `Gate::before`, above those lines, returning a
hard `false`.** Consequently **a super admin does not bypass module checks** — a
module the company has not bought is not a permission question, and showing a
super admin a UI the customer does not have is worse than switching a licence on
to look at it.

### 11 — filtering permissions without deleting them

Permissions live in the landlord `permissions` table grouped by model name
(`User`, `Employee`, `MPR`, …). A module owns the groups matching the models in
its directory, so the map is derived, not maintained.

**One trap, and it destroys data.** `SyncsGroupedPermissions::selectedPermissionIds()`
collects ids from the groups `RoleForm::groupedPermissions()` returns, then calls
`permissions()->sync()`. Filter that method by module and every hidden group's
permissions are missing from the sync array — so saving **any** role detaches the
disabled modules' permissions from it, and a later re-enable finds every role
stripped. Preserve hidden-group ids explicitly, or stop using `sync()` here.
Test it before writing the filter.

## 9. Admin UI — two surfaces

**Landlord: licensing.** A Modules section on the Company edit page, super admins
only. One toggle per module = a licence grant.

**Tenant: activation.** `app/Modules/Core/Filament/Pages/Modules.php`, Settings
group, `Administrator` only (mirror `CompanySettings::canAccess()`).

- Lists **only licensed** modules. An unlicensed module is not shown as a locked
  toggle — it is not shown at all, since the customer cannot act on it.
- Core shown but locked. **There must be no way to disable the module containing
  the Modules page itself, or Roles/Users.**
- Dependencies enforced in the form: enabling pulls in requirements, disabling
  warns about dependents *before* the save — same lesson as the fiscal-year close
  modal, list blockers up front rather than failing the attempt.
- Record count per module, so an admin sees what they are hiding ("Invoicing —
  412 invoices"). Hiding existing data is fine; doing it unknowingly is not.
- Both surfaces write to the activity log. "Who turned Payroll off and when" is
  where every such incident starts.

## 10. Testing

The move needs tests the registry approach did not, because its failure mode is
silence rather than an exception.

1. **Every model is in the morph map.** `enforceMorphMap` makes an omission throw,
   so assert the map covers every model class in every module directory. Run this
   from phase 0 onward.
2. **Every model has a policy registered** (`Gate::getPolicyFor()` non-null).
   This is the MPR hole, generalised — with auto-discovery gone, it is the only
   thing standing between a move and an open resource.
3. **No cross-module namespace use outside `requires`.** Static scan of `use`
   statements per module directory.
4. **Every resource, page and widget resolves to exactly one module** — one added
   without a module fails CI rather than shipping ungated.
5. **Stored-string round trip**: a comment, a payment, an activity-log entry and a
   custom field written before the move still resolve after it (fixture rows with
   legacy `App\Models\…` values).
6. Effective state is the AND: licensed-not-enabled and enabled-not-licensed both
   deny.
7. Per module: enabled → resources `canAccess()`; disabled → they do not, routes
   404/403, widgets hidden, API endpoints closed.
8. Core cannot be disabled or unlicensed.
9. A **super admin is denied** a disabled module (the `Gate::before` ordering).
10. Dependency rules: cannot enable Invoicing with Accounting off; disabling
    Accounting reports Invoicing and Inventory as dependents.
11. **Saving a role with a module disabled does not detach that module's
    permissions** (§8.11).
12. Revoke → re-grant restores the company's previous `enabled` state.
13. Disable → re-enable leaves data intact (row counts equal).
14. Cross-module degradation: Accounting off, payslip still created, no journal
    entry posted.

## 11. Phasing

Enforcement lands before the admin UI, because a licence is a commercial
boundary. The moves come last per module and each is its own commit.

| Phase | Work | Risk |
|---|---|---|
| **0** | `config/modules.php` registry, `company_modules` table + model, `App\Support\Modules`, backfill existing companies to all-on. **`Relation::enforceMorphMap()` with legacy FQCN aliases**, tests 10.1–10.2. No files move, no behaviour change. | none |
| **1** | `BelongsToModule` on all resources/pages + widget `canView()`. Covers nav, search, palette. | low |
| **2** | `Gate::before` module deny; route middleware (web + API); scheduled-work guards. **A module becomes unreachable rather than merely hidden.** | medium |
| **3** | Landlord licensing section; tenant Modules page with dependency rules. | low |
| **4** | Permission-list filtering (sync fix first); cross-module service guards. | medium — touches payroll/invoice posting and role saving |
| **5** | **The physical move, one module per commit** (recipe below). | high |
| **6** | Cross-module namespace lint (10.3); optionally normalise morph aliases to short keys. | low |

Nothing may be sold as a module until phase 2 is in production. Phase 5 is
independent of 0–4 and can slip without blocking the product.

### Phase 5 recipe, per module

Smallest first, so the mechanics are proven on one resource before touching
eleven: **MPR → Inventory → Invoicing → Employees → Payroll → Projects →
Accounting → Core.**

1. `git mv` the files (use `git mv`, not copy+delete — it keeps rename detection
   and therefore `git blame`).
2. Rewrite namespaces and imports repo-wide for the moved classes.
3. Add the module's `ServiceProvider` and `Plugin`; register in
   `config/modules.php`.
4. Register every policy explicitly; run test 10.2.
5. Move the module's routes, commands and config; check `routes/console.php` for
   **string** class references (`model:prune --model=…`).
6. `composer dump-autoload`, full test suite, and grep the codebase for the old
   FQCNs — including in `config/`, blade views and string literals.
7. Commit. Nothing else in that commit.

Core moves last because `config/auth.php`, `config/multitenancy.php`
(`tenant_model`) and `config/activitylog.php` (`activity_model`) all name its
classes as strings, and a mistake there takes down login for every company.

## 12. Risks

- **Silent authorization holes.** 33 policies losing auto-discovery at once, with
  the MPR incident as precedent. Test 10.2 is not optional.
- **Stored class strings** (§4). Comments, payments, activity log, custom fields
  and saved table views all hold FQCNs. The morph map must land in phase 0,
  before any file moves.
- **Locking a company out.** Core is immutable, always licensed, and owns the
  Modules page. Asserted explicitly (10.8).
- **Role permission loss** (§8.11) — the most likely way this project destroys
  customer data.
- **Hidden-but-live data before phase 2.** With phase 1 alone, Payroll "off" does
  not stop a scheduled job or an API client writing payslips. Do not treat a
  toggle as a licence boundary, or demo it as one, until phase 2 ships.
- **Mobile clients.** `/api/my-payslips`, `/api/my-mprs/*` and `/api/reports/*`
  begin returning 403 where they returned data. Confirm the shipped app builds
  handle that before revoking any licence in production — the first company to
  lose a module should not be the test.
- **Merge pain.** Phase 5 rewrites every namespace. Any branch open across it will
  conflict in every file it touches. Land or abandon in-flight work first.
- **The status page keeps its own switch.** `/status/{company}/{token}` is
  unauthenticated and already gated by a per-company setting. Projects being
  licensed and enabled is a *second* condition, not a replacement — both must be
  true for the page to answer.

## 13. As built

Seven phases, 16 commits, 565 tests. Where this plan was wrong or incomplete:

### The dependency graph in §7 does not match the code

The boundary lint (phase 6) found **88 imports across 15 module pairs** that no
`requires` entry declares:

```
accounting -> employees, payroll, invoicing, inventory
core       -> accounting, payroll, invoicing, inventory, employees, mpr
employees  -> projects, accounting
invoicing  -> inventory
mpr        -> employees
payroll    -> accounting        (by design, guarded)
```

**Accounting is not the base of the graph.** `Payment.payable` may be an
Employee, `PaymentService` settles payslips, `OperationsOverview` aggregates
every module, `RegisterEntryService` reaches into invoices and stock — so
Accounting sits in a *cycle* with Invoicing and Inventory, which declare it as a
requirement. Core reaches into six modules because it owns the surfaces that
enumerate domain models (the CustomField list, payroll-account validation,
the fiscal-year close action, `User::mprs()`).

These are recorded as `KNOWN_COUPLINGS` in `ModuleBoundaryTest`, not converted
into `requires`. **An import and a licence dependency are different things**:
every module is always deployed, so an import is harmless while the other module
is unlicensed *provided the call site degrades*. Declaring the requirement to
satisfy a lint would make Payroll unsellable without Accounting — exactly what
the guarded degradation exists to avoid. The list is asserted in both directions,
so it can neither grow nor keep stale entries.

Breaking the debt needs interfaces, events or a registry. Until then the graph
cannot get worse without someone deciding to make it worse.

### Six stored-class-string columns, not five

§4 missed `journal_entries.source_type` and `stock_movements.source_type`, and
misjudged the others: these are plain column writes, so `enforceMorphMap()` does
not cover them at all. Every such column now normalises to the alias **in a
mutator**, and reads go through `JournalEntry::forSource()` or
`whereMorphedTo()` — normalising at the boundary is what makes the guarantee hold
for callers nobody has written yet.

### `enabled` is a three-state column

A licence grant must light the module up, and a re-grant must restore the
company's own choice. Those need `NULL` (never chosen) distinct from `false`
(chosen off); with a plain boolean one of the two behaviours has to be wrong.

### Requirements propagate at read time

§7 enforced dependencies only in the activation form, but a licence revoke does
not go through that form — revoking Accounting left Invoicing enabled and
reachable, free to post journal entries into a module the company no longer had.
`Modules::enabledFor()` now checks requirements recursively.

### Two ordering problems the plan did not anticipate

- `spatie/laravel-permission` registers its **own** `Gate::before` during boot,
  returning true as soon as the user holds the permission. Before-callbacks run
  in registration order, so the module deny had to move into `register()` — a
  boot-time registration never runs for exactly the users it must stop.
- The dependency cascade cannot work from the desired state alone. Enabling
  Invoicing while Accounting is off and disabling Accounting while Invoicing is
  on produce the identical end state and must resolve in opposite directions.

### What the move actually broke

Namespace moves break references in **both** directions, and none of it is a
compile error: the moved file's references to its old neighbours, and every file
left behind that referred to the moved class the same way. Also: Laravel resolves
factory names *and* factory-to-model names through the model namespace; commands
are auto-discovered only from `app/Console/Commands`; and `App\Models\Company` is
a prefix of `App\Models\CompanyModule`.

The worst one was silent and green. `ModuleCoverageTest` discovered classes by
scanning `app/Models` and `app/Filament` — once everything had moved, both were
empty, so every invariant passed over nothing. Discovery now enumerates
`app/Modules/*`, and a test asserts the discovery itself finds classes.

### Still open

- **MPR imports Employee** (`belongsTo`) but declares no requirement, so it can
  be licensed without Employees. Either declare it or confirm the relation is
  optional at runtime.
- **The API has no tenant resolution** (`multitenancy.tenant_finder` is null), so
  route middleware falls back to the caller's company membership and a
  multi-company user's request is not attributable to one company.
- **Mobile clients** now receive 403 where those endpoints always answered.
