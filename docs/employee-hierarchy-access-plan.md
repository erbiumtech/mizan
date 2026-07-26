# Employee Manager Hierarchy & Data Access — Implementation Plan

**Status:** Implemented
**Created:** 2026-07-26

## Goal & decisions

Let a **manager** see all their reports' data. Concretely: add a reporting hierarchy to employees; a user who manages other employees can view **everything currently scoped to "own rows only"** for themselves **plus their entire downline** (all descendants, transitively).

- **Model:** **Manager hierarchy** — `Employee.manager_id` (self-referential). A manager sees their direct reports *and their reports' reports* (full subtree).
- **Data in scope:** **everything scoped by employee** — every resource today limited to "own rows" expands to "own + managed": `Employee`, `Payslip`, `MPR`, `EmployeeSetting`, `AnnualTax`. (Privileged roles — Administrator/Accountant/Manager/CEO — keep full access as they do now.)
- **Who assigns the hierarchy:** **Admins + Managers** (role `Administrator` or `Manager`, or `CEO`).

## Current scoping (the seam we're changing)

Non-privileged **Employee**-role users are restricted in each resource's `getEloquentQuery()`:
| Resource | Current "own" filter |
|----------|----------------------|
| `EmployeeResource` | `where('user_id', $me)` |
| `PayslipResource` | `whereHas('employee', user_id = $me)` (only for Employee-role, non-privileged) |
| `MPRResource` | `where('user_id', $me)` |
| `EmployeeSettingResource` / `AnnualTaxResource` | (to be confirmed / added — scope by `employee.user_id`) |

These "own" filters become **"own + downline"**.

## Architecture

```
Employee.manager_id ──┐  (self-referential; nullable)
                      ▼
EmployeeHierarchy::descendantEmployeeIds($employee)   → all reports (transitive)
EmployeeAccess::accessibleEmployeeIds($user)          → self employee + descendants
EmployeeAccess::accessibleUserIds($user)              → user_ids of the above
                      │
                      ▼
Resource getEloquentQuery(): replace "own" filter with whereIn(accessible…)
```

- **`Employee.manager_id`** — nullable self-FK (soft index; same tenant DB).
- **Resolution service** `App\Support\EmployeeAccess` (request-memoized):
  - `accessibleEmployeeIds(User): Collection<int>` — the user's own employee id + all descendant employee ids.
  - `accessibleUserIds(User): Collection<int>` — the `user_id`s of those employees (for `MPR` which keys on `user_id`).
  - Descendants computed by loading the tenant's `id → manager_id` map once and doing an in-PHP BFS (works on SQLite + MySQL, no recursive-CTE dependency; employee counts are small).
- All tenant-scoped (`Employee` is a `TenantModel`); no cross-DB joins (users resolved via ids, per [[cross-db-join-pitfall]]).

## Phased plan

- [x] **Phase 1 — Schema & model.** Migration `database/migrations/tenant/…_add_manager_id_to_employees` (nullable `manager_id` + index; existing tenants via `tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"`). `Employee`: `manager()` (belongsTo self), `directReports()` (hasMany self). Factory support.
- [x] **Phase 2 — Access resolution.** `App\Support\EmployeeAccess` with `accessibleEmployeeIds()` / `accessibleUserIds()` + BFS descendant resolver + per-request memo. Unit tests for a 3-level tree.
- [x] **Phase 3 — Apply scoping.** Update `getEloquentQuery()` in `Employee`, `Payslip`, `MPR`, `EmployeeSetting`, `AnnualTax` resources: keep privileged-role full access; for restricted users use `whereIn('id' | 'employee_id' | 'user_id', accessible…)` instead of the `= $me` filter. Centralize the "is privileged?" + accessible-set logic in a small trait `ScopesToAccessibleEmployees` to avoid drift.
- [x] **Phase 4 — Assignment UI.** Add a **Manager** `Select` to the Employee form (searchable, shows `display_label`, **excludes self and own descendants** to prevent cycles). Editable only by Admins/Managers (`hasAnyRole(['Administrator','Manager','CEO'])`); read-only/hidden otherwise. Managers need to *see* employees to assign them, so `EmployeeResource` visibility also opens to those roles (not just Administrator). Optional: a "Direct reports" relation manager on the Employee view.
- [x] **Phase 5 — Cycle & integrity guards.** Validation rule preventing a manager selection that would create a loop (self or descendant). On manager delete/deactivate, decide reparent vs null (default: set reports' `manager_id` to the deleted manager's manager, or null).
- [x] **Phase 6 — Tests.** Hierarchy scoping: a manager sees own + all descendants' Payslips/MPRs/EmployeeSettings/AnnualTaxes/Employee rows; a leaf employee still sees only their own; privileged roles see all; **tenant isolation** (a manager in Company A sees nothing in Company B); cycle guard rejects loops. Keep the suite green.

## Permissions & roles

- **Viewing** expanded data is automatic from the hierarchy (no new permission) — driven by `manager_id`, not a role.
- **Assigning** `manager_id`: gated to `Administrator`/`Manager`/`CEO`. (If you later want finer control, swap for an `EmployeeManageHierarchy` permission — noted as a future option.)
- Note: the spatie **`Manager` role** (segregation-of-duties, sees all payroll) is separate from **org manager** (`manager_id`). A person can be an org manager without the Manager *role*; the hierarchy grants them visibility of just their downline.

## Risks / notes

- **Privileged-role short-circuit must stay** so Admin/Accountant/Manager/CEO keep full access; only the "Employee-only" branch changes to "own + downline".
- **`MPR` keys on `user_id`**, others on `employee_id` — the service exposes both id sets so each resource filters on the right column.
- **Performance:** resolve the descendant set once per request (memoized) and filter with a single `whereIn`; don't call the resolver per row (see [[performance-patterns]]).
- **Cycles:** always exclude self + descendants from the manager picker and validate on save.
- **Per-tenant:** `manager_id` lives in each company's tenant DB; hierarchies are independent per company ([[permission-teams-model]]).
- **Deactivation:** an inactive manager still owns `manager_id` links — decide whether their reports' data stays visible to them; default keep until reassigned.
