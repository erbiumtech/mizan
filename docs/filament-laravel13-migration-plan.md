# Migration Plan: Nova → Filament + Laravel 12 → 13 + Multi-Tenancy

**Status:** Phases 0–5 done. Full suite green (139 passed, 0 failures).

> **Pre-existing test failures resolved (2026-07-25):** the 5 long-standing failures are fixed. **Tax (×4):** `TaxCalculatorTest` encoded the 2025-2026 salaried slabs but ran against the shared 2026-2027 fiscal year — pointed it at 2025-2026; also fixed an off-by-one in `SalarySlabSeeder` (`min_amount` thresholds were `600001…` but the tax formula subtracts `min_amount`, so they must be the round `600000…` "exceeding" thresholds the service documents). *Salary slabs are per-tenant, so re-seed via `php artisan tenants:artisan "db:seed --class=SalarySlabSeeder --force"` (NOT plain `db:seed`, which has no current company and hits the tenant placeholder DB).* **Audit (×1):** `AuthorizationTest` grabbed the first Account "updated" activity (seeding updates several accounts) — scoped it to the specific subject account + latest.
**Created:** 2026-07-25
**Branch:** `filament`

## Decisions (confirmed)

- **Sequencing (REVISED):** Build Filament and reach parity on **Laravel 12** while Nova still runs → remove Nova → **then** upgrade Laravel + Filament to latest. See blocker below.
- **Permissions:** Move from `sereny/nova-permissions` (Nova-only) to `spatie/laravel-permission` with a Filament UI (Shield-style resource).
- **Fidelity:** Exact feature parity — every resource, action, metric, and filter reproduced 1:1.

## ⚠️ Critical blocker driving the sequence

Nova **5.4.3** (the only licensed version — see [[nova-license-version]]) is **incompatible with Laravel 13**:
- It requires `spatie/once ^3.0`, which the Laravel framework *replaces* (a hard composer conflict).
- It caps at Illuminate `^12`.

Only Nova 5.9.5 resolves against Laravel 13, and we are not licensed for it. Therefore **Nova must be removed before the Laravel 13 upgrade** — the original "Laravel 13 first" order is impossible. The framework bump (tinker 2→3, dropping spatie/once) becomes trivial once Nova is gone.

## Target versions

- **During the port:** stay on **Laravel 12** + Nova 5.4.3; install the latest **Filament that supports Laravel 12** (v4.x).
- **After Nova removal:** **Laravel 13.x** (requires PHP 8.3+; PHP 8.4 already active locally) + latest **Filament**. Filament v4 supports both L12 and L13, so no Filament re-install is needed — just a version bump.

## Current state

- Laravel 12 (`laravel/framework: ^12.0`), PHP 8.2 locally.
- **Laravel Nova 5.4.3** admin panel — primary migration target. No Nova→Filament compat layer; everything is hand-ported.
- `sereny/nova-permissions` + `spatie/laravel-activitylog`.
- PDF stack: `spatie/laravel-pdf` + `spatie/browsershot` + `puppeteer`.
- API layer (`routes/api.php`, `app/Http/Controllers/Api`) is independent of Nova — **no changes needed**.
- Domain: accounting/payroll.

### Nova inventory to port

| Item | Count | Location |
|------|-------|----------|
| Resources | 28 | `app/Nova/*.php` |
| Actions | 28 | `app/Nova/Actions` |
| Metrics | 7 | `app/Nova/Metrics` |
| Filters | 7 | `app/Nova/Filters` |
| Custom Fields | 1 (`Currency`) | `app/Nova/Fields` |
| Dashboards | 1 (`Main`) | `app/Nova/Dashboards` |
| Policies | 27 | `app/Policies` |

---

## Phase 0 — Prep ✅

- [x] Confirm local PHP is **8.4** (active: 8.4.23).
- [x] Work on branch `filament`.
- [x] Generate parity-inventory checklist → `docs/nova-parity-inventory.md` (the parity contract for Phase 2).

## Phase 1 — Install Filament on Laravel 12 (side-by-side with Nova)

