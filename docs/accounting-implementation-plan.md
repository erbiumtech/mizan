# Implementation Plan: Tax Engine + Double-Entry Accounting + Ledger + Audit Logs

**Project:** MPR (Laravel 12 · Nova 5 · Spatie Permissions)
**Date:** 2026-07-15
**Reference:** [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel), `~/Downloads/salary_tax.xlsx`

---

## Background

- The reference repo's double-entry engine has three pieces: an `accounts` table (type + normal balance + parent hierarchy), a `journal_entries` header (draft → posted lifecycle), and `journal_entry_lines` where each line is a debit **or** credit. Balancing is enforced at post time inside a DB transaction. We borrow that core and drop multi-tenancy, multi-currency, and bank reconciliation.
- The Excel's `Rate` sheet contains the official FBR salaried-person slabs for **FY 2025-26**:

| # | Annual taxable income | Tax |
|---|---|---|
| 1 | Up to 600,000 | 0 |
| 2 | 600,001 – 1,200,000 | 1% of amount exceeding 600,000 |
| 3 | 1,200,001 – 2,200,000 | 6,000 + 11% of excess over 1,200,000 |
| 4 | 2,200,001 – 3,200,000 | 116,000 + 23% of excess over 2,200,000 |
| 5 | 3,200,001 – 4,100,000 | 346,000 + 30% of excess over 3,200,000 |
| 6 | Above 4,100,000 | 616,000 + 35% of excess over 4,100,000 |

- Current gaps in the codebase:
  1. `SalarySlabSeeder` has 8 slabs with older rates (20/25/29/32/35%) that disagree with the sheet.
  2. Off-by-one in `PayslipService.php:66` — excess is computed against `min_amount` stored as `600001`, but the law says "exceeding 600,000".
  3. Tax logic is inline in `PayslipService`, not reusable.

---

## Phase 1 — Dynamic Pakistan Tax Calculation

1. **New `app/Services/TaxCalculatorService.php`**
   - `annualTax(float $annualTaxable, int $fiscalYearId): float` — slab lookup for that fiscal year, `fixed_tax + percentage% × (income − threshold)`.
   - `monthlyTax(...)` — annual ÷ 12, rounded.
   - Fully dynamic: future Finance Act changes = seed new rows, no code change.
2. **Update `SalarySlabSeeder`**
   - The 6 slabs above; store `min_amount` as the *exceeding threshold* (600000, 1200000, …); `max_amount = null` for the top slab.
   - Target the **active** fiscal year; delete stale slabs for that year before inserting.
3. **Refactor `PayslipService`**
   - Replace inline slab query (lines 60–67) with injected `TaxCalculatorService->monthlyTax(...)`.
   - Update both `new PayslipService` call sites (`app/Nova/Payslip.php:119`, `app/Models/Payslip.php:31`) to `app(PayslipService::class)`.

## Phase 2 — Audit Log Foundation

1. `composer require spatie/laravel-activitylog` + publish migration (`activity_log`: causer, subject morph, event, old/new JSON, timestamp).
2. **`Auditable` trait** wrapping `LogsActivity` with shared defaults: `logOnly(fillable)`, `logOnlyDirty()`, `dontSubmitEmptyLogs()`, per-model log name.
3. Apply to **every domain entity**: `Payslip`, `AnnualTax`, `Employee`, `EmployeeSetting`, `SalarySlab`, `FiscalYear`, `MPR`, `User`, plus the new `Account`, `JournalEntry`, `JournalEntryLine`.
4. Explicit domain events from services: entry **posted/reversed** (with totals), payslip **generated/regenerated**, slabs **re-seeded**.
5. Nova **`ActivityLog` resource** (read-only, filterable by model/user/event/date). Permission `ActivityLogView` (Administrator only); no update/delete — audit logs are immutable.

## Phase 3 — Accounting Migrations (3 tables)

