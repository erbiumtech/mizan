# Projects Listing — Implementation Plan

**Status:** Proposed
**Created:** 2026-07-27

Goal: a **Project** entity with employees assignable to it, plus a Filament listing with create/edit. HR-flavoured — no client, invoice or ledger links. The assignment **schema and Eloquent relations** land in this slice; the assignment **UI** is deferred (see [§7](#7-out-of-scope-for-this-slice)).

---

## 1. Migration — `database/migrations/tenant/2026_07_27_140000_create_projects_tables.php`

```
projects
  id
  code            string, unique          // e.g. PRJ-ERP-01
  name            string
  description     text, nullable
  status          string, default 'planned'   // planned|active|on_hold|completed|cancelled
  start_date      date, nullable
  end_date        date, nullable
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
```

Lives under `database/migrations/tenant`, so it auto-loads in the test suite (`AppServiceProvider::boot`) and runs per company. Apply to existing tenants with:

```
php artisan tenants:artisan "migrate --force"
```

The pivot carries `from_date` in the unique key so an employee can be re-assigned to the same project in a later stint.

## 2. Model — `app/Models/Project.php`

- Extends `TenantModel`; `use Auditable, HasCustomFields` — same as `Product` / `Invoice`.
- Status constants (`STATUS_PLANNED`, `STATUS_ACTIVE`, `STATUS_ON_HOLD`, `STATUS_COMPLETED`, `STATUS_CANCELLED`).
- Casts: `start_date`, `end_date` → `date`.
- `employees()` — `belongsToMany(Employee::class)->withPivot(['role', 'allocation_pct', 'from_date', 'to_date'])->withTimestamps()`.
- `scopeActive()` — status `active`.
- Inverse `projects()` added to `app/Models/Employee.php`.

## 3. Policy — `app/Policies/ProjectPolicy.php`

Auto-discovered by Laravel (`App\Models\X` → `App\Policies\XPolicy`). Checks `ProjectView` / `ProjectCreate` / `ProjectUpdate` / `ProjectDelete`, with `delete()` refusing a project that already has assignments — the same guard style `ProductPolicy` uses for movement history.

## 4. Permissions

- `database/seeders/PermissionSeeder.php` — four entries with `'group' => 'Project'`.
- `database/seeders/RoleSeeder.php` — `ProjectView/Create/Update` into `$managerPermissions`; `ProjectDelete` into the CEO extras. Administrator syncs `Permission::all()` already; the Employee role gets none.
- Re-seed after deploy: `php artisan tenants:artisan "db:seed --class=RoleSeeder --force"` (plus `PermissionSeeder` centrally).

## 5. Filament resource — `app/Filament/Resources/Projects/`

Mirrors the `Products` layout. Resources are auto-discovered — no panel registration — and the new resource is picked up for free by global search and the command palette (`RecordProvider` / `ResourceProvider` both iterate `Filament::getResources()`).

| File | Contents |
| --- | --- |
| `ProjectResource.php` | `navigationGroup 'Employee'`, `Heroicon::OutlinedBriefcase`, `recordTitleAttribute 'name'`, globally searchable `code` + `name`, `getEloquentQuery()` with `withCount('employees')` and `with('customFieldValues.customField')` |
| `Schemas/ProjectForm.php` | code (unique, `ignoreRecord: true`), name, description, status select, start/end date pickers (end `afterOrEqual` start), `...CustomFieldsSchema::form(Project::class)` |
| `Tables/ProjectsTable.php` | `->header(view('filament.tables.saved-views-bar'))`; columns code, name, status badge (colour per state), start/end dates, "Team" from `employees_count`, `created_at` toggled off by default; `SelectFilter` on status; `Group::make('status')`; `EditAction` + `DeleteBulkAction` |
| `Pages/ListProjects.php` | `use HasSavedViews`; `CreateAction` + `saveViewAction()`; preset views: Active first / Name A→Z / Newest |
| `Pages/CreateProject.php`, `Pages/EditProject.php` | Standard |

## 6. Tests — `tests/Feature/ProjectResourceTest.php`

Follows `FilamentRoleResourceTest` (`InteractsWithTenant`, `RefreshDatabase`, `Gate::before(fn () => true)`):

- create via Livewire persists the project;
- duplicate `code` is rejected;
- end date before start date is rejected;
- delete is blocked while an assignment exists.

`FilamentResourcesSmokeTest` already renders every resource's index/create pages, so that coverage comes free.

## 7. Out of scope for this slice

- Assignment management UI (a relation manager on the project edit page).
- Utilisation / overlap validation across projects.
- Tagging invoices, payments or petty cash vouchers to a project.
- Project reporting or profitability rollups.

Because the pivot table ships now, none of the above needs a schema change later.

---

**Order:** migration → model → policy + permissions → resource → tests.
**Footprint:** ~8 new files, 3 edited (`Employee`, `PermissionSeeder`, `RoleSeeder`).