Stay on Laravel 12 + Nova 5.4.3. Install the latest Filament that supports L12 (v4.x).

- [x] `composer require filament/filament` → installed **v5.7.3** (v5 supports both L12 and L13, so no re-upgrade needed in Phase 4). `php artisan filament:install --panels` done.
- [x] Panel scaffolded at `/admin` (`AdminPanelProvider`, registered). Nova stays at `/cpi` — no route collision.
- [x] `spatie/laravel-permission` v6.25.0 **already installed** (sereny depends on it); permission tables already migrated. No data migration needed — roles exist: Administrator, Employee, Accountant, Manager, CEO.
- [x] `User` implements `FilamentUser::canAccessPanel()` mirroring Nova's `viewNova` gate.
- [x] App boots on Laravel 12.64 / PHP 8.4.23; `/admin` + login routes register.
- [x] Add Shield-style Filament resource for roles/permissions. `RoleResource` (Access Control group) with per-module grouped permission CheckboxLists (synced via `SyncsGroupedPermissions` trait) + `PermissionResource` (name/group/guard CRUD, group filter). Both honor existing `RolePolicy`/`PermissionPolicy`. Verified by `FilamentRoleResourceTest` (create + edit hydration/persistence).

## Phase 2 — Exact-parity port (batched by domain)

Batches: Accounting · Payroll · Banking · Inventory · Reports. Source of truth: `docs/nova-parity-inventory.md`.

- [x] **Pilot: `Product`** ported end-to-end (form, computed table columns, 3 stock actions as record+bulk, read-only Movements relation manager, policy reused). Verified with Livewire render test. Conventions captured to memory.
- [x] Remaining 25 Resources → Filament Resources (ported via the `port-nova-to-filament` workflow; all 27 render, verified by `FilamentResourcesSmokeTest`).
- [x] 28 Actions → Filament table/page actions (single + bulk). All ported with confirmation dialogs + modal text matching Nova (Post/Reverse JE, Run Depreciation, Dispose Asset, Auto-Match, Complete Reconciliation, Exclude line). Statement-line Match/Unmatch/Exclude live on `BankStatementLines`. Authorization via `->visible()`/policy checks.
- [x] 7 Metrics → Filament widgets; rebuild Dashboard. Ported to `app/Filament/Widgets/`: `OperationsOverview` + `AccountBalancesOverview` (stats), `CashFlowChart` (line, 14/30/60 ranges) and `PayrollByEmployeeChart` (pie). Per-metric permission gating preserved via `canView()`/per-stat checks. Default Filament demo widgets removed from `AdminPanelProvider`.
- [x] 7 Filters → Filament table filters. Audited all host resources; added the 5 missing instances — EmployeeName/EmployeeEmail on `Employees` (both filter PK `id` via `->attribute('id')`, matching Nova) and Employee/Month/FiscalYear on `Payslips`. Verified they apply via `FilamentFiltersSmokeTest`.
- [x] `Currency` custom field → Filament `TextColumn::money('PKR')` / numeric `TextInput`. Filament formats via PHP intl (not brick/money), so the Nova-specific `RoundingNecessaryException` pre-rounding workaround is unnecessary — money columns round safely. Verified no brick/money usage remains.
- [x] 27 Policies reused (Filament honors Laravel policies) — verified every policy defines `viewAny` (required for resource visibility); standard Laravel method names align with Filament's gate checks.
- [x] Custom authorization & role-scoped queries — 5 `getEloquentQuery` overrides (Account order, Employee/MPR/EmployeeChangeRequest/Payslip role scoping) match Nova `indexQuery` 1:1. Multi-relation search (employee_id + user.name + fiscalYear.name, +id/month) reproduced via searchable columns on AnnualTaxes, EmployeeSettings, Payslips; verified with `FilamentSearchSmokeTest`.
- [x] Custom `/reports/*` pages → Filament custom Pages under `app/Filament/Pages/` (nav group **Reports**, auto-discovered). All 7 ported:
  - `TrialBalance` (as_of filter, PDF), `ProfitAndLoss` (from/to filters, PDF) — reuse `FinancialReportService`.
  - `SalaryBankFile` (month/value_date, streamed CSV) — reuse `SalaryBankExportService`.
  - `PettyCashBook` (month view of the two-sided book + Add Voucher / Top Up / Replenish modal actions) — reuse `PettyCashService`.
  - `BankPaymentFile` (month/type/value_date filters, grouped preview, streamed CSV that marks payments exported; regenerates salary drafts) — reuse `BankPaymentExportService` + `PaymentService`.
  - `AccountRegister` (account switcher, from/to filter, GnuCash-style ledger, Add Transaction modal action) — reuse `RegisterEntryService`.
  - `GnuCashImport` (CSV `FileUpload`, dry-run preview via token-stored file, Confirm Import writing the ledger + activity log) — reuse `GnuCashImportService`.
  - Permission gates preserved via `canAccess()` (`ReportView`, `JournalEntryView`, `GnuCashImport`) + action `->visible()` (`PettyCashCreate`/`Replenish`, `JournalEntryCreate`+`RegisterPost`). CSV downloads stream directly (self-contained, no dependency on Nova-era routes); PDFs reuse the existing `reports.*` routes for now (Browsershot config). Verified with `FilamentReportPagesSmokeTest`.
