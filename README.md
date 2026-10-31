# Mizan

**Multi-tenant accounting, payroll, HR, sales, construction and work management for Pakistani businesses.**
Laravel 13, Filament 5, PHP 8.4.

Mizan runs a company's books and people in one place: a posted double-entry ledger, payroll that books
itself into that ledger, FBR salaried tax slabs stored per fiscal year, bank payment files a Pakistani bank
accepts, and the operational modules around them — leave and attendance, invoicing and CRM, construction
cost control and site operations, a Kanban work tracker. Each company gets its own database; each module
is licensed and switched on per company.

> **Status: in production for the company that built it, still young.** The API is versioned under
> `/api/v1`, but there is no upgrade path between releases yet, and the Pakistan-specific pieces (tax
> slabs, bank file formats, FBR digital invoicing) need attention every Finance Act. Read
> [Security model](#security-model) before running it for anyone else.

## Contents

- [What is built](#what-is-built)
- [The interface](#the-interface)
- [Architecture](#architecture)
- [Getting started](#getting-started)
- [Testing](#testing)
- [Conventions](#conventions)
- [Security model](#security-model)
- [Roadmap](#roadmap)
- [Documentation](#documentation)
- [Contributing and license](#contributing-and-license)

---

## What is built

Every module lives in `app/Modules/<Name>` with a `module.php` manifest (models, resources, permissions,
role grants, option lists, navigation). `Core` is always on; everything else is licensed and enabled per
company, and a module refuses to switch on until the modules it `requires` are on. The groups below follow
the navigation rail.

### Finance

| Module | What it does | Requires |
|---|---|---|
| **Accounting** | Chart of accounts, journal entries (draft → pending approval → approved → posted, reversal instead of un-posting), payments, banks and bank files, fixed assets and depreciation, petty cash, bank reconciliation, financial reports | — |
| **Invoicing** | Contacts and sales/purchase invoices, recurring invoices, statements and overdue reminders; posts through Accounting. FBR digital invoicing pipeline (see [Roadmap](#roadmap)) | Accounting |
| **Inventory** | Products and stock movements, valued through Accounting | Accounting |
| **Personal Finance** | An individual's Pakistani income tax estimate over a personal account's own books | Accounting |

Payments resolve their type per transaction (RTGS, IBFT, same-bank transfer) against the SBP bank
directory, and export as a Standard Chartered iPayments file or a company-defined template format
(`BankFileFormat`). Balances are computed from posted lines, never stored.

### People and payroll

| Module | What it does | Requires |
|---|---|---|
| **Employees** | Employee records (CNIC, bank details, reporting lines), self-service change requests that go through approval | — |
| **Payroll** | Payslips and pay components, salary slabs, FBR salaried tax per fiscal year, annual tax projection, salary bank files and FBR tax files; payslips post to the ledger | Employees |
| **Leave** | Leave types, entitlements, requests, per-day records, computed balances, carry-over and lapse | Employees |
| **Attendance** | Work patterns, daily attendance, overtime, regularisation requests | Employees |
| **Devices** | Biometric devices pushing punches live, folded into attendance days | Attendance |
| **Advances** | Loans to employees recovered from payroll in instalments | Employees, Payroll |
| **Expense Claims** | Claims, approval, reimbursement through payroll | Employees, Payroll |
| **Joining & Leaving** | Onboarding/exit checklists, expiring documents, issued assets, final settlement | Employees |
| **Performance** | Review cycles, goals, ratings and one-to-ones (ratings never touch pay) | Employees |
| **Recruitment** | Vacancies, applicants, interviews, offers; a hire creates the employee | — |
| **Client Billing** | The monthly bill to a client: staff at full cost plus office expenses, less advance repayments | Employees, Payroll, Invoicing |
| **MPR** | Monthly progress reports and the month-over-month comparison export | — |

### Sales and service

| Module | What it does | Requires |
|---|---|---|
| **CRM** | Leads, sources and owners, pipelines and opportunities, activities, a public lead-capture endpoint, conversion to a customer | — |
| **Quotations** | Versioned quotes with validity; conversion produces a draft invoice | Invoicing |
| **Support** | Tickets, categories with measured (not enforced) SLA clocks, internal replies | — |
| **Campaigns** | Segments, campaigns and consent as append-only records; WhatsApp is template-gated | CRM |

### Construction

| Module | What it does | Requires |
|---|---|---|
| **Construction** | Jobs and sites, work breakdown, the cost-code library, the ISO 19650 document register | — |
| **Construction Contracts** | FIDIC/AIA head contracts and subcontracts: item schedule, variations, progress claims, payment certificates, retention | Construction |
| **Construction Cost Control** | Job-cost ledger, cost periods, budget / committed / actual / forecast per cost code | Construction, Accounting |
| **Construction Site Operations** | Site diary, RFIs, submittals, punch lists, programme, delay events with their notice clock (works offline — see below) | Construction |
| **Construction Quality & Safety** | ITPs and inspections with hold points, NCR/CAPA, incidents, permits, toolbox talks, inductions | Construction |

### Work and projects

| Module | What it does | Requires |
|---|---|---|
| **Projects** | Projects and dated team assignments, environment health checks with uptime history, certificate expiry, an optional token-gated public status page | Employees |
| **Timesheets** | Time against projects, billable or not, and the hours-based invoice line | Employees, Projects |
| **Work** | Work items on a Kanban board: types, workflows with status categories, backlog, sprints, epics, releases, an item panel, repository and time-tracker links | Employees, Projects |

### Core and platform

- **Core** — users, roles and permissions per company, companies, custom fields, the activity log, fiscal
  years, holidays, saved table views, the ⌘K command palette, reports hub, help pages.
- **Platform panel** (`/platform`) — super-admin operation across companies: provisioning, company profiles
  (which modules a kind of business starts with), legal entity types, subscriptions and billing, the public
  website content. See `docs/platform-panel-plan.md`, `docs/saas-launch-plan.md`.
- **Default roles**: Employee, Accountant, Manager (inherits Accountant, adds approve/post), CEO (inherits
  Manager), Administrator (everything). Modules add their own grants through `role_grants`.

## The interface

- **Two Filament 5 panels.** `/admin/{company-slug}` is the company panel (tenant-scoped, SPA navigation);
  `/platform` is the landlord panel with no tenant.
- **Material 3 theme** (`resources/css/filament/admin/material.css`, `docs/material-3-theme.md`): colour
  tokens (`--md-sys-color-*`), a navigation rail plus drawer grouped by domain, a top app bar with the
  company's logo and search, outlined form fields, list pages with search and filter chips above the table,
  and the same layout for related-record tabs, which load after the record page.
- **Side sheets** (`docs/side-sheets.md`): simple master data opens for view and edit in a sheet over its
  list; medium records open a view sheet with "Open full page"; documents stay on full pages. Every
  resource declares its choice.
- **Replicate** (`docs/replicate-records.md`): 58 resources offer "make another like this", each declaring
  what a copy resets; excluded resources have a written reason.
- **Approvals first**: lists of records with an approval step show those awaiting a decision on top.
- **Urdu and right-to-left**: every UI string is translated in `lang/en.json` and `lang/ur.json`.
- **Installable PWA with offline sync** (`docs/desktop-mobile-plan.md`): web push, a manager's approvals over
  `/api/v1`, and an outbox/inbox sync engine that carries attendance punches and site diary lines, snag
  changes and photographs taken offline.
- **Optional AI command bar** (`docs/ai-command-bot-plan.md`): natural-language transactions through the
  Anthropic API, off unless enabled with a key.

## Architecture

| | |
|---|---|
| Runtime | PHP 8.4, Laravel 13, Filament 5 / Livewire, Tailwind 4 via Vite |
| Tenancy | `spatie/laravel-multitenancy` — a landlord database (users, companies, roles, billing) and one database per company |
| Auth | `spatie/laravel-permission` in teams mode (roles per company); Sanctum tokens for the API |
| Audit | `spatie/laravel-activitylog` |
| Queues / realtime | Horizon, Reverb (broadcast notifications), web push |
| Documents | `spatie/laravel-pdf` (Browsershot/Chromium) with a dompdf fallback |
| Ops | `spatie/laravel-backup` (landlord and per-tenant), `spatie/laravel-health`, Sentry |

Key mechanisms:

- **Tenant resolution.** The company slug in `/admin/{company}` makes a company current, switching the
  `tenant` connection, file storage roots and the permission team.
- **Modules.** `app/Modules/*/module.php` manifests; per-company `CompanyModule` rows say what is licensed
  and enabled; a disabled module's resources, navigation, reports and permissions disappear. Adding one:
  `docs/new-module-checklist.md`.
- **Option lists.** Dropdowns a company may edit come from `options('module.key')`, seeded per company
  (`docs/option-lists-audit.md`).
- **Replication, side sheets, pending-first sorting** are declared per model/resource and enforced by
  coverage tests (below).
- **API.** Routes answer under `/api` and `/api/v1`; new endpoints are `/v1` only. Spec:
  `docs/api/openapi.yaml`.

## Getting started

Requirements: PHP 8.4, Composer, MySQL 8, Node 22+ (asset build and PDF rendering).

```bash
git clone https://github.com/erbiumtech/mizan.git && cd mizan
cp .env.example .env          # before composer install: artisan boots during install
```

Edit `.env`:

- `DB_*` — the landlord database.
- `TENANT_DB_DRIVER` and `TENANT_DB_*` — where per-company databases are created (`tenant_<slug>`).
- `BACKUP_NOTIFICATION_EMAIL` — leave it commented out or set a real address; empty fails validation.
- `NODE_BINARY` (and npm) — absolute paths, for PDF rendering.
- `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` — required on a production host (see below).

```bash
composer install
php artisan key:generate
npm install && npm run build
php artisan migrate --seed    # landlord schema, permissions, the super admin, and a demo company
php artisan serve
```

Sign in at `/admin`. Off production, the seeded super admin is `admin@example.test` / `password` and the
demo company is **Demo Company (Private) Limited** (`demo-company`). On a production host the seeder
refuses the demo password and creates no demo company.

Day-to-day commands:

```bash
php artisan companies:create "Acme Ltd" --owner=admin@example.test   # provision a company and its database
php artisan tenants:migrate             # migrate every tenant database (also --status, --pretend)
php artisan tenants:seed-baseline       # top up baseline data (option lists, etc.) for existing companies
php artisan queue:work                  # or Horizon
```

Add `* * * * * php artisan schedule:run` to cron: health checks, backups, billing, and the per-module
reminders (`routes/console.php`) run from the scheduler.

Use `tenants:migrate`, not `tenants:artisan migrate`: without a path the latter migrates the landlord
folder and reports nothing to do.

## Testing

```bash
php artisan test --parallel   # the full suite
vendor/bin/pint --test        # code style
```

The suite runs on SQLite with tenant migrations auto-loaded, so landlord and tenant collapse into one
schema; paths that need a real second connection are covered by dedicated isolation tests. CI
(`.github/workflows/tests.yml`) runs Pint, then splits the suite across four shards by test-method weight
(`.github/scripts/shard-tests.php`), with a gate job named `laravel-tests (8.4)`.

Tests that hold the codebase's conventions:

| Test | Enforces |
|---|---|
| `TranslationCoverageTest` | Every UI string goes through `__()`, exists in `en.json` and `ur.json`, keys sorted |
| `TenantIsolationTest`, `TenantConnectionIsolationTest`, `TenantDatabaseIsolationTest` | Landlord vs tenant models and connections stay separated |
| `PanelPerformanceTest` | Query-count and page-size budgets on key pages |
| `ModuleCoverageTest`, `HelpCoverageTest`, `NotificationCoverageTest` | Every module, page and notification is declared, documented and wired |
| `ReplicableCoverageTest` | Replicate appears exactly where a model is replicable |
| `RecordSheetCoverageTest` | Every resource declares sheet / view sheet / page |
| `PendingFirstCoverageTest` | Lists of approval-bearing records sort pending first |
| `ClassConstantReferenceTest` | Every `self::`/`static::` constant read resolves |
| `RelationManagersLoadLazilyTest` | No relation manager renders eagerly |

## Conventions

- Strings: `__()` everywhere, added to both `lang/en.json` and `lang/ur.json`, sorted case-insensitively.
- CSS: Material tokens only in `material.css`; no `:has()` nested inside `:has()` (the whole rule is
  dropped); logical properties so Urdu mirrors.
- Built assets in `public/build` are committed; rebuild with `npm run build` when CSS/JS changes.
- New modules follow `docs/new-module-checklist.md`; morph types are stored as aliases, not class names.
- See [CONTRIBUTING.md](CONTRIBUTING.md) for the PR flow.

## Security model

- **Segregation of duties is in policies**, not the UI: an approver cannot approve their own journal entry,
  accountants cannot post, posted entries are reversed rather than edited.
- **Employee self-service writes go through approval**, so nobody silently changes their own bank account.
- **The activity log is append-only** and excludes credential fields.
- **Project environment passwords are stored in plain text by decision** (shared dev/qual/prod logins);
  `docs/projects-listing-plan.md` §4 documents the upgrade path.
- **Health checks fetch operator-supplied URLs** — `HEAD`/`GET` only, no redirects, no stored body, on-demand
  checks behind their own permission.
- The most recent review is `docs/security-audit-2026-10-26.md`. Report issues per [SECURITY.md](SECURITY.md).

## Roadmap

Planned or partly built, from the plan documents (nothing here is shipped unless the doc says so):

- **FBR digital invoicing** — phases 0–3 and 5 built; nothing transmits yet. A real integrator driver
  (phase 4) waits on advisor answers, and `fbr.enabled` stays off (`docs/fbr-digital-invoicing-plan.md`,
  `docs/fbr-advisor-questions.md`).
- **Desktop and mobile clients** — PWA, push, approvals and offline sync for attendance and site work are
  built; further offline workflows (O3–O5) and native shells are open (`docs/desktop-mobile-plan.md`).
- **Non-profit tax computation** — the s.100C credit math is held for the advisor; the other legal entity
  types are built (`docs/legal-entity-types-plan.md`).
- **Multi-store retail and POS** — not started (`docs/retail-stores-pos-plan.md`).
- **Gap analyses** against other products, partly actioned: `docs/akaunting-gap-plan.md`,
  `docs/operentra-gap-analysis.md`.

## Documentation

| Doc | About |
|---|---|
| `docs/accounting-implementation-plan.md` | Tax engine, double-entry ledger, audit log |
| `docs/modules-plan.md`, `docs/module-packaging-plan.md`, `docs/new-module-checklist.md` | The module system and adding a module |
| `docs/company-profiles-plan.md`, `docs/legal-entity-types-plan.md` | Which modules and tax pack a company gets |
| `docs/platform-panel-plan.md`, `docs/saas-launch-plan.md`, `docs/company-website-plan.md` | Super-admin panel, SaaS billing, public website |
| `docs/hrms-plan.md`, `docs/employee-hierarchy-access-plan.md`, `docs/payslip-component-input-plan.md` | HR modules, manager hierarchy, payslip inputs |
| `docs/crms-plan.md` | CRM, quotations, support, campaigns |
| `docs/construction-management-plan.md` | The five construction modules |
| `docs/project-management-plan.md`, `docs/projects-listing-plan.md` | Work tracker and projects |
| `docs/personal-finance-plan.md` | Personal tax estimate |
| `docs/fbr-digital-invoicing-plan.md`, `docs/fbr-advisor-questions.md` | FBR e-invoicing |
| `docs/desktop-mobile-plan.md`, `docs/api/openapi.yaml` | API v1, PWA, push, offline sync |
| `docs/material-3-theme.md`, `docs/side-sheets.md`, `docs/replicate-records.md` | UI theme, side sheets, replicate |
| `docs/advanced-tables-implementation.md`, `docs/table-context-menu-plan.md`, `docs/command-palette-implementation.md` | Saved views, right-click menus, ⌘K |
| `docs/custom-fields-implementation.md`, `docs/option-lists-audit.md` | Custom fields, editable dropdowns |
| `docs/reports-expansion-plan.md`, `docs/realtime-notifications-plan.md`, `docs/ai-command-bot-plan.md` | Reports, notifications, AI command bar |
| `docs/page-load-performance-plan.md` | SPA navigation and render budgets |
| `docs/security-audit-2026-10-26.md`, `docs/open-source-release-checklist.md` | Security review, release checklist |
| `docs/filament-laravel13-migration-plan.md`, `docs/nova-parity-inventory.md` | History: the move from Nova to Filament |
| `docs/gnucash-gap-analysis.md`, `docs/erpnext-gap-plan.md`, `docs/bexio-gap-plan.md`, `docs/akaunting-gap-plan.md`, `docs/operentra-gap-analysis.md` | Gap analyses against other products |
| `docs/retail-stores-pos-plan.md` | Retail and POS (not started) |

## Contributing and license

Issues and pull requests are welcome — read [CONTRIBUTING.md](CONTRIBUTING.md) and the
[Code of Conduct](CODE_OF_CONDUCT.md). New behaviour needs a test; accounting and tax changes need a test
that fails without them. Changes are recorded in [CHANGELOG.md](CHANGELOG.md).

Licensed under [MIT](LICENSE).
