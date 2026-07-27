# Projects Listing — Implementation Plan

**Status:** Implemented (both phases, 2026-07-27) — see [Implementation notes](#implementation-notes) for the three places the build diverged from this plan
**Created:** 2026-07-27
**Updated:** 2026-07-27 — per-project environments (Prod / Qual / Dev) with plain-text credentials by decision ([§4](#4-environments--credentials)); primary + secondary manager ([§5](#5-primary--secondary-manager)); health checks & uptime ([§6](#6-health-checks--uptime)); open to all employees except delete ([§7](#7-permissions)); phase 2 adds alerting, certificates, status page and dashboard widgets ([§10](#10-phase-2--schema-additions) onward)

Goal: a **Project** entity with employees assignable to it, a **primary and secondary manager**, and its **deployment environments** (Prod, Qual, Dev) recorded against it — URL, login details, and a **health/uptime status** — all surfaced in a Filament listing with create/edit. HR/delivery-flavoured: no client, invoice or ledger links. Team assignments are managed from the project page and shown read-only on the employee page (§8).

**Two phases.** §1–§9 are the feature: projects, teams, environments, credentials, scheduled health checks with uptime. §10–§16 turn that into an on-call tool: alerting with flap suppression, certificate expiry, content assertions, per-environment intervals, a public status page, and dashboard widgets. Phase 2 is about the same size as phase 1 — see [Delivery order](#delivery-order) for why it is worth waiting a week between them.

---

## 1. Migrations — `database/migrations/tenant/2026_07_27_140000_create_projects_tables.php`

```
projects
  id
  code                   string, unique     // e.g. PRJ-ERP-01
  name                   string
  description            text, nullable
  status                 string, default 'planned'  // planned|active|on_hold|completed|cancelled
  manager_employee_id    FK employees, nullable, nullOnDelete   // primary manager
  secondary_employee_id  FK employees, nullable, nullOnDelete   // secondary manager / stand-in
  start_date             date, nullable
  end_date               date, nullable
  timestamps
  index('status')

project_employee
  id
  project_id      FK projects, cascadeOnDelete
  employee_id     FK employees, cascadeOnDelete
  role            string, nullable        // "Backend lead"
  allocation_pct  decimal(5,2), nullable  // 0–100
  from_date       date
  to_date         date, nullable
  timestamps
  unique(['project_id','employee_id','from_date'])

project_environments
  id
  project_id      FK projects, cascadeOnDelete
  kind            string                  // prod|qual|dev
  url             string, nullable
  username        string, nullable
  password        string, nullable        // plain text — see §4
  notes           text, nullable          // VPN requirement, bastion host, seeded test users…
  timestamps
  unique(['project_id','kind'])           // one row per environment per project

  -- health check state (§6); denormalised "latest result" so the listing
  -- needs no aggregate over the history table
  is_monitored       boolean, default true
  health_status      string, nullable         // up|down|unknown  (null until first check)
  health_code        unsignedSmallInteger, nullable   // last HTTP status
  health_latency_ms  unsignedInteger, nullable
  health_error       string, nullable         // truncated to 255
  health_checked_at  timestamp, nullable
```

Second migration — `database/migrations/tenant/2026_07_27_140100_create_project_environment_checks_table.php`:

```
project_environment_checks
  id
  project_environment_id  FK project_environments, cascadeOnDelete
  checked_at              timestamp
  is_up                   boolean
  status_code             unsignedSmallInteger, nullable
  latency_ms              unsignedInteger, nullable
  error                   string, nullable
  index(['project_environment_id','checked_at'])
```

The history table is what makes "uptime" mean anything — a single last-status column can only say up or down *right now*. Volume is the thing to size deliberately: at a 5-minute interval, one environment writes 288 rows/day, so 20 projects × 3 environments ≈ 17k rows/day, ~520k/month **per company**. With the 30-day pruning in §6 that settles at roughly half a million rows — fine for MySQL on an indexed FK, but the reason retention is a config value and not an afterthought.

All four tables live under `database/migrations/tenant`, so they auto-load in the test suite (`AppServiceProvider::boot`) and run per company. Apply to existing tenants with:

```
php artisan tenants:artisan "migrate --force"
```

Notes:
- The pivot carries `from_date` in its unique key so an employee can be re-assigned to the same project in a later stint.
- `unique(['project_id','kind'])` keeps the basic slice to exactly one Prod / Qual / Dev row each. Multi-region ("EU Prod", "US Prod") would need a `label` column added to the key — deliberately deferred.
- Primary and secondary are **columns on `projects`**, not pivot flags: both roles are singular, so two FKs enforce "one of each" by construction instead of needing an app-level "only one manager" check. The pivot's free-text `role` stays for the wider team's job titles ("Backend lead", "QA").
- Neither designation requires a `project_employee` row — a manager who isn't a listed team member still shows as manager.
- `manager_employee_id` mirrors the naming of `employees.manager_id` (added 2026-07-26), so the column reads the same way across the schema.

## 2. Models

**`app/Models/Project.php`**
- Extends `TenantModel`; `use Auditable, HasCustomFields` — same as `Product` / `Invoice`.
- Status constants (`STATUS_PLANNED`, `STATUS_ACTIVE`, `STATUS_ON_HOLD`, `STATUS_COMPLETED`, `STATUS_CANCELLED`).
- Casts: `start_date`, `end_date` → `date`.
- `employees()` — `belongsToMany(Employee::class)->withPivot(['role','allocation_pct','from_date','to_date'])->withTimestamps()`.
- `currentEmployees()` — the same relation constrained to open stints (`to_date` null or `>=` today), which is what the listing's "Team" count and the relation manager's default filter use.
- `manager()` / `secondaryManager()` — `belongsTo(Employee::class, 'manager_employee_id' | 'secondary_employee_id')`.
- `environments()` — `hasMany(ProjectEnvironment::class)`; plus `environment(string $kind)` convenience accessor.
- `scopeActive()` — status `active`.
- `managers()` helper returning the primary and secondary as a filtered collection, for the places that just need "who runs this".
- Guard: `secondary_employee_id` may not equal `manager_employee_id` (§5).
- Inverse `projects()` (with the same `withPivot`), `currentProjects()`, `managedProjects()` and `secondaryProjects()` added to `app/Models/Employee.php`.
- `assign(Employee $employee, array $pivot)` / `endAssignment(Employee $employee, ?string $on = null)` — the two operations the assignment UI calls, so the "no duplicate open stint" rule and the `to_date` default live in the model rather than in a Filament closure.

**`app/Models/ProjectEnvironment.php`**
- Extends `TenantModel`, `use Auditable`.
- Kind constants `KIND_PROD` / `KIND_QUAL` / `KIND_DEV` and a `KINDS` label map (`prod => 'Production'`, `qual => 'Qualification'`, `dev => 'Development'`).
- Health constants `HEALTH_UP` / `HEALTH_DOWN` / `HEALTH_UNKNOWN`; `health_checked_at` cast to `datetime`, `is_monitored` to `boolean`.
- No cast on `password` — stored and read as plain text.
- `checks()` — `hasMany(ProjectEnvironmentCheck::class)`.
- `isMonitorable(): bool` — `is_monitored && filled($url)`; the query scope `scopeMonitorable()` is what the scheduler selects on.
- `uptimePercent(int $days = 30): ?float` — `checks` in window, `avg(is_up) * 100`, `null` when there is no history yet (never render "0%" for "no data").
- `recordCheck(bool $isUp, ?int $code, ?int $latencyMs, ?string $error): void` — writes one history row and updates the denormalised `health_*` columns in one transaction.
- **Audit-log override:** `Auditable` logs every dirty fillable attribute, so without an override each password change writes a permanent copy of the old and new value into `activity_log.properties`. Use `LogOptions::defaults()->logFillable()->logExcept(['password'])->logOnlyDirty()->…` — and the `health_*` columns must be excluded too, or every 5-minute check writes an activity row per environment and buries the log. This does not affect visibility anywhere in the UI.
- `belongsTo(Project::class)`.

**`app/Models/ProjectEnvironmentCheck.php`**
- Extends `TenantModel`. **No `Auditable`** — it is itself an append-only log; auditing it would double the write volume for nothing.
- `use Illuminate\Database\Eloquent\Prunable` with `prunable()` returning `where('checked_at', '<', now()->subDays(config('projects.health.retention_days')))`. Retention then runs via `model:prune` (§6) instead of a hand-written command.
- Casts `checked_at` → `datetime`, `is_up` → `boolean`.

## 3. Policies

`app/Policies/ProjectPolicy.php` — auto-discovered (`App\Models\X` → `App\Policies\XPolicy`). Checks `ProjectView` / `ProjectCreate` / `ProjectUpdate` / `ProjectDelete`, with `delete()` refusing a project that already has assignments — the guard style `ProductPolicy` uses for movement history.

Environments have no policy of their own: they are managed inline on the project form, so project rights govern them. The one exception is the on-demand health check, gated by its own permission (§7).

## 4. Environments & credentials

**Decision:** environment passwords are stored **unencrypted** and are **visible in the UI** to anyone who can view a project. No masking, no reveal gate, no separate credential permission. Rationale: this is an internal team tool, and the point of the field is quick copy-paste access to shared Prod/Qual/Dev logins.

Consequences to be aware of, recorded so this reads as a decision rather than an oversight:
- A database dump, a read replica, or a backup file exposes every environment credential in clear text — no `APP_KEY` needed.
- Every employee sees production logins, since the Employee role holds `ProjectView` (§7) and projects are not row-scoped.
- If the field is added to a table column or export, credentials travel into CSVs and saved views.

Two low-cost habits keep the worst edges off without touching visibility:
- `logExcept(['password'])` on the model, so the audit log doesn't accumulate a permanent history of rotated credentials (§2).
- `password` excluded from `getGloballySearchableAttributes()` — searching by credential is meaningless and would surface secrets in the palette's result subtitles.

The health checker never sends these credentials: it makes an unauthenticated request and treats a `401`/`403` as "reachable" (§6). If an environment needs auth to answer at all, point the check at a public health path instead of wiring the password into the request.

If this ever needs tightening (external collaborators, an audit, a client requirement), the cheapest upgrade path is a `'password' => 'encrypted'` cast plus a `ProjectCredentialView` permission gating the reveal — a column-type widening (`string` → `text`) and one migration to re-encrypt existing rows.

## 5. Primary & secondary manager

Each project names a **primary manager** (`manager_employee_id`) and a **secondary manager** (`secondary_employee_id`). Both are plain designations — two people recorded as running the project, the secondary being the one to go to when the primary isn't available.

- **No leave tracking, no automatic hand-over.** The app does not know when anyone is away and does not compute who is "currently" responsible; it shows both names and lets people use the right one. Deliberate: there is no dated leave data in this codebase (`payslips.leaves_taken` is a monthly count used for payroll deduction, with no dates), and adding leave records to drive an automatic switch was explicitly ruled out of this feature.
- **Secondary must differ from primary** — enforced on the form with `->rules(['different:manager_employee_id'])`, plus a model-level guard so a seeder or console script can't set both to the same person.
- **Both optional.** A project may have neither, or only a primary. Nothing depends on them being set, so no backfill is needed for existing rows and no validation forces a secondary.
- **Deleting an employee** nulls the designation (`nullOnDelete`) rather than blocking the delete or cascading the project away — a project outliving its manager is normal.
- Both are shown wherever the project is: as columns in the listing, as fields on the form, and as a small Responsibility section on the view page.

If automatic cover is ever wanted (secondary displayed as responsible while the primary is on leave), it needs a dated leave source first; that is noted in §17 as a separate piece of work, and nothing in this design blocks it.

## 6. Health checks & uptime

Every monitored environment URL is pinged on a schedule; the result drives a status badge in the listing and a 30-day uptime figure on the project page.

### What a check does

`app/Jobs/CheckEnvironmentHealth.php` — `ShouldQueue`, implements `Spatie\Multitenancy\Jobs\TenantAware` so the worker re-binds the right company connection before touching the DB (the package's `MakeQueueTenantAwareAction` is already configured in `config/multitenancy.php`). One job **per environment**, not one per batch, so a single hanging host can't stall everyone else's checks.

```
HEAD url  (timeout 5s, connect 3s, redirects not followed)
  → 405/501 from a server that rejects HEAD  → retry once with GET
  → 2xx / 3xx / 401 / 403                    → up      (reachable; auth-gated still counts)
  → any other status                          → down    (record the code)
  → connection error / timeout / DNS failure  → down    (record a truncated reason)
```

Then `recordCheck()` writes one history row and refreshes the denormalised `health_*` columns.

Deliberate choices:
- **`401`/`403` count as up.** Most of these URLs sit behind basic auth or an SSO wall; "the server answered" is the signal we want. A checker that reported production down because it isn't logged in would be noise, and would be worse than nothing — people stop reading a dashboard that cries wolf.
- **Redirects are not followed.** A `3xx` is answered-and-therefore-up, and following redirects would let a compromised or mistyped host bounce our server somewhere else.
- **No response body is stored** — only status, latency and a truncated error string. Nothing to leak, nothing to grow unbounded.
- **`is_monitored` per environment**, so a `localhost` dev URL that is permanently unreachable from the server can be excluded instead of sitting permanently red. Any environment with a blank URL is skipped regardless.

### Scheduling

`app/Console/Commands/CheckEnvironmentsHealth.php` — `projects:check-health`, using `Spatie\Multitenancy\Commands\Concerns\TenantAware` so it fans out over every company when run with no `--tenants` argument. It dispatches one job per `monitorable()` environment.

In `routes/console.php` (the file already schedules work, so cron is expected to be running):

```php
Schedule::command('projects:check-health')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('tenants:artisan "model:prune --model=App\\Models\\ProjectEnvironmentCheck"')
    ->daily();
```

**Operational dependency, worth stating plainly:** this needs both `schedule:run` on cron *and* a queue worker (`QUEUE_CONNECTION` defaults to `database`). If either isn't running, no check ever executes — which is exactly why `health_status` starts as `null` and renders as a grey **Unknown**, never a green tick. A monitoring feature that silently shows "healthy" when the monitor itself is dead is the one failure mode to design out.

### Config — `config/projects.php`

```php
'health' => [
    'enabled'        => env('PROJECT_HEALTH_CHECKS', true),
    'timeout'        => 5,
    'connect_timeout'=> 3,
    'user_agent'     => 'MPR-HealthCheck/1.0',
    'healthy_codes'  => [401, 403],   // in addition to 2xx/3xx
    'retention_days' => 30,
],
```

### The outbound-request question

The app server will fetch URLs that panel users type in. These URLs are *meant* to be internal — dev and qual environments behind a VPN, sometimes on private ranges — so blanket-blocking private IPs would remove most of the feature's value. What that leaves is a real, bounded capability: anyone who can add an environment can make the app server issue an HTTP request to an arbitrary host and read back whether it answered, with what status, and how fast. That is a basic internal port/host prober.

What's in the design to keep it bounded: `HEAD`/`GET` only, no redirect following, short timeouts, no response body retained, and no way to set headers or a request body.

The remaining decision is **who may trigger a check on demand**, since the scheduled runs only touch URLs already saved. The plan takes the tighter default: a dedicated `ProjectHealthCheck` permission for the "Check now" action, granted to **Manager, CEO and Administrator only** — not Accountant, not Employee (§7). Everyone still *sees* the results — scheduled checks populate them for all. Widening it is a one-line seeder change if that turns out to be too strict.

## 7. Permissions

Projects are a **company-wide shared reference**: every employee sees every project and can add or correct environment data. Only deletion and on-demand checks are privileged.

- `database/seeders/PermissionSeeder.php` — `'group' => 'Project'`: `ProjectView`, `ProjectCreate`, `ProjectUpdate`, `ProjectDelete`, `ProjectHealthCheck`.
- `database/seeders/RoleSeeder.php`:
  - **Employee role** — add `ProjectView`, `ProjectCreate`, `ProjectUpdate` alongside its existing `PayslipView` / `EmployeeSettingView` / `CommentCreate` / `CommentView`. Environments are edited inline on the project form, so `ProjectUpdate` covers them, as does naming the primary/secondary manager. **No `ProjectDelete`, no `ProjectHealthCheck`** — an employee reads health results but doesn't fire ad-hoc requests from the server (§6).
  - `ProjectView/Create/Update` → into the Accountant list, which `$managerPermissions` is built from, so Accountant/Manager/CEO all hold them explicitly rather than by accident of role ordering.
  - `ProjectHealthCheck` → into the **Manager extras** array, not the Accountant list: an accountant reads and edits projects but does not make the server fire outbound requests. Note the coupling — `$managerPermissions` derives from Accountant's permissions, so adding it to the Accountant list would grant it there, and removing it from the Accountant list would silently strip it from Manager and CEO too.
  - `ProjectDelete` → the CEO extras list only. Administrator syncs `Permission::all()` already.
- Re-seed after deploy: `php artisan tenants:artisan "db:seed --class=RoleSeeder --force"` (plus `PermissionSeeder` centrally).

Deletion is therefore CEO/Administrator-only, and `ProjectPolicy::delete()` additionally refuses projects that already have assignments (§3). Employees get no delete route at all — the `DeleteBulkAction` and any `DeleteAction` simply don't render for them, since Filament resolves both through the policy.

Note the interaction with §4: because every employee now holds `ProjectView`, **every employee can read every environment's plain-text password, production included**. That is the direct consequence of these two decisions together, and it is the intended shape here — but it is the fact to revisit first if the company ever onboards contractors or interns into the panel.

## 8. Filament resource — `app/Filament/Resources/Projects/`

Mirrors the `Products` layout. Resources are auto-discovered — no panel registration — and the new resource is picked up for free by global search and the command palette (`RecordProvider` / `ResourceProvider` both iterate `Filament::getResources()`).

**No row scoping.** Unlike `EmployeeResource` (own + downline) or `PayslipResource`, `getEloquentQuery()` applies no ownership filter — every project is visible to every user who holds `ProjectView`, which per §7 is everyone. Nothing to build; just don't add a scope here later by reflex.

| File | Contents |
| --- | --- |
| `ProjectResource.php` | `navigationGroup 'Employee'`, `Heroicon::OutlinedBriefcase`, `recordTitleAttribute 'name'`, globally searchable `code` + `name`, `getEloquentQuery()` with `withCount('employees')` and `with(['environments', 'manager.user', 'secondaryManager.user', 'customFieldValues.customField'])` — the `.user` eager loads matter because an employee's display name comes from the linked user; health state needs no extra load because it lives on the environment row |
| `Schemas/ProjectForm.php` | **Details section:** code (unique, `ignoreRecord: true`), name, description, status select, start/end dates (end `afterOrEqual` start). **Management section:** `manager_employee_id` and `secondary_employee_id` selects, `->relationship('manager' \| 'secondaryManager')` with `->searchable()->preload()`, both nullable, secondary carrying `->rules(['different:manager_employee_id'])` and helper text "stand-in for the primary manager". **Environments section:** `Repeater::make('environments')->relationship()` with kind select (the three `KINDS`, `->distinct()`), `url` (`->url()->prefix('https://')`), `username`, `password` as a plain visible `TextInput`, `notes`, and an `is_monitored` toggle (helper: "uncheck for URLs the server can't reach, e.g. localhost"); `->defaultItems(3)` pre-filled Prod/Qual/Dev on create, `->collapsed()`, `->itemLabel(fn ($state) => KINDS[$state['kind']] ?? 'Environment')`. Then `...CustomFieldsSchema::form(Project::class)` |
| `Tables/ProjectsTable.php` | `->header(view('filament.tables.saved-views-bar'))`; columns code, name, status badge, **"Manager"** and **"Secondary"** (both `->searchable()` through the relation, secondary toggleable), **"Environments"** — one badge per configured kind, coloured by health (`success` up / `danger` down / `gray` unknown) with a tooltip carrying last status code, latency and `health_checked_at->diffForHumans()`, start/end dates, "Team" from `employees_count`, `created_at` toggled off; filters: `SelectFilter` on status, manager filter for "my projects", and a **health filter** (`any environment down` / `never checked`) — the two views someone actually opens this page for; `Group::make('status')`; row actions `EditAction` + an **Open** `ActionGroup` linking to each environment URL (`shouldOpenInNewTab: true`); `DeleteBulkAction` |
| `Pages/ViewProject.php` | Infolist: **Responsibility** (primary + secondary manager) and **Environments** — per environment, kind, URL as a link, username and password as `->copyable()` entries, health badge, last checked, latency, and **30-day uptime** from `uptimePercent()` rendered as "—" when there's no history. Per-environment **Check now** `Action` (visible with `ProjectHealthCheck`) that runs the check synchronously and reports the outcome in a notification |
| `Pages/ListProjects.php` | `use HasSavedViews`; `CreateAction` + `saveViewAction()`; preset views: Active first / Name A→Z / Newest / **Unhealthy first** |
| `Pages/CreateProject.php`, `Pages/EditProject.php` | Standard |
| `RelationManagers/EmployeesRelationManager.php` | The assignment UI — see below. Registered in `ProjectResource::getRelations()`, so it renders as a tab on both the edit and view pages |

### Assignment management — `RelationManagers/EmployeesRelationManager.php`

`protected static string $relationship = 'employees'`, title "Team". Columns: employee (via `employee_id` + linked user name), pivot `role`, pivot `allocation_pct` (suffixed `%`), pivot `from_date`, pivot `to_date`, and a **Status** badge — `success` "current" for an open stint, `gray` "ended" once `to_date` has passed. Default sort `from_date desc`, with a "current only" filter on by default so a project with years of history opens on today's team.

Actions:

| Action | Behaviour |
| --- | --- |
| `AttachAction` | `->preloadRecordSelect()`, with the pivot fields in `->schema(fn (AttachAction $action) => [$action->getRecordSelect(), role, allocation_pct, from_date (default today), to_date (nullable)])` — the standard Filament pattern for pivot data on attach. Rejects an employee who already has an **open** stint on this project, with a plain message rather than a raw unique-constraint error (the `unique(project_id, employee_id, from_date)` index only catches the same-day case). |
| `EditAction` | Edits the pivot columns only — role, allocation, dates. Validates `to_date >= from_date`. |
| **`endAssignment`** (custom) | Sets `to_date` to today. This is the intended way to remove someone from a project: the stint stays on the record, so "who worked on this last year" survives. Visible only while the stint is open. |
| `DetachAction` / `DetachBulkAction` | Kept for correcting mistakes, but gated on `ProjectDelete` — i.e. CEO/Administrator only. Detaching erases the stint outright, and the pivot's `from_date`/`to_date` exist precisely so history is preserved; ordinary users get *End assignment* instead. |

Permission-wise, attach/edit/end are all `ProjectUpdate`, which per §7 every employee holds — the team list is meant to be self-service. Only detach is privileged.

### Employee side — assigned projects on the employee view page

`app/Filament/Resources/Employees/RelationManagers/ProjectsRelationManager.php`, added to `EmployeeResource::getRelations()` alongside the existing `ChangeRequestsRelationManager`. Relation managers render on that resource's `view` page, which is where this was asked for.

**Read-only**, following `ChangeRequestsRelationManager`'s precedent: no attach/create/edit/detach. Assignments are managed from the project side, so there is one place to change them and no second write path to keep consistent. Columns: project code, project name, status badge, pivot role, pivot allocation, pivot from/to dates, current/ended badge. `->recordUrl(fn ($record) => ProjectResource::getUrl('view', ['record' => $record]))` so a row click jumps to the project. Sorted by `from_date desc`. `canViewForRecord()` requires `ProjectView`.

Note this composes with existing scoping rather than fighting it: `EmployeeResource` is already limited to own + downline, so an employee sees their own project history and a manager sees their reports'. Nothing extra to build.

Also added to `EmployeeInfolist` — a compact **Manages** section listing `managedProjects` and `secondaryProjects` (§5), since "which projects does this person run" is a different question from "which projects are they assigned to", and the two manager columns already make it a free query.

## 9. Tests — phase 1

**`tests/Feature/ProjectResourceTest.php`** — follows `FilamentRoleResourceTest` (`InteractsWithTenant`, `RefreshDatabase`, `Gate::before(fn () => true)`):

- create via Livewire persists the project, including primary and secondary manager;
- duplicate `code` is rejected;
- end date before start date is rejected;
- naming the same employee as both primary and secondary is rejected — at the form and at the model;
- a project saves fine with no managers set, and with only a primary;
- deleting an employee nulls their designation and leaves the project intact;
- delete is blocked while an assignment exists;
- creating a project with three environments persists one row per kind, and a second `prod` row for the same project is rejected;
- editing an environment's URL leaves the stored password untouched, and the resulting activity entry carries no password value (the `logExcept` guard from §2).

**`tests/Feature/ProjectAssignmentTest.php`** — the pivot behaviour, driven through the relation managers with `Livewire::test(EmployeesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])`:

- attaching an employee stores the pivot role, allocation and dates;
- attaching someone who already has an **open** stint on that project is rejected with a readable message, not a constraint error;
- re-attaching someone whose previous stint has ended **is** allowed, and both stints coexist;
- *End assignment* sets `to_date` to today and leaves the row in place;
- `to_date` before `from_date` is rejected;
- the "current only" filter hides ended stints, and `currentEmployees()` / the listing's Team count agree with it;
- detach is absent for an Employee-role user and present for a CEO (the §8 gate);
- the employee-side `ProjectsRelationManager` lists that employee's stints, is read-only (no attach/detach actions), and does not list projects they aren't assigned to.

**`tests/Feature/ProjectAuthorizationTest.php`** — the §7 shape, asserted against real roles rather than `Gate::before` (follow `AuthorizationTest` / `EmployeeHierarchyScopingTest` for role setup under spatie teams):

- an **Employee**-role user lists all projects — including ones they neither manage nor are assigned to;
- that user can create a project and edit environments/managers on someone else's project;
- that user **cannot** delete, and **cannot** see the Check now action;
- a **Manager** can trigger Check now; a **CEO** can delete an unassigned project but is still refused one with assignments.

**`tests/Feature/EnvironmentHealthCheckTest.php`** — the check logic, all with `Http::fake()` so no real request leaves the suite:

- `200` → up, latency and code recorded, one history row written, `health_*` columns refreshed;
- `500` → down with the code stored;
- `401` and `403` → **up** (the §6 decision — this is the assertion that stops someone "fixing" it later);
- `302` → up, and the redirect target is **not** requested;
- connection exception / timeout → down with a truncated error, no unhandled throw;
- `405` on HEAD → retried once with GET, and the GET result is what's recorded;
- an environment with `is_monitored = false` or a blank URL is never dispatched;
- `uptimePercent()` returns `null` with no history, `100.0` for all-up, and the right figure for a mixed window — with rows outside the window excluded;
- `Prunable` deletes checks older than `retention_days` and keeps newer ones;
- the command dispatches one job per monitorable environment **per tenant** (assert with two companies that jobs don't leak across connections — the case tenancy bugs actually show up in).

`FilamentResourcesSmokeTest` already renders every resource's index/create pages, so that coverage comes free.

## 10. Phase 2 — schema additions

Everything from here on is **phase 2**: monitoring turned into an on-call tool, plus dashboard widgets. It is roughly the same size as phase 1 again, and it is worth shipping §1–§9 first — alerting that fires on a checker nobody has watched yet is how you learn what a false positive looks like the hard way. The sections are written to be built in order after phase 1 is live.

`database/migrations/tenant/2026_07_27_140200_add_monitoring_to_project_environments.php`:

```
project_environments (added columns)
  alerts_enabled        boolean, default true    // seeded false for dev in the form
  muted_until           timestamp, nullable      // maintenance window — suppresses alerts, still records checks
  check_interval_min    unsignedSmallInteger, nullable   // null → config default (§13)
  expected_content      string, nullable         // when set, the check GETs and asserts the body contains this
  expected_status       unsignedSmallInteger, nullable   // override for endpoints that legitimately return 204/302
  is_public             boolean, default false   // publishable on the status page (§14)
  consecutive_failures  unsignedSmallInteger, default 0
  consecutive_successes unsignedSmallInteger, default 0
  ssl_expires_at        timestamp, nullable
  ssl_issuer            string, nullable
  ssl_checked_at        timestamp, nullable
  ssl_alerted_at_days   unsignedSmallInteger, nullable   // last threshold already alerted on
```

`database/migrations/tenant/2026_07_27_140300_create_project_environment_incidents_table.php`:

```
project_environment_incidents
  id
  project_environment_id  FK project_environments, cascadeOnDelete
  started_at              timestamp        // first failure of the run, not the threshold crossing
  confirmed_at            timestamp, nullable   // when it crossed the failure threshold and alerted
  resolved_at             timestamp, nullable
  failure_count           unsignedInteger, default 1
  last_error              string, nullable
  last_status_code        unsignedSmallInteger, nullable
  reminders_sent          unsignedTinyInteger, default 0
  timestamps
  index(['project_environment_id','resolved_at'])
```

An incident row is what makes the rest of phase 2 tractable: it is the flap-suppression state, the alert dedupe key, the downtime duration for MTTR, and the reminder counter — all in one place. Counters alone can suppress duplicate alerts but can't answer "how long was prod down last month".

## 11. Alerting & flap suppression

The rule that keeps alerts trustworthy: **alert on confirmed state transitions, never on individual check results.**

```
check fails:
    consecutive_successes = 0; consecutive_failures++
    open an incident if none is open (started_at = now)
    if consecutive_failures == failure_threshold  (default 3)
        confirm the incident, send EnvironmentDown          ← the only "down" alert
    else if incident confirmed and now - last_reminder >= reminder_interval (default 60 min)
        send a reminder, up to max_reminders (default 3), then stay quiet

check succeeds:
    consecutive_failures = 0; consecutive_successes++
    if consecutive_successes == recovery_threshold (default 2) and an incident is open
        resolve it; send EnvironmentRecovered with the downtime duration
```

- **Three failures at a 5-minute interval means a ~10-minute confirmation delay.** That is the trade being made deliberately: a single dropped request, a deploy restart or a DNS blip never pages anyone, at the cost of not hearing about a genuine outage for ten minutes. Both numbers are config, and a `check_interval_min` of 1 on prod (§13) brings confirmation down to ~2 minutes without touching the threshold.
- **Unconfirmed incidents still get recorded**, resolved silently, and remain visible on the project page. Flapping shows up as a row of short unconfirmed incidents — which is the data you need to decide whether a threshold is wrong.
- **`muted_until`** suppresses alerts during a planned deploy while still recording checks and incidents, so a maintenance window doesn't create a blind spot in the uptime figure. A **Mute** action (1h / 4h / until tomorrow) sits next to *Check now*, gated on `ProjectHealthCheck`.
- **Recovery alerts always send** if a down alert was sent, even past the reminder cap. An unresolved-looking incident is worse than one extra email.

### Who is notified

`app/Notifications/EnvironmentDown.php`, `EnvironmentRecovered.php`, `CertificateExpiring.php` — all `ShouldQueue`, following `PayslipRejected`'s shape (`via()`, `toMail()` with a deep link into the panel).

- **Recipients:** the project's primary and secondary manager (§5), resolved to their `User` via `Employee::user`. If neither is set, fall back to a configurable role — `config('projects.alerts.fallback_role', 'Manager')` — resolved through spatie with the company's team id. Silently dropping an alert because nobody was assigned is the failure mode to avoid.
- **Slack:** `config/services.php` already carries `slack.notifications.bot_user_oauth_token` and a default channel, so `toSlack()` is the natural fit — it needs `composer require laravel/slack-notification-channel`, which isn't installed yet. Until then `via()` returns mail only, driven by `config('projects.alerts.channels')`. Worth noting `MAIL_MAILER` defaults to `log`, so mail needs a real transport configured before any of this is more than a log line.
- **Per-kind gating:** `alerts_enabled` on the environment (form default: on for prod/qual, off for dev), plus a global `config('projects.alerts.enabled')` kill switch for staging deploys of this app itself.

## 12. Certificate expiry

A daily job, separate from the health check — certificates don't change every five minutes, and a TLS handshake is a heavier operation than a HEAD.

`app/Jobs/CheckEnvironmentCertificate.php`, dispatched by `projects:check-certificates` (`TenantAware`, scheduled `->dailyAt('06:00')`), for every monitored environment whose URL is `https`:

- Opens a socket with `stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]])`, reads the peer certificate, parses `validTo_time_t` and the issuer CN.
- Stores `ssl_expires_at`, `ssl_issuer`, `ssl_checked_at`.
- Alerts the project managers when the remaining days cross a configured threshold — `[30, 14, 7, 3, 1]` — using `ssl_alerted_at_days` so each threshold fires **once** rather than daily for a month.
- `verify_peer: false` on purpose: dev and qual often run self-signed certs, and we want the expiry date regardless. Chain validity is reported as a separate flag rather than an alert, because a self-signed dev cert is not an incident.
- An expired or unreadable certificate does **not** mark the environment down — that's the HTTP check's job. Keeping the two independent stops one cert problem from muddying the uptime figure.

## 13. Content assertions & per-environment intervals

**Content assertions.** When `expected_content` is set, the check uses `GET` instead of `HEAD` and additionally requires the body to contain that string; `expected_status`, when set, replaces the default 2xx/3xx/401/403 rule. A status-200 page that has started rendering a stack trace is the outage this catches and a plain ping never will. The body is matched against the first `config('projects.health.max_body_bytes')` (default 256 KB) and **still never stored** — only a "content assertion failed" error string.

**Per-environment intervals.** `check_interval_min` (null → `config('projects.health.default_interval', 5)`). The scheduler flips from "every five minutes, check everything" to "every minute, check what's due":

```php
Schedule::command('projects:check-health')->everyMinute()->withoutOverlapping();
```

with the command selecting `health_checked_at IS NULL OR health_checked_at <= now() - INTERVAL check_interval_min MINUTE`. Two consequences to plan for: the row volume in §1 scales with the interval (a 1-minute prod check is 1,440 rows/day/environment, five times the earlier estimate), and `withoutOverlapping` plus per-environment jobs means the queue worker is now on the critical path every minute — worth its own supervisor entry rather than sharing with payroll PDF generation.

## 14. Public status page

The one part of this feature that faces the internet, so it is **off by default** and shows the least it can.

- Route in `routes/web.php`, outside the auth group: `/status/{company:slug}/{token}`. Filament's tenancy middleware doesn't apply here, so a small middleware resolves the `Company` by slug, checks the token, calls `makeCurrent()`, and `forgetCurrent()`s afterwards — without it every query would hit the landlord connection.
- **Token, not obscurity-free:** `projects.status_page.token` and `projects.status_page.enabled` live in the existing tenant `settings` table (via the `setting()` helper), managed on the Administrator-only `CompanySettings` page. Rotating the token revokes every shared link.
- **Published fields:** project name, environment kind, current up/down, and 30-day uptime. **Never** the URL, username, password, `health_error` (which leaks internal hostnames), or incident error text. Only environments with `is_public = true` appear — prod only, by default.
- Cached for 60 seconds (`Cache::remember`) so the page costs one query per minute regardless of traffic, and a public URL can't be turned into a database load generator.
- Tests must assert the negative: a response body containing a credential or an internal hostname is the bug this page could ship.

## 15. Dashboard widgets & latency charts

Widgets are auto-discovered from `app/Filament/Widgets` (already configured in `AdminPanelProvider`), each gated by `canView()` and following the existing `$isLazy` / `$sort` conventions in `OperationsOverview` and `CashFlowChart`. Sorts start at 5 so the finance widgets keep their position.

| Widget | Contents |
| --- | --- |
| `EnvironmentHealthOverview` (stats) | Up / Down / Unknown environment counts and open confirmed incidents. `canView()` → `ProjectView`, which per §7 is **everyone**. Down count coloured `danger` when non-zero, with a `url()` to the projects listing pre-filtered to unhealthy |
| `EnvironmentIncidentsTable` (table widget) | Currently-open confirmed incidents: project, environment kind, down for (duration from `started_at`), last status code, last error. Empty state "All environments healthy" — the widget everyone actually reads. Gated `ProjectView` |
| `MyProjectsOverview` (table widget) | The projects the signed-in user's employee record manages (primary or secondary) or is assigned to, with each one's worst environment status. Makes the dashboard useful to an individual rather than only to whoever owns everything. Resolves `auth()->user()->employee`; renders nothing when the user has no employee record |
| `CertificateExpiryTable` (table widget) | Environments whose `ssl_expires_at` is inside 30 days, soonest first. Hidden entirely when the list is empty rather than showing a reassuring empty box |

**Latency charts** — `ProjectHealthChart` (`ChartWidget`, `columnSpan: 'full'`) on `ViewProject::getHeaderWidgets()`: average latency per hour per environment over a `$filter`-selected window (24h / 7d / 30d), one dataset per environment kind, following `CashFlowChart`'s filter pattern. Driven by a single grouped aggregate over `project_environment_checks` (`selectRaw` on a date-truncated `checked_at`), not by loading rows — at 30 days × 1-minute intervals that table holds tens of thousands of rows per environment.

A dashboard-level version of the same chart is deliberately **not** included: aggregating latency across unrelated projects produces a line that means nothing.

## 16. Tests — phase 2

**`tests/Feature/EnvironmentAlertingTest.php`** — the state machine from §11, with `Notification::fake()` and `Http::fake()`:

- two consecutive failures send **nothing** and leave an unconfirmed incident open;
- the third failure confirms the incident and sends exactly one `EnvironmentDown`;
- further failures inside the reminder interval send nothing; one past it sends a reminder; reminders stop at `max_reminders`;
- one success does not resolve (threshold is 2); the second resolves the incident and sends `EnvironmentRecovered` with the right duration;
- a fail → pass → fail flap opens, resolves and reopens incidents without ever alerting;
- `muted_until` in the future suppresses notifications but still writes checks and incidents;
- recipients are the primary and secondary manager; with neither set, the fallback role receives it; with `alerts_enabled = false`, nobody does;
- `config('projects.alerts.enabled') = false` silences everything.

**`tests/Feature/EnvironmentCertificateTest.php`** — threshold crossings alert once each (not daily), a renewed certificate resets `ssl_alerted_at_days`, a non-https URL is skipped, and an unreachable TLS port records an error without marking the environment down.

**`tests/Feature/EnvironmentContentAssertionTest.php`** — `expected_content` present switches HEAD → GET; body containing the string is up; a 200 whose body lost the string is **down** with a content-assertion error; `expected_status` overrides the default rule; no body is persisted anywhere.

**`tests/Feature/EnvironmentCheckSchedulingTest.php`** — only due environments are dispatched, `check_interval_min` overrides the config default, and a null interval falls back to it.

**`tests/Feature/StatusPageTest.php`** — the security-critical one: disabled by default returns 404; a wrong token 404s; a valid token renders only `is_public` environments; **the response body contains no password, username, URL or error text**; the page is cached; and the correct tenant is resolved when two companies both have status pages enabled.

**`tests/Feature/ProjectWidgetsTest.php`** — each widget renders for an Employee-role user (`FilamentWidgetsSmokeTest` covers the panel-wide sweep), counts match seeded data, `MyProjectsOverview` shows only the signed-in user's projects and degrades gracefully with no employee record, and `CertificateExpiryTable` hides when empty.

## 17. Out of scope

- **Utilisation / overlap validation across projects** — the assignment UI (§8) validates within a project (no duplicate open stint, sane dates) but does not check whether an employee's `allocation_pct` across all their open assignments exceeds 100%. That needs a company-wide view and a policy decision about whether it's a hard block or a warning.
- Multi-region environments (a `label` column in the environment unique key).
- Tagging invoices, payments or petty cash vouchers to a project.
- Project reporting or profitability rollups.
- **Leave tracking and automatic cover** — no `employee_leaves` table, no "who is responsible today" resolution. The secondary manager is a recorded name, not a computed hand-over (§5). Wiring cover up later means adding a dated leave source first; the two manager columns are already the right shape for it.
- **On-call depth beyond §11** — escalation chains and rotas, acknowledge/silence-from-email, PagerDuty/Opsgenie integration, SMS, and synthetic multi-step transactions (log in, click through, assert). §11 pages the two managers; anything with a rota is a different product.
- **Public status page depth** — custom domain, historical incident timeline with written updates, subscriber email notifications, per-component grouping.

Because the pivot, environment, check and incident tables ship across the two phases, none of the above needs a destructive schema change later.

---

## Delivery order

**Phase 1 (§1–§9)** — migrations → models → policy + permissions → resource → assignment relation managers → health check job/command → tests.
~22 new files, 6 edited (`Employee`, `EmployeeResource`, `EmployeeInfolist`, `PermissionSeeder`, `RoleSeeder`, `routes/console.php`).

**Phase 2 (§10–§16)** — schema additions → alerting state machine + notifications → certificate job → content assertions + due-based scheduling → status page → widgets and charts → tests.
~18 new files, 4 edited (`ProjectEnvironment`, `CompanySettings`, `routes/console.php`, `routes/web.php`), plus `composer require laravel/slack-notification-channel` if Slack alerts are wanted.

**Recommendation:** ship phase 1 and let the checker run for a week or two before building phase 2. The thresholds in §11 (3 failures to confirm, 2 to recover, 60-minute reminders) are guesses until there is real data about how often these particular environments blip; tuning them against a live history costs nothing, while shipping alerting on day one means learning what a false positive looks like by being paged for one. Nothing in phase 2 requires phase 1 to have been built differently.

**Prerequisites for phase 2 to actually deliver anything:** `MAIL_MAILER` is currently `log`, so a real mail transport is needed before a "down" alert reaches a person; Slack needs the notification-channel package; and both phases need `schedule:run` on cron plus a running queue worker (§6).

---

## Implementation notes

Built 2026-07-27. Three deviations from the plan above, all forced by the codebase rather than chosen:

1. **Pivot table name.** The plan called the table `project_employee`, which is *not* Laravel's default for `Employee` + `Project` (that would be `employee_project`, alphabetically). The table keeps the plan's name and both `belongsToMany` calls pass it explicitly.

2. **`isDue()` is evaluated in PHP, not SQL.** A per-environment interval compared against `health_checked_at` needs database-specific date arithmetic (`datetime(?, '-N minutes')` on sqlite vs `DATE_SUB` on MySQL). Since the row count is tens per company, `ProjectEnvironment::dueForCheck()` loads the monitorable rows and filters them in PHP instead. The same portability problem in the latency chart is handled with an explicit per-driver `bucketExpression()`.

3. **Dispatch logic lives in `HealthCheckDispatcher`, not in the commands.** Spatie's `TenantAware` command trait iterates real per-tenant database connections, so a console command wrapped in it cannot run in this suite (`TENANT_DATABASE_CONNECTION` is empty and `SwitchTenantDatabaseTask` throws). The "which environments are due" logic moved into a service the tests drive directly; the commands are thin wrappers. **The per-company fan-out is therefore not covered by tests** — it is the package's behaviour, but it is also the thing most likely to break silently on a multi-tenant deploy, so verify it once by hand with `php artisan projects:check-health` against two real companies.

Two bugs the tests caught while building, worth knowing about because both would have been quiet in production:

- **Certificate thresholds fired at the wrong level.** The crossed threshold was computed with `max()` of the satisfied thresholds, so a certificate 5 days from expiry recorded the *30-day* threshold and then went quiet — no 7-day, 3-day or 1-day warning would ever have been sent. It needs `min()` (the tightest threshold crossed).
- **`$data` missing from a transaction closure** in `PettyCashService::updateVoucher` (unrelated feature, found in the same session) threw on every save.

Also worth recording: `StatusPageTest` and the widget tests drop `SwitchTenantDatabaseTask` from `multitenancy.switch_tenant_tasks` so `makeCurrent()` works on the single test database. If a future change makes the status page depend on real per-tenant DB behaviour, those tests will pass while production breaks.

### Verification at time of writing

- Full suite: **284 passed** (962 assertions), including 66 new assertions across 9 new test files.
- Pint clean on all new and touched files.
- Not exercised in a browser, and no scheduled check has ever run against a real host — `MAIL_MAILER` is still `log`, so no alert has been delivered end to end. The mail transport, a cron entry for `schedule:run`, and a queue worker are the three prerequisites before monitoring does anything (§6).