- [x] Check each batch against its Nova counterpart (done during the port via `docs/nova-parity-inventory.md`; Nova has since been removed in Phase 3).

## Phase 3 — Remove Nova (cutover) ✅ DONE

- [x] Full parity regression (CRUD, actions, permissions, PDFs) — full suite green at baseline (115 passed; only the 5 known pre-existing failures: TaxCalculatorTest slab math ×4 + AuthorizationTest audit ordering ×1, all unrelated to the migration).
- [x] Removed `laravel/nova` + `sereny/nova-permissions` from `composer.json`, the `https://nova.laravel.com` repositories entry, `app/Nova/` (72 classes), `config/nova.php`, and `app/Providers/NovaServiceProvider.php` (unregistered from `bootstrap/providers.php`). `spatie/once` was only a transitive Nova dep (Laravel `replace`s it) and dropped automatically.
- [x] **Promoted `spatie/laravel-permission` (^6.0) to a direct dependency** — it was previously only pulled in transitively via `sereny/nova-permissions`, so removing sereny would have broken the Filament Role/Permission resources + all policies. Now required by the root package; version 6.25.0 retained.
- [x] Removed the orphaned `create_passkeys_table` migration — `laravel/passkeys` was a Nova-bundled dependency (Nova 5 passkey auth) the app never used; its migration referenced the now-absent `Laravel\Passkeys\Passkeys` class and broke every test until deleted.
- [x] Promoted Filament to primary route — `/` now redirects to the Filament admin panel (`Filament::getPanel('admin')->getUrl()` → `/admin`) instead of `/nova`. Updated `ExampleTest`.
- [x] Removed the now-superseded report web routes/controllers/old Blade views (salary-bank-file, bank-payment-file, account-register, gnucash-import, petty-cash) — fully replaced by the Filament report Pages; one old view still linked to `/nova`. Kept the PDF-serving GET routes (`reports.trial-balance`, `reports.profit-and-loss`) that the Filament pages' "Download PDF" actions call, plus `invoice.pdf` (`ReportPageController` + `InvoicePdfController` retained).

## Phase 4 — Upgrade Laravel 12 → 13 + Filament to latest ✅ DONE

