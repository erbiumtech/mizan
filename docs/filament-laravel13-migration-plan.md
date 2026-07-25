# Migration Plan: Nova → Filament + Laravel 12 → 13

**Status:** In progress
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
- [ ] Remaining 25 Resources → Filament Resources (in progress via `port-nova-to-filament` workflow).
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
- [ ] Check each batch against its Nova counterpart before moving on.

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
- [ ] Update `CLAUDE.md` / docs. *(No `CLAUDE.md` in repo; this plan doc is the living record.)*

---

## Notes & risks

- Nova and Filament share no code; Phase 3 is the bulk of the effort and parallelizes cleanly by domain (can be run as a multi-agent workflow).
- Verify sereny permission tables' shape before assuming a clean spatie mapping.
- Keep Nova running until Phase 4 so the app is never without a working admin panel.

## Sources

- Laravel 13 — https://laravel-news.com/laravel-13
- Filament v3→v4 upgrade — https://filamentexamples.com/tutorial/filament-v3-v4-upgrade
- Filament version support policy — https://filamentphp.com/docs/5.x/introduction/version-support-policy