1. **`accounts`** — `code` (unique, e.g. `1100`), `name`, `type` enum (`asset|liability|equity|income|expense`), `normal_balance` enum (`debit|credit`, auto-derived), `parent_id` self-FK (hierarchy), `is_active`, `allow_manual_entry`, `description`, cached `balance` decimal(15,2).
2. **`journal_entries`** — `entry_number` unique (`JE-YYYY-NNNNNN`), `entry_date`, `reference`, `memo`, `entry_type` enum (`general|adjusting|closing|reversing`), **`status` enum (`draft|pending_approval|approved|rejected|posted`)**, approval fields (**`approved_by` FK users nullOnDelete, `approved_at`, `rejection_reason`**), `is_posted`/`posted_at`, `created_by` FK users, `fiscal_year_id` FK, nullable `source_type`/`source_id` morphs (a Payslip owns the entry it generated).
3. **`journal_entry_lines`** — `journal_entry_id` FK cascade, `account_id` FK **restrict**, `debit_amount`/`credit_amount` decimal(15,2), `description`.
4. **`comments`** — polymorphic (`commentable_type`/`commentable_id`), `user_id` FK, `body` text, nullable `parent_id` (replies), `resolved_at` nullable, timestamps. Used first for payslip queries ("my overtime is missing"); reusable later on journal entries or MPRs.

## Phase 4 — Models

- **`Account`** — `parent()`/`children()`/`lines()`; `creating` hook derives `normal_balance` (asset/expense → debit, else credit); `canAcceptEntries()` (active + leaf + manual allowed); recursive `calculated_balance` accessor for roll-ups.
- **`JournalEntry`** — `lines()`, `totalDebits`/`totalCredits`, `isBalanced()` via `bccomp`, auto entry numbering, `source()` morphTo, `fiscalYear()`, `approver()`; status helpers `isApproved()`, `canBePosted()` (status = approved).
- **`JournalEntryLine`** — belongsTo both; `amount`/`type` accessors.
- **`Comment`** — `commentable()` morphTo, `user()`, `replies()`; `HasComments` trait added to `Payslip` (MorphMany).
- All use the `Auditable` trait.

## Phase 5 — Services: Engine + Ledger

- **`JournalEntryService`**
  - `create(array $header, array $lines)` — validates ≥2 lines, exactly one side > 0 per line, all accounts postable, debits == credits; entry starts as `draft`.
  - `submitForApproval(JournalEntry $e)` — draft → `pending_approval`.
  - `approve(JournalEntry $e, User $approver)` — only Manager/CEO; **approver must not be the creator** (segregation of duties); stamps `approved_by`/`approved_at`, status → `approved`.
  - `reject(JournalEntry $e, User $approver, string $reason)` — status → `rejected` with `rejection_reason`; accountant can amend and resubmit.
  - `post(JournalEntry $e)` — guard already-posted, **require status = approved**, re-check balance, DB transaction applying signed deltas (debit-normal: `+debit −credit`; credit-normal: inverse), stamp `is_posted`/`posted_at`, status → `posted`.
  - `reverse(JournalEntry $e)` — create + post a mirrored reversing entry (audit-safe; no in-place un-post).
  - Each posts an explicit activity-log event with entry number and totals.
- **`GeneralLedgerService`** (the Ledger layer)
  - `accountLedger(Account, $from, $to)` — opening balance, chronological posted lines with **running balance**, closing balance.
  - `trialBalance($asOfDate, $fiscalYearId)` — debit/credit per account; totals must match.
  - `generalLedger($from, $to)` — all account ledgers, for export.
  - Source of truth stays `journal_entry_lines` — running balances are computed, never stored, so they cannot drift.

## Phase 6 — Chart of Accounts + Authorization

- **`ChartOfAccountsSeeder`** — payroll-oriented chart:
  - `1000` Cash/Bank (asset)
  - `2100` Income Tax Payable · `2200` ESI/Health Insurance Payable · `2300` Salaries Payable · `2400` Employee Advances
  - `5100` Basic Salary · `5200` Medical Allowance · `5300` Petrol Allowance · `5400` Device Allowance · `5500` Bonus & Overtime (expenses)
- **`PermissionSeeder`** additions: `AccountView/Create/Update/Delete`, `JournalEntryView/Create/Submit/Approve/Reject/Post/Reverse`, `ActivityLogView`.
- **`RoleSeeder`** additions — accounting roles with segregation of duties:

| Role | Permissions |
|---|---|
| **Accountant** | Account view/create/update; JournalEntry view/create/submit; ActivityLog view. **Cannot approve or post.** |
| **Manager** | Everything Accountant has + JournalEntry approve/reject/post/reverse |
| **CEO** | Same approval powers as Manager + Account delete |
| **Administrator** | All (existing role, unchanged) |
| **Employee** | (existing role, extended) View **own** payslips only + download own payslip PDF + add/view comments on own payslips. No access to accounts, journal entries, or other employees' data. |

- **`PermissionSeeder`** also adds: `PayslipViewOwn`, `CommentCreate`, `CommentViewOwn` (Employee), `CommentView/Resolve` (Accountant/Manager/CEO/Administrator).
- Policies `AccountPolicy`, `JournalEntryPolicy` (`approve()` checks Manager/CEO role **and** approver ≠ creator), `ActivityLogPolicy`, `CommentPolicy` (employee may comment only on own payslips; may edit/delete own comment until staff replies) — following the existing `PayslipPolicy` pattern.
- **Update `PayslipPolicy`**: `view()` allows Employee role when `payslip->employee->user_id === $user->id` (own payslips only).
- **Demo/staff seed data**:
  - `EmployeeSeeder` — creates the staff users (Employee role) and their employee records.
  - `EmployeeSettingSeeder` — gives every employee a salary setting for the active fiscal year, basic wages spread evenly across **200,000 – 450,000** (rounded to the nearest 1,000), medical allowance at 10% of basic, petrol 13,500, device 5,000. Idempotent via `updateOrCreate` per employee + fiscal year.
  - `PayslipSeeder` — generates payslips for **every employee for each elapsed month of the active fiscal year** (up to the previous month). Sets only the input fields (`employee_id`, `month`, `fiscal_year_id`, working/paid days, occasional bonus/extra hours/advances for realism) and lets the existing `Payslip::booted()` hook drive `PayslipService::calculateByParams()` so earnings, tax, and net salary are computed exactly as in production — no hand-rolled math in the seeder. Idempotent via `firstOrCreate` per employee + month + fiscal year (skips existing slips so regenerated data isn't clobbered). Registered in `DatabaseSeeder` after `EmployeeSettingSeeder` (requires employees, settings, salary slabs, and the active fiscal year); once Phase 8 lands, seeded payslips also exercise the payroll journal-entry posting path.

## Phase 7 — Nova UI

- **`Account`** resource — hierarchy via parent BelongsTo, type badge, balance; **Account Ledger view** (Lens/detail table with running balance from `GeneralLedgerService`).
- **`JournalEntry`** resource — HasMany lines, **status badge** (draft/pending/approved/rejected/posted); Nova actions **Submit for Approval**, **Approve** / **Reject** (with reason field, visible only to Manager/CEO via `canRun`), **Post Entry**, **Reverse Entry** — all calling the service.
- Optional: Nova dashboard card "Entries awaiting my approval" for Manager/CEO; email notification to approvers on submission (Laravel notification).
- **`ActivityLog`** resource (read-only) + per-record **History panel** (MorphMany) on Payslip, JournalEntry, Account, Employee detail pages.
- **Employee self-service** (extends existing Nova `Payslip` resource):
  - `indexQuery()` scoping — Employee role sees only payslips where `employee.user_id = auth id`; existing DownloadPayslip action stays available for own slips.
  - **Comments panel** (MorphMany) on the payslip detail page — employee posts a query, Accountant/Manager replies and marks it resolved; unresolved-comment badge on the index.
  - Notifications: staff notified on new employee comment; employee notified on reply/resolution (Laravel notifications, reusing the approval-notification setup).
  - The existing Sanctum API (`PayslipController`) gains `GET /payslips/{id}/comments` + `POST` for a future employee portal/mobile app — same policy checks.
- Trial Balance & General Ledger as dashboard card / PDF export via existing `spatie/laravel-pdf`.

## Phase 8 — Payroll → Ledger Integration

When a payslip is finalized, `PayslipService` builds and posts a journal entry via `JournalEntryService`:

| Line | Account | Side |
|---|---|---|
| Basic + allowances + bonus | 5xxx expense accounts | Debit |
| Withholding tax (Phase 1 calculator) | 2100 Income Tax Payable | Credit |
| ESI insurance | 2200 ESI Payable | Credit |
| Advances recovered | 2400 Employee Advances | Credit |
| Net salary | 2300 Salaries Payable | Credit |

- Payroll entries are created as `pending_approval` — a Manager/CEO approves the payroll batch before it posts to the ledger (configurable: auto-approve system-generated entries via a config flag if that's too heavy).
- Linked via the `source` morph; payslip deletion/regeneration **reverses its entry first**.
- `AnnualTax` reporting can later reconcile against the `2100` ledger instead of recomputing.
- Every step lands in the audit log with the causing user.

## Phase 9 — Tests

- **Tax**: slab boundaries (exactly 600,000 → 0; 3,000,000 → 116,000 + 23%×800,000 = 300,000/yr → 25,000/mo); fiscal-year switching.
- **Engine**: unbalanced entry rejected; posting updates balances per normal-balance side; double-post rejected; non-leaf/inactive account rejected; reversal restores balances.
- **Approval workflow**: unapproved entry cannot be posted; Accountant cannot approve; creator cannot approve own entry; rejected entry can be amended and resubmitted; approve/reject events land in the audit log with the approver as causer.
- **Employee self-service**: Employee sees only own payslips (index scoping + policy); cannot view another employee's payslip or any journal entry; can comment on own payslip but not others'; staff reply and resolve; notifications fire both ways.
- **Ledger**: running balance correctness across a date range; trial balance debit/credit totals equal.
- **Audit**: rows written on create/update/post/reverse with correct causer; immutability (no update/delete via Nova).
- **Integration**: payslip entry lines sum to payslip totals; delete/regenerate reverses cleanly.

## Phase 10 — Accounts CRUD API + Chart of Accounts relations

Nova already provides staff CRUD for accounts (Phase 7). This phase adds a REST API
(following the existing `app/Http/Controllers/Api` + Sanctum pattern) and makes the
chart's relationships first-class in both API and UI.

1. **`Api\AccountController`** — full CRUD, all routes behind `auth:sanctum` and `AccountPolicy`:
   - `GET /api/accounts` — flat list; filters: `type`, `is_active`, `parent_id`, `search` (code/name).
   - `GET /api/accounts/tree` — the chart as a nested tree (parents with recursive `children`), each node carrying `balance` and roll-up `calculated_balance`.
   - `GET /api/accounts/{account}` — single account **with relations**: `parent`, `children`, latest `lines` (paginated), and `calculated_balance`.
   - `POST /api/accounts` — create (validation: unique code, valid type, `parent_id` must exist and match `type`).
   - `PUT /api/accounts/{account}` — update; changing `parent_id` re-validated against cycles (an account cannot become its own descendant).
   - `DELETE /api/accounts/{account}` — policy rules apply (CEO permission, no lines, no children).
2. **Form requests + `AccountResource` (JSON resource)** — `StoreAccountRequest` / `UpdateAccountRequest`; resource embeds `parent:code,name`, `children_count`, `lines_count`.
3. **Model relation hardening** (`Account`):
   - `descendants()` helper (recursive children) used by the tree endpoint and cycle guard.
   - `journalEntries()` hasManyThrough via lines — "all entries touching this account".
   - Scopes: `postable()` (active + leaf + manual), `ofType($type)`, `roots()` (`parent_id` null).
4. **Nova polish** — Account detail already shows Sub Accounts + Ledger Lines panels; add
   `parent` column to the index and a type-grouped ordering (`code` asc) so the chart reads
   as a tree in the list view.
5. **Tests** — API CRUD happy paths + policy denials (Accountant can create/update but not
   delete; Employee gets 403), tree endpoint shape, cycle-prevention on reparenting,
   duplicate-code rejection.
6. **Seeders — accounts & journal entries demo data**:
   - **`AccountSeeder`** (extends the Phase 6 `ChartOfAccountsSeeder`): adds common operating
     accounts beyond payroll — 1300 Accounts Receivable, 1400 Office Equipment,
     2400 Accounts Payable, 4200 Consulting Revenue, 5700 Rent Expense, 5800 Utilities Expense,
     5900 Office Supplies — nested under the existing 1000–5000 group headers (idempotent by `code`).
   - **`JournalEntrySeeder`** — realistic demo entries created **through `JournalEntryService`**
     (never raw inserts, so numbering/validation/audit all fire), covering every workflow state:
     a posted month of activity (revenue invoiced, rent + utilities paid, salaries accrual),
     one entry left `pending_approval`, one `draft`, one `rejected` (with reason), and one
     posted-then-reversed pair. Approver = seeded Manager user; entries dated across
     Jul–Sep 2026 so the ledger, running balances, and trial balance have a real spread.
   - Both registered in `DatabaseSeeder` after `ChartOfAccountsSeeder`; `JournalEntrySeeder`
     skipped in production (`app()->environment('production')` guard).

## Phase 11 — Fixed Assets (register + depreciation)

The `Account` Nova resource already exists (Phase 7: hierarchy, badges, ledger lines panel).
This phase adds a proper fixed-asset register on top of the engine.

1. **Migration `fixed_assets`** — `name`, `asset_code` (unique), `account_id` FK (asset account,
   e.g. 1400 Office Equipment), `purchase_date`, `purchase_cost` decimal(15,2),
   `depreciation_method` enum(`straight_line|declining_balance`), `useful_life_months`,
   `salvage_value`, `accumulated_depreciation` (cached), `status` enum(`active|fully_depreciated|disposed`),
   `disposed_at`, nullable `journal_entry_id` (purchase entry link), timestamps.
2. **Chart additions** (`ChartOfAccountsSeeder`): `1500` Accumulated Depreciation (asset, contra —
   credit-normal via explicit `normal_balance`), `5950` Depreciation Expense.
3. **`FixedAsset` model** — `Auditable`; `account()`, `depreciationEntries()` (morph source);
   `monthlyDepreciation()` (straight-line: (cost − salvage) / life; declining: rate × book value),
   `bookValue()` accessor (cost − accumulated).
4. **`DepreciationService`** — `runForMonth(FiscalYear $fy, string $month)`: for each active asset,
   builds a journal entry via `JournalEntryService` (debit 5950, credit 1500), links it via the
   `source` morph, updates `accumulated_depreciation`, flips status when fully depreciated.
   `dispose(FixedAsset $asset)` books the disposal (write off cost vs accumulated + gain/loss).
   Entries follow the same approval workflow (pending unless auto-post flag).
5. **Nova `FixedAsset` resource** — asset register with book-value column, status badge,
   depreciation schedule panel (its journal entries), **Run Monthly Depreciation** action
   (Manager/CEO) and **Dispose Asset** action.
6. **Tests** — straight-line math, fully-depreciated stop, disposal entry balances,
   schedule appears in ledger.
7. **`FixedAssetSeeder`** — demo asset register: a handful of realistic assets (laptops,
   office furniture, server hardware, a vehicle) with staggered `purchase_date`s across the
   active fiscal year, mixed depreciation methods and useful lives, linked to the `1400`
   asset account. After creating the assets it calls `DepreciationService::runForMonth()`
   for each elapsed month of the fiscal year so the depreciation schedule, `1500`/`5950`
   ledgers, and book values carry real data. Idempotent via `firstOrCreate` on `asset_code`
   (depreciation runs skip months whose entry is already posted for an asset). Registered
   in `DatabaseSeeder` after `PayslipSeeder`.

## Phase 12 — Bank Reconciliation (statement import, matching, workflow)

Reconciles the bank statement against the 1100 Cash/Bank ledger.

1. **Migrations**:
   - `bank_statements` — `account_id` FK (bank account), `statement_date`, `opening_balance`,
     `closing_balance`, `status` enum(`draft|in_progress|completed`), `completed_by`/`completed_at`.
   - `bank_statement_lines` — `bank_statement_id` FK, `transaction_date`, `description`,
     `reference`, `amount` (signed), `matched_line_id` nullable FK → `journal_entry_lines`,
     `match_status` enum(`unmatched|auto_matched|manually_matched|excluded`).
2. **`BankReconciliationService`**:
   - `import(array $rows, BankStatement $statement)` — CSV rows → statement lines.
   - `autoMatch(BankStatement $statement)` — match unreconciled ledger lines on the bank account
     by exact amount + date within ±3 days, then by amount + reference contains entry number;
     one-to-one only, ties left unmatched for manual review.
   - `match(line, ledgerLine)` / `unmatch(line)` — manual override, policy-gated.
   - `complete(BankStatement $statement, User $user)` — allowed only when every line is matched
     or excluded **and** closing balance equals ledger balance as of statement date
     (via `GeneralLedgerService`); stamps completed, locks lines from unmatching.
   - Reconciled ledger lines get `reconciled_at` (new nullable column on `journal_entry_lines`)
     so they are excluded from future auto-matching.
3. **Workflow & permissions** — `BankStatementImport/Match/Complete` permissions:
   Accountant imports and matches; Manager/CEO completes. All actions audit-logged.
4. **Nova resources** — `BankStatement` (status badge, lines panel, progress: matched/total,
   **Auto-Match** + **Complete Reconciliation** actions), `BankStatementLine` (match status badge,
   **Match/Unmatch/Exclude** inline actions showing candidate ledger lines).
5. **Tests** — import shape, auto-match exact/date-window/ambiguous-tie cases, manual match/unmatch,
   completion blocked while unmatched lines remain or balances disagree, reconciled lines
   excluded from re-matching.

## Phase 13 — Financial Reports (Trial Balance + Profit & Loss)

Turns the ledger into the two core statements. All figures come from posted
`journal_entry_lines` via the services — computed on demand, never stored.

1. **`FinancialReportService`**
   - `trialBalance($asOfDate, $fiscalYearId)` — thin wrapper over the existing
     `GeneralLedgerService::trialBalance()`: every account with activity, its debit or
     credit balance (per `normal_balance`), grouped by account type, with grand totals
     that must be equal — surfaced as an explicit `balanced` flag.
   - `profitAndLoss($from, $to, $fiscalYearId)` — revenue accounts (4xxx) minus expense
     accounts (5xxx) over the period: section per type with account lines and subtotals
     (Revenue, Expenses — payroll expenses `5000–5900`, depreciation `5950` fall out of
     the chart naturally), **Net Profit / (Loss)** line. Contra accounts respect
     `normal_balance`; only `posted` entries count.
   - Both accept an optional account-range filter and return a serializable DTO/array so
     Nova, PDF, and API all share one shape.
2. **Nova UI** — a **Reports** tool (or two Lenses on `Account`) with date-range /
   fiscal-year filters: Trial Balance page (unbalanced totals highlighted red) and
   P&L page (sectioned, net profit bolded). Both get a **Download PDF** action via the
   existing `spatie/laravel-pdf` (same pipeline as payslip PDFs).
3. **API** — `GET /api/reports/trial-balance?as_of=&fiscal_year_id=` and
   `GET /api/reports/profit-and-loss?from=&to=` (Sanctum + `ReportView` permission).
4. **Permissions** — `ReportView` added to `PermissionSeeder`; granted to Accountant,
   Manager, CEO, Administrator (not Employee).
5. **Tests** — trial balance totals equal after seeded payroll + depreciation entries;
   P&L net profit equals revenue minus expenses for a seeded period; draft/pending
   entries excluded; date-range boundaries inclusive; PDF endpoints return 200 and the
   permission gate blocks Employee.

---

## Build order & scope

**1 → 2 → 3–5 → 6–7 → 8 → 10 (Accounts API) → 11 (Fixed Assets) → 12 (Bank Reconciliation) → 13 (Financial Reports)**, tests throughout. Roughly 28 new files + edits to `PayslipService`, `PayslipPolicy`, Nova `Payslip` resource, `PermissionSeeder`, `RoleSeeder`, API routes, and two instantiation sites; one vendor package (`spatie/laravel-activitylog`).

## Open decision

Seed the 2025-26 tax rates against the currently active `2026-2027` fiscal year, **or** create a separate `2025-2026` fiscal year record for them?