- [x] Set `laravel/framework: ^13.0` and `laravel/tinker: ^3.0` (tinker 2.x capped at Laravel 12). No other constraint bumps were needed — `laravel/sanctum` 4.3.2, `spatie/laravel-activitylog` 5.0, `spatie/laravel-pdf` 2.12, `spatie/laravel-permission` 6.25, `spatie/browsershot` 5.4 and `filament/filament` v5.7 already declare `^13.0` support; `phpunit/phpunit` 11.5 stayed. Also bumped the `dev-master` branch-alias to `13.x-dev`.
- [x] Filament already on **v5.7.3** (supports L12 & L13) — no Filament upgrade required.
- [x] Ran `composer update laravel/framework laravel/tinker -W`: framework `v12.64.0 → v13.22.0`, tinker `→ v3.0.2`, Symfony `7.4 → 8.1`, brick/math `0.14 → 0.18`, guzzle bumps. Clean resolve, no conflicts. **Zero application code changes were required for L13** — no deprecations at boot.
- [x] **Gate passed:** `migrate:fresh --seed` runs clean; 92 admin routes register; Filament panel + all resources/widgets/report pages render (Filament smoke tests green); PDF routes intact. Full suite at baseline (117 passed — incl. 2 new panel-access tests; only the 5 known pre-existing failures remain).
- [x] Removed the last Nova leftover missed in Phase 3: `app/Services/NovaAuthService.php` (dead, unreferenced, and referencing the now-absent `Laravel\Fortify\Fortify` — Fortify was a Nova dependency). **Preserved its business rule in Filament**: `User::canAccessPanel()` now enforces active accounts (`status === 1`) instead of returning `true`, so inactive users are blocked from the panel exactly as the legacy status-based login did. `UserFactory` now defaults `status = 1` with an `inactive()` state; added `PanelAccessTest` covering both.
- [x] Update `CLAUDE.md` / docs. *(No `CLAUDE.md` in repo; this plan doc is the living record.)*

---

## Phase 5 — Multi-Tenancy (database-per-tenant)

Turn the current single-tenant accounting/payroll app into a multi-company SaaS where each company's data lives in its **own database**, one login can belong to **many companies** and switch between them, and a user's **role is scoped per company**.

### Decisions (confirmed)

- **Isolation:** **Database-per-tenant** via `spatie/laravel-multitenancy`. A central *landlord* DB holds the tenant registry + auth; each company gets its own database provisioned on creation.
- **Users:** **Central users + membership.** Users live in the landlord DB; a `company_user` pivot records which companies each user may access. One login → many companies.
- **Tenant identification:** **In-app switcher** using **Filament path-based tenancy** (`/admin/{company}`). No DNS/subdomains. The resolved Filament tenant drives the spatie DB connection swap for the request.
- **Roles/permissions:** **Per-company** via `spatie/laravel-permission` **teams** (`team_id` = company id), stored in the landlord DB alongside users. A user can be Accountant in one company and Manager in another.

### Architecture

**Landlord (central) connection** — holds cross-company data:
- `companies` (the tenant model; `name`, `slug`, `database` name, status). `Company extends Spatie\Multitenancy\Models\Tenant`.
- `users` (moved to landlord connection), `password_reset_tokens`, sessions/jobs meta.
- `company_user` membership pivot (also the Filament `HasTenants` relationship).
- spatie permission tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`) with **`teams = true`** and `team_foreign_key = company_id`.

**Tenant connection** — one DB per company, holds the whole domain (all 27 models): accounts, journal entries/lines, fiscal years, employees, employee settings/change requests, payslips, annual taxes, salary slabs, invoices/lines, payments, beneficiaries, contacts, products, stock movements, fixed assets, banks, company bank accounts, transaction types, petty cash vouchers, bank statements/lines, MPR, comments — plus a new per-tenant `settings` table.

**Request flow:** login (landlord guard) → Filament resolves `/admin/{company}` to a `Company` and checks membership → a panel tenancy middleware calls `$company->makeCurrent()` (spatie swaps the `tenant` connection) and sets `PermissionRegistrar::setPermissionsTeamId($company->id)` → all domain models (tenant connection) + policies now operate on that company only.

### Sub-phases / todos

- [x] **5.1 Install & configure** — installed `spatie/laravel-multitenancy` v4.1. Published `config/multitenancy.php` + `config/permission.php`. Added a switchable **`tenant`** connection to `config/database.php` (the default connection doubles as the *landlord*, per spatie's `landlord_database_connection_name = null`; SQLite → one file per company under `database/tenants/`). Configured `tenant_model = App\Models\Company`, `tenant_database_connection_name = tenant`, and switch tasks (PrefixCache + SwitchTenantDatabase + `SetPermissionsTeamIdTask`, created in 5.3–5.4). Set permission `team_foreign_key = company_id`. **`queues_are_tenant_aware_by_default` temporarily set to `false`** — spatie's default (`true`) made every queued notification demand a current tenant and broke the suite; re-enabled properly in 5.7. Baseline restored (122 tests, 0 errors).
- [x] **5.2 Landlord schema & models** — `companies` + `company_user` migrations (landlord); `App\Models\Company` extends spatie `Tenant` (slug route key, `users()` membership); `User implements HasTenants` (`companies()`, `getTenants()`, `canAccessTenant()`); `CompanyFactory`; `CompanyMembershipTest`. Teams + `company_id` pivot columns + default team context completed in the 5.2-remainder entry below. `User` is pinned to the landlord connection (`getConnectionName()`) so tenant-model→user relations don't inherit the tenant connection.
- [x] **5.3 Partition migrations** — split the existing 34 migrations into `database/migrations/landlord` (8: auth/permissions/companies/activity_log/personal_access_tokens) and `database/migrations/tenant` (28: all domain tables). Added `App\Models\TenantModel` (abstract base applying `UsesTenantConnection`) and codemod'd the 25 domain models to alias-import it as `Model` (zero class-body edits). `AppServiceProvider::boot()` registers the landlord path always and the tenant path only under `testing` (single-DB test mode via `TENANT_DATABASE_CONNECTION=""`). Cross-DB `users` FKs in tenant tables downgraded to `->index()` soft refs (users lives in the landlord DB). **Runtime switch proven** by `TenantDatabaseIsolationTest` — two companies on two SQLite files, each with an isolated `banks` table, no leakage into the default/landlord connection. Baseline green (125 tests, 0 errors).
- [x] **5.4 spatie ↔ Filament integration** — enabled `->tenant(Company::class, slugAttribute: 'slug')` on `AdminPanelProvider`; all panel routes are now `admin/{tenant:slug}/…` with Filament's built-in tenant switcher menu. Because isolation is at the **database** level, Filament's row-level tenant scoping is turned off globally via `Resource::scopeToTenant(false)` in `AppServiceProvider`. A `SyncSpatieTenant` listener on Filament's `TenantSet` event bridges the resolved Filament tenant to spatie: in production it calls `$company->makeCurrent()` (switch DB + cache prefix + permission team id); in the single-DB test env (no dedicated tenant connection) it only scopes the permission team id, leaving the shared test database untouched. Smoke tests updated via a `Tests\Concerns\InteractsWithTenant` trait that sets a current tenant. Baseline green (125 tests, 0 errors).
- [x] **5.5 Company provisioning** — `App\Multitenancy\CompanyProvisioner` creates the landlord `Company` record, creates + migrates an isolated tenant database (SQLite file per company, or `CREATE DATABASE` for MySQL/PgSQL), seeds baseline reference data via a new `TenantBaselineSeeder` (FiscalYear, Chart of Accounts, TransactionTypes, SalarySlabs, Banks) while the tenant is current, and attaches the creating user as an Administrator member. Exposed two ways: a `companies:create {name} {--slug} {--owner}` console command and a Filament self-service `RegisterCompany` page wired via `->tenantRegistration()` (route `admin/new`). Proven by `CompanyProvisioningTest` (isolated seeded DB + owner attachment + no leakage). Baseline green (126 tests, 0 errors).
- [x] **5.6 Per-tenant settings** — per-tenant `settings` table (tenant connection; `key` unique + json `value`), `App\Models\Setting` (extends `TenantModel`), and `App\Support\TenantSettings` accessor (singleton; per-tenant cache keyed by `Company::current()`; **falls back to `config()` when no override**, so behavior is unchanged for un-provisioned/single-DB test runs). Added a `setting($key, $default)` helper (autoloaded via `composer.json` `files`). Wired all 4 config call sites to `setting()`: `accounting.auto_post_payroll` + `accounting.payroll_accounts` (`PayrollPostingService`), `petty_cash.float_amount` (`PettyCashService`), `ipayments` (`SalaryBankExportService` + `BankPaymentExportService`). Added a Filament `CompanySettings` page (nav group **Settings**, Administrator-only) editing float/auto-post/payroll-account-codes/iPayments per company. Verified by `TenantSettingsTest` (fallback/override/array/bool/service) + `CompanySettingsPageTest` (render/save/gate). Baseline: 126 passed, only the 5 known pre-existing failures.
- [x] **5.7 Tenant-aware infrastructure** — **Queues:** re-enabled `queues_are_tenant_aware_by_default` via `env('QUEUES_TENANT_AWARE_BY_DEFAULT', true)` (set `false` in `phpunit.xml` so the single-DB suite stays green); queued jobs now capture + restore the current tenant. **Cache:** already isolated by spatie `PrefixCacheTask`. **Storage:** new `App\Multitenancy\Tasks\SwitchTenantFilesystemTask` (added to `switch_tenant_tasks`) reroutes the `public`/`local` disk roots + public URL to `…/tenants/{id}` per company and restores defaults on forget; routed the direct `storage_path('app/public/…')` writers through `Storage::disk('public')` so payslip/MPR PDFs no longer collide or leak across companies (`PayslipService`, `MPRSTable`, `PayslipsTable`); GnuCash import temp files inherit isolation via the rerouted default disk. **Activity log:** added `company_id` to the landlord `activity_log` (migration applied), new tenant-aware `App\Models\ActivityLog` (stamps `company_id` on write, global scope filters to the current company on read, unscoped in landlord/CLI context); `config/activitylog.php` `activity_model` + the Filament `ActivityLogResource` now use it. Verified by `TenantFilesystemTaskTest`, `TenantActivityLogTest` (+ the earlier `PayrollWidgetSmokeTest` cross-DB fix). Baseline: 131 passed, only the 5 known pre-existing failures.
- [x] **5.8 Data migration for the existing company** — *complete.* Idempotent `tenancy:migrate-existing {--name} {--slug=default} {--fresh}` command (`App\Console\Commands\MigrateExistingToTenant`): provisions the "default" `Company` schema-only (added `seedBaseline:false` to `CompanyProvisioner`), copies every domain table from the landlord/default connection into the tenant DB (driver-agnostic, FK checks disabled during copy, skips already-populated tables unless `--fresh`), and backfills all existing users as company members. Now also **seeds the default company's roles and remaps each user's pre-existing role assignments into that company's team** (`backfillRoles`). Verified by `MigrateExistingToTenantTest` (copy + membership + role remap, no leakage).
- [x] **5.2 remainder / teams enablement** — enabled `permission.teams = true`. Team column (`company_id`) baked into `create_permission_tables` (roles/model_has_roles/model_has_permissions, conditional on the teams flag) for fresh installs/tests, **plus** an idempotent `add_teams_to_permission_tables` ALTER migration (hasColumn-guarded, FK-index-then-swap-PK) for the existing landlord MySQL DB (applied). Roles are now **per-company**: `RoleSeeder` scopes creation to the current team; `CompanyProvisioner` seeds each new company's roles + assigns the creator Administrator within the company team. `SetPermissionsTeamIdTask`/`SyncSpatieTenant` set the team id on tenant switch. **Test harness (5.9 support):** base `TestCase` sets a default team id so domain tests stay consistent; tenant tests override via `makeCurrent`.
- [x] **5.9 Tests** — `PerCompanyRoleTest` (same user = Accountant in company A, Manager in company B; permissions resolve per team; membership gates `canAccessTenant`), plus existing `TenantDatabaseIsolationTest` (data isolation) and `CompanyProvisioningTest`/`MigrateExistingToTenantTest` updated for teams. Full suite: 134 passed, only the 5 known pre-existing failures.
- [x] **5.10 Tenancy UX & access control** —
  - **Company switching:** the Filament tenant menu (`->tenantMenu()`) is enabled, so any user belonging to >1 company gets a company switcher; `getTenants()` returns their `companies` membership.
  - **Per-company role/membership UI:** the User resource edit/create form has a **Company Access** multi-select (manages `company_user`) and a **Roles in {company}** multi-select scoped to the current company's team (hydrated/synced in `EditUser`/`CreateUser`). Verified by `UserRoleAssignmentTest`.
  - **Admin-only company creation:** `RegisterCompany::canView()` gates the "Register company" flow to `User::canCreateCompanies()` (Administrator in at least one of their companies); the registration page provisions via `CompanyProvisioner` (isolated DB + baseline seed + per-company roles + creator as Administrator). Verified by `CompanyRegistrationAccessTest`.
  - **Fix for pre-teams company:** the earlier-provisioned `erbium` company had no team-1 roles (predated per-company seeding); re-seeded its roles and restored the owner's Administrator assignment. New companies get roles automatically via the provisioner.

- [x] **5.11 Optional hardening & tooling** —
  - **CLI:** `user:assign-role {email} {role} --company=` (`AssignUserRole`) — attaches membership if needed, sets the team context, and assigns a per-company role from the terminal.
  - **Landlord cleanup tool:** `tenancy:cleanup-landlord --company=default [--force]` (`CleanupLandlordDomainTables`) — drops the 26 now-redundant domain tables from the landlord DB, but **only** tables whose data exists in the reference company's tenant DB (safety guard). Built as a command (not a migration) so it never runs in the single-DB test env. **Not yet executed** — it removes the last backup copy of live data, so run it manually once tenants are verified.
  - **PDF/storage verification:** confirmed under a live tenant that `Storage::disk('public')` paths/URLs are scoped to `…/tenants/{id}/…` and report services read tenant data; Browsershot rendering itself is unchanged/env-dependent.
  - **Intentionally not changed:** (1) permission (Role/Permission) models left on the default/landlord connection — they already resolve correctly (registrar loads on the non-swapped landlord connection; `User` is landlord-pinned so role relations follow) and custom models would add partial-coverage risk for no real benefit; (2) the NULL-team legacy roles + `company_id=0` assignment rows are **load-bearing** (the migration remap references the NULL-team role by id), so they are kept, not "cleaned".

### Notes & risks (Phase 5)

- **Filament tenancy is built for single-DB relations**; here the `Company` + membership live in the *landlord* DB while domain data lives per-tenant. This works because Filament only needs `getTenants()`/slug resolution from the central `Company` model — but every domain relationship must resolve on the tenant connection (watch cross-connection eager loads/joins).
- **Test harness is the biggest lift:** `RefreshDatabase` must migrate two connections and create a throwaway tenant DB per test. Existing 122 tests + all seeders assume one DB and will need a tenancy-aware base test case.
- **SQLite dev:** database-per-tenant = one file per company; ensure the switch task points the `tenant` connection at the right file and that `:memory:` isn't shared across tenants in tests.
- **Migrations split is irreversible-ish:** partitioning the 34 migrations must preserve FK order within each connection; cross-connection FKs (e.g. domain rows referencing `users.id`) become soft references (no DB-level FK) since users are in another DB.
- **Queues/schedule/PDF (Browsershot):** any out-of-request execution loses the current tenant — must be re-established from the job payload.
- Keep it behind the existing test baseline (122 passed, 5 known pre-existing failures) at every sub-phase.

- Nova and Filament share no code; Phase 3 is the bulk of the effort and parallelizes cleanly by domain (can be run as a multi-agent workflow).
- Verify sereny permission tables' shape before assuming a clean spatie mapping.
- Keep Nova running until Phase 4 so the app is never without a working admin panel.

## Sources

- Laravel 13 — https://laravel-news.com/laravel-13
- Filament v3→v4 upgrade — https://filamentexamples.com/tutorial/filament-v3-v4-upgrade
- Filament version support policy — https://filamentphp.com/docs/5.x/introduction/version-support-policy
- Filament multi-tenancy — https://filamentphp.com/docs/5.x/users/tenancy
- spatie/laravel-multitenancy — https://spatie.be/docs/laravel-multitenancy
- spatie/laravel-permission teams — https://spatie.be/docs/laravel-permission/v6/basic-usage/teams-permissions

