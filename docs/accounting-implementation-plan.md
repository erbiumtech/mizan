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

## Phase 14 — Bank Directory + Salary Bank File (iPayments)

Pays the month's net salaries through Standard Chartered iPayments using a bulk CSV
(template: `docs/ipayments_csv (002).csv` — 204 comma-delimited columns, UTF-8; `H`
header row, one `P` row per payment, `T` trailer with count + total).

1. **`banks` table + `Bank` model** — the IBFT bank directory from
   `docs/IMD CODES IBFT.xlsx`: `bank_code` (IMD code, unique), `bank_name`,
   `bank_short_code` (HBL/MCB/UBL…), `is_active`; `Auditable`; `employees()` HasMany.
   **`BankSeeder`** loads all 36 banks (idempotent via `bank_code`).
2. **Employee additions** — `bank_id` FK (nullOnDelete) + `bank()` BelongsTo replacing
   free-text bank entry; `address_line_1`/`address_line_2` (the iPayments Payee
   Address1/Address2 columns). Legacy `bank_name`/`bank_code` strings kept as fallback.
3. **Nova** — `Bank` resource (CRUD: Bank Code, Bank Name, Bank Short Code, Active,
   Employees panel) gated by `BankPolicy`; Employee form gains a searchable Bank
   BelongsTo and the address fields.
4. **Permissions** — `BankView/Create/Update/Delete`: Accountant manages the directory,
   CEO/Admin may delete (blocked while employees reference the bank).
5. **`SalaryBankExportService`** — builds the iPayments CSV for one month's payslips:
   maps net salary, IBAN (preferred) or account no, IMD bank code via the `bank`
   relation, addresses, NIC as CNIC beneficiary ID; company-side defaults in
   `config/ipayments.php` (`IPAYMENTS_*` env vars). Trailer totals must equal the
   payslip net-salary sum. **Salary Bank File** page under the Nova Reports menu
   (month + value-date pickers, preview with missing-account warnings, CSV download),
   gated by `ReportView`.
6. **Tests** — 204 fields per row, H/P/T structure, trailer count + total match,
   IBAN preferred over account no, values containing commas sanitized, UTF-8 output.

## Phase 15 — Transaction Types, Company Bank Accounts + Generalized Bank Payments

Categorizes money movement (rent, food, salary, utilities…), models the company's
own bank accounts (each earmarked for a purpose), and generalizes the Phase 14 salary
export into a bank payment file that can also pay non-employees (office rent to the
landlord, food vendors) with the iPayments Payment Type chosen per transaction.

1. **`transaction_types` table + `TransactionType` model** — `name` (unique: Salary,
   Rent, Food, Utilities, Fuel, Office Supplies, Equipment, Tax Payment, Miscellaneous),
   `code` (slug), `account_id` FK → default expense/liability account in the chart
   (e.g. Rent → `5700 Rent Expense`, Food → `5600 Meal Expense`), `description`,
   `is_active`; `Auditable`.
   - **Chart additions** (`ChartOfAccountsSeeder`): `5700` Rent Expense,
     `5750` Utilities Expense, `5800` Fuel & Travel Expense, `5850` Office Supplies
     Expense (Food/Meals already exists as `5600`).
   - **`TransactionTypeSeeder`** — the types above mapped to their default accounts;
     idempotent via `code`.
   - Nullable `transaction_type_id` FK on `journal_entries` — set explicitly on manual
     entries and by services (payroll posting → Salary, depreciation → Equipment);
     enables per-category filtering and a spend-by-type report slice later.
2. **`company_bank_accounts` table + `CompanyBankAccount` model** — the firm's own
   accounts that bulk payments debit from: `bank_id` FK (Phase 14 directory), `title`,
   `account_no`, `iban`, **`account_type`** — a `transaction_type_id` FK (an account
   earmarked for Salary, Rent, or Food payments), `is_default` (per type),
   `is_active`; `Auditable`.
   - **iPayments integration**: `SalaryBankExportService` resolves the debit account
     from the default *Salary* company bank account (fallback: `IPAYMENTS_DEBIT_ACCOUNT`
     env). Future rent/food payment files reuse the same resolution by type.
3. **Nova** — `TransactionType` resource (name, code, default account BelongsTo, badge
   for active) and `CompanyBankAccount` resource (bank BelongsTo, masked account no,
   type Select from transaction types, default toggle); `JournalEntry` form gains an
   optional Transaction Type select.
4. **Permissions** — `TransactionTypeView/Create/Update/Delete` and
   `CompanyBankAccountView/Create/Update/Delete`; Accountant view/create/update,
   CEO/Admin delete. Deletion blocked while journal entries (type) or payment files
   (account) reference them.
5. **`beneficiaries` table + `Beneficiary` model** — payees who are **not employees**
   (office landlord, caterer, utility vendors) but must be paid through the same bank
   file: `name`, `bank_id` FK (Phase 14 directory), `account_no`, `iban`,
   `id_type`/`id_number` (CNIC or NTN), `address_line_1/2`, `email`, `phone`,
   `transaction_type_id` FK (what we usually pay them for — Rent, Food…),
   **`payment_type`** enum (`IBFT|BT|ACH|RTGS|LBC`, default per beneficiary),
   `is_active`; `Auditable`. Nova CRUD (`BeneficiaryView/Create/Update/Delete`
   permissions, Accountant manages).
6. **`payments` table + `Payment` model** — one bank-file row-to-be: `payable`
   morph (**Employee or Beneficiary**), `transaction_type_id`, `amount`,
   `reference`/`details` ("Office Rent July 2026"), `value_date`,
   **`payment_type`** (resolved per transaction: beneficiary default → override
   allowed; auto-suggest **RTGS** ≥ 1,000,000, **BT** when beneficiary bank =
   debiting bank, else **IBFT**), `company_bank_account_id` FK (debit side, defaults
   to the type's earmarked account), `status` enum (`draft|approved|exported|paid`),
   nullable `journal_entry_id` (books debit expense-account-of-type / credit bank on
   approval); `Auditable`. Salary payments are generated from payslips (one Payment
   per payslip, payable = Employee, type = Salary); other payments entered manually
   in Nova ("pay office rent to the landlord").
7. **`BankPaymentExportService`** (generalizes Phase 14's `SalaryBankExportService`) —
   builds one iPayments file from a set of `Payment` rows filtered by month +
   transaction type(s): the **Payment Type column (col 2) comes from each payment's
   `payment_type`**, not a global config; debit account (col 9) from each payment's
   company bank account; beneficiary columns from the Employee or Beneficiary record
   (name, bank IMD code, account/IBAN, CNIC/NTN with matching ID type, addresses,
   contact). H/T rows unchanged; T total = sum of included payments. Marks exported
   payments `exported`.
8. **Nova/UI** — `Beneficiary` and `Payment` resources; the **Salary Bank File**
   page becomes **Bank Payment File**: month + transaction-type filter (Salary only,
   Rent only, or combined), per-row Payment Type shown and editable while `draft`,
   preview totals per type, CSV download.
9. **Demo seed data** — registered in `DatabaseSeeder` after `BankSeeder` +
   `TransactionTypeSeeder`:
   - **`CompanyBankAccountSeeder`** — the company's operating accounts: a default
     *Salary* account (SCB, `IPAYMENTS_DEBIT_ACCOUNT`-style number), a *Rent* account
     and a general *Miscellaneous* account, each linked to its bank (Phase 14
     directory) and transaction type, `is_default` set. Idempotent via
     `updateOrCreate` on `account_no`.
   - **`BeneficiarySeeder`** — realistic non-employee payees: office landlord (Rent,
     CNIC, IBAN, address), caterer (Food), internet/utility provider (Utilities, NTN),
     fuel station (Fuel) — each with bank from the directory and a sensible default
     payment type. Idempotent via `firstOrCreate` on `name`.
10. **Tests** — non-employee beneficiary (landlord) exports correctly alongside
   salaries; payment-type resolution rules (RTGS threshold, same-bank BT, IBFT
   default, manual override wins); debit account follows transaction type; exported
   rows keep 204 columns; status transitions draft → exported.
11. **Other tests** — seeder idempotency; default-account mapping; only one default
   company account per type (enforced on save); salary export debits the Salary
   account; journal entries filterable by type.

## Phase 16 — Account Register (GnuCash-style single-screen transaction entry)

Modeled on the checking-account register screenshot (`docs/` reference): one UI where
**all** day-to-day transactions are entered against a bank/cash account — bills,
utilities, dining, salaries, rent, income — each row a Date / Num / Description /
**Transfer** (counter-account) / Debit / Credit line with a **running balance**.

1. **Register page** (Blade, linked from the Nova sidebar like the Reports pages) —
   one register per debit-side account:
   - **Account tabs** across the top (like GnuCash's "Accounts | Checking Account"):
     one tab per active `CompanyBankAccount` (backed by `1100 Cash/Bank` or its own
     GL sub-account) — selecting a tab shows that account's register.
   - **Ledger table** from `GeneralLedgerService::accountLedger()`: date, entry number
     (Num), description/memo, Transfer column showing the *other* account of the entry
     as a `Type:Account` path (e.g. `Expenses:Utilities:Electric` ≈
     `expense → 5750 Utilities`), debit, credit, **running balance**; date-range filter.
   - **Quick-add row pinned at the bottom**: date (defaults today), description,
     **Transfer select** — searchable, grouped by account type and showing the
     hierarchy path, defaulting from the chosen transaction type — amount entered in
     either the Debit or Credit column (money-in vs money-out of the bank account),
     optional transaction type tag (auto-inferred from the transfer account's
     `transaction_types` mapping when unique).
2. **`RegisterEntryService`** — turns one register row into a balanced 2-line journal
   entry via `JournalEntryService`: credit bank / debit transfer account for money-out,
   inverse for money-in; stamps `transaction_type_id`; entries are **auto-approved and
   posted** (same treatment as depreciation's system entries) so the running balance
   updates immediately — full multi-line entries still go through the JournalEntry
   workflow for anything the quick row can't express.
3. **Permissions** — reuse `JournalEntryCreate` for adding rows; a new
   `RegisterPost` permission gates the auto-post shortcut (Accountant+); the page
   itself requires `JournalEntryView`.
4. **Niceties** — keyboard-first entry (Enter saves and starts the next row),
   duplicate-last-row action, per-row link to the underlying journal entry in Nova,
   reconciled flag column (`R`) fed by Phase 12's `reconciled_at`.
5. **Tests** — money-out row books credit-bank/debit-expense and running balance
   drops; money-in (income) row is the inverse; transfer select excludes
   non-postable accounts; auto-post requires `RegisterPost`; register totals agree
   with `GeneralLedgerService` for the same range.

## Phase 17 — GnuCash Import (CSV)

Migrates existing GnuCash books into the engine using GnuCash's own CSV exports
(File → Export): **Account Tree to CSV**, **Transactions to CSV**, and
**Active Register to CSV**.

1. **`GnuCashImportService`** with one importer per export format:
   - `importAccountTree(rows)` — GnuCash account-tree CSV (`Type, Full Account Name,
     Name, Code, Description, …, Hidden, Placeholder`): maps GnuCash types to our
     enum (ASSET/BANK/CASH → asset, CREDIT/LIABILITY → liability, INCOME → income,
     EXPENSE → expense, EQUITY → equity), builds the `parent_id` hierarchy from the
     colon-separated *Full Account Name* (`Expenses:Utilities:Electric`), creates
     missing parents as non-postable placeholders, assigns codes (uses GnuCash's code
     when present, else next free code in the type's range), `Placeholder` →
     `allow_manual_entry = false`. Idempotent: match on full name path, then code.
   - `importTransactions(rows)` — GnuCash transactions CSV (multi-row per
     transaction: `Date, Transaction ID, Number, Description, …, Full Account Name,
     Amount Num., Rate/Price…`): groups rows by *Transaction ID* into one
     `JournalEntry` (memo = Description, `entry_date` = Date, reference = Number),
     each split row becomes a line (positive amount → debit, negative → credit on
     that account); validates each group balances before creating via
     `JournalEntryService`; entries are imported as **approved + posted** (they are
     historical facts), tagged `entry_type = general` and source-marked as
     `gnucash-import`. Idempotent via a `gnucash_id` column (new nullable string on
     `journal_entries`, unique) storing the Transaction ID.
   - `importActiveRegister(rows)` — the single-account register export (Date, Num,
     Description, Transfer, R, Debit, Credit): the target account is chosen at
     upload; each row books a 2-line entry against the *Transfer* account (resolved
     by full name path, auto-created via `importAccountTree` rules when missing) —
     reuses Phase 16's `RegisterEntryService`; `R = y` sets `reconciled_at`.
2. **Duplicate & error handling** — dry-run mode returns a preview (rows parsed,
   accounts to create, entries to book, duplicates skipped, unbalanced groups
   rejected with row numbers); nothing writes until confirmed. All writes in one DB
   transaction per file.
3. **UI** — **GnuCash Import** page under the Nova sidebar (same Blade pattern):
   upload CSV + import-kind select (auto-detected from the header row), dry-run
   preview table, Confirm Import button; per-import activity-log event with counts.
   Gated by a new `GnuCashImport` permission (Administrator + Accountant).
4. **Config/notes** — GnuCash amounts are signed decimals with locale separators
   (`1,385,022.98`) — parser strips thousands separators; dates accepted as
   `dd/mm/yyyy` and `yyyy-mm-dd`. Multi-currency rows rejected with a clear error
   (engine is single-currency PKR).
5. **Tests** — account-tree hierarchy import (3-level path), type mapping, transaction
   grouping balances, signed-amount to debit/credit conversion, register import with
   auto-created transfer account, duplicate `gnucash_id` skipped on re-import,
   dry-run writes nothing, unbalanced group rejected.

## Phase 18 — Petty Cash Book (imprest system)

Classic two-sided petty cash book (per the reference sheet): a *Received* side for
float top-ups, a *Paid* side where every voucher is analyzed into a column per
expense category (Cleaning, Stationery, Travel…), the month closed with a **c/d
balance carried down**, and the float restored by a month-end **replenishment paid
from a company bank account to the petty-cash custodian through the salary bank
file**.

1. **Chart + custodian**
   - New account `1150 Petty Cash` (asset) in `ChartOfAccountsSeeder`.
   - New transaction type `petty-cash-replenishment` (mapped to `1150`).
   - The **custodian is a `Beneficiary`** (Phase 15) flagged via new
     `is_petty_cash_custodian` boolean — their bank details receive the monthly
     top-up alongside salaries.
   - `config/petty_cash.php`: `float_amount` (the imprest, e.g. 4,000) and custodian
     defaults.
2. **`petty_cash_vouchers` table + model** — one Paid-side row each: `voucher_no`
   (auto `PCV-YYYY-NNNN`), `date`, `details`, `amount`,
   `transaction_type_id` (the analysis column: Cleaning → new type, Stationery →
   Office Supplies, Travel → Fuel/Travel…), nullable `receipt_path` (photo of the
   chit), `journal_entry_id`; `Auditable`. Saving books a posted 2-line entry —
   debit the type's expense account, credit `1150` — via `RegisterEntryService`
   (Phase 16 treatment: system-posted).
3. **`PettyCashService`**
   - `bookVoucher(data)` — creates voucher + entry (validates float not overdrawn).
   - `monthSummary($month)` — the book layout: received (b/d + top-ups), paid rows
     with one column per transaction type used, per-column totals, **c/d closing
     balance** = received − paid (the ledger `1150` balance must agree).
   - `replenish($month)` — month-end close: amount = float − closing balance
     (restores the imprest). Creates a **`Payment`** (Phase 15): payable = custodian
     Beneficiary, type = `petty-cash-replenishment`, **debits the company bank
     account** (the type's earmarked account or the Salary default), details
     "Petty cash replenishment {month}". The payment rides in the month's **bank
     payment file with the salaries**; on approval it books debit `1150` / credit
     `1100`. Idempotent — one replenishment per month.
4. **UI** — **Petty Cash Book** page (Nova sidebar, Blade pattern): month picker;
   two-sided layout exactly like the reference — left Received (b/d, top-ups),
   right Paid with dynamic analysis columns and totals row; c/d line; **Add
   Voucher** quick-row; **Replenish Month** button (Manager/CEO) showing the
   computed top-up before confirming. Nova `PettyCashVoucher` resource for
   browsing/receipt uploads.
5. **Permissions** — `PettyCashView/Create` (Accountant+), `PettyCashReplenish`
   (Manager/CEO). Vouchers immutable once their month is replenished.
6. **Demo seed data** — registered in `DatabaseSeeder` after `BeneficiarySeeder` +
   `PayslipSeeder`:
   - **`BeneficiarySeeder` addition** — an "Office Boy / Petty Cash Custodian"
     beneficiary (`is_petty_cash_custodian = true`) with bank details from the
     directory, so the replenishment payment has somewhere to land.
   - **`PettyCashSeeder`** — for each elapsed month of the active fiscal year:
     an opening float top-up (4,000), then a handful of dated vouchers spread
     across the month mirroring the reference sheet (Cleaning 1,000 · Stationery
     1,000 · Travel 500 · Cleaning 1,000), booked through
     `PettyCashService::bookVoucher()` so the `1150` ledger entries are real;
     closed months are then replenished via `PettyCashService::replenish()` so
     the demo bank payment file shows the custodian's top-up next to salaries and
     the new month opens with the correct b/d. Idempotent: skips a month that
     already has vouchers; deterministic amounts (no randomness).
7. **Tests** — voucher books debit-expense/credit-1150; column totals and c/d match
   the reference sheet's math (4,000 received, 3,500 paid, 500 c/d, 3,500
   replenished); replenishment Payment lands in the bank payment file next to
   salaries and restores the float; second replenish call for the same month is
   rejected; overdrawn float blocked.

## Phase 19 — Inventory (stock tracking + FIFO / LIFO / average-cost + COGS posting)

Stock on hand valued by a configurable method, with every movement booking a
balanced journal entry automatically.

1. **Chart additions** (`ChartOfAccountsSeeder`): `1300` Inventory (asset),
   `4200` Sales Revenue (income), `5050` Cost of Goods Sold (expense).
2. **Migrations**
   - `products` — `sku` (unique), `name`, `description`, `unit` (pcs/kg/…),
     **`valuation_method`** enum (`fifo|lifo|average`), `reorder_level`,
     `inventory_account_id` / `cogs_account_id` / `revenue_account_id` FKs
     (default 1300/5050/4200), `is_active`.
   - `stock_movements` — `product_id` FK, `type` enum (`purchase|sale|adjustment`),
     `quantity` (signed), `unit_cost` (purchases/adjustments), `unit_price` (sales),
     `movement_date`, `reference`, nullable `journal_entry_id`, nullable
     `source_type`/`source_id` morphs (an invoice line owns its movement),
     `remaining_quantity` (per-lot tracker for FIFO/LIFO consumption), timestamps.
3. **`InventoryValuationService`** — the costing engine, pure and testable:
   - `costOfSale(Product, qty)` — consumes purchase lots by the product's method:
     **FIFO** (oldest lots first), **LIFO** (newest first) — decrementing
     `remaining_quantity` per lot — or **average cost** (running weighted average of
     on-hand stock, no lot consumption).
   - `onHand(Product)` / `stockValue(Product)` — quantity and value as of a date;
     value must always reconcile with the `1300` ledger.
4. **`InventoryService`** — movements + automatic postings via `JournalEntryService`
   (system-posted like depreciation):
   - `purchase(product, qty, unitCost, date)` — movement + entry: debit `1300`,
     credit `1100` Cash/Bank (or `2400` Accounts Payable when invoice-linked).
   - `sale(product, qty, unitPrice, date)` — two balanced legs in one entry:
     revenue (debit `1100`/receivable, credit `4200` at price) and **COGS**
     (debit `5050`, credit `1300` at the valuation-engine cost).
   - `adjust(product, qty, reason)` — write-off/count corrections (debit `5050` or
     credit as appropriate). Negative-stock guarded.
5. **Nova UI** — `Product` resource (SKU, valuation method badge, on-hand qty,
   stock value, reorder warning) with **Receive Stock** / **Record Sale** /
   **Adjust Stock** actions; `StockMovement` resource (read-only, links to its
   journal entry); low-stock dashboard metric.
6. **Permissions** — `ProductView/Create/Update/Delete`, `StockMove` (Accountant+),
   `StockAdjust` (Manager/CEO). Product deletion blocked while movements exist.
7. **`InventorySeeder`** — demo products (laptops, cables, paper, toner) across all
   three valuation methods, with a purchase/sale history through elapsed months so
   FIFO vs LIFO vs average produce visibly different COGS; registered in
   `DatabaseSeeder` after `PettyCashSeeder`. Idempotent via `sku`.
8. **Tests** — FIFO consumes oldest lot; LIFO newest; average recomputes on each
   purchase; COGS entry balances and hits 5050/1300; stock value reconciles with the
   1300 ledger; negative stock blocked; each method's textbook example verified.

## Phase 20 — Invoices (customer & supplier invoicing + ledger posting)

Line-item invoicing on both sides (sell to customers, buy from suppliers), posting
balanced entries and driving inventory movements.

1. **Chart additions**: `1250` Accounts Receivable (asset), `2400` Accounts Payable
   (liability, if not present), `4300` Other Income kept for non-product lines.
2. **Migrations**
   - `contacts` — unified customer/supplier directory: `name`, `kind` enum
     (`customer|supplier|both`), `email`, `phone`, `address_line_1/2`, `ntn`/`cnic`,
     `bank_id` FK nullable (pay suppliers through the Phase 15 payment flow),
     `is_active`.
   - `invoices` — `invoice_number` unique (`INV-YYYY-NNNNNN` sales /
     `BILL-YYYY-NNNNNN` purchases), `kind` enum (`sale|purchase`), `contact_id` FK,
     `invoice_date`, `due_date`, `status` enum (`draft|issued|partially_paid|paid|void`),
     `subtotal`, `tax_amount`, `total`, `amount_paid`, `memo`,
     nullable `journal_entry_id`, `fiscal_year_id` FK.
   - `invoice_lines` — `invoice_id` FK cascade, nullable `product_id` FK (service
     lines allowed), `description`, `quantity`, `unit_price`, `line_total`,
     nullable `account_id` override (non-product lines post here).
3. **`InvoiceService`**
   - `issue(Invoice)` — validates lines sum to totals, then posts one balanced entry:
     **sale**: debit `1250` A/R (total), credit `4200` per product line / line-account
     override, credit `2100` for tax; product lines also fire
     `InventoryService::sale()` so COGS books at the same time.
     **purchase**: debit `1300` (product lines, creating purchase lots) or expense
     account per line, debit tax, credit `2400` A/P.
   - `recordPayment(Invoice, amount, date)` — debit `1100` / credit `1250` (sales)
     or debit `2400` / credit `1100` (purchases); supplier payments can instead
     create a Phase 15 `Payment` (payable = supplier contact via morph) so they ride
     the bank payment file; status transitions to `partially_paid`/`paid`.
   - `void(Invoice)` — reverses the posting entry (and inventory movements) via the
     reversing-entry mechanism; only Manager/CEO.
   - Aging helpers: `outstandingReceivables()` / `outstandingPayables()` grouped by
     30/60/90 buckets for a future report.
4. **Nova UI** — `Contact` resource (kind badge, invoices panel); `Invoice` resource
   with inline `InvoiceLine` HasMany (product picker auto-fills price/description),
   status badge, **Issue**, **Record Payment** (amount prompt), **Void** actions,
   PDF download via the existing spatie/laravel-pdf pipeline; unpaid-invoices
   dashboard metric.
5. **Permissions** — `ContactView/Create/Update/Delete`,
   `InvoiceView/Create/Update/Issue/Pay/Void` (Accountant creates/issues/pays;
   Manager/CEO void; Employee none).
6. **Seeders** — `ContactSeeder` (a few customers incl. "4sure AG", suppliers for
   the inventory products) and `InvoiceSeeder` (issued + paid sales invoices and
   supplier bills across elapsed months, driving the Phase 19 purchase lots and
   sales so the A/R, A/P, revenue, COGS, and inventory ledgers all carry consistent
   demo data); registered after `InventorySeeder`. Idempotent via `invoice_number`.
7. **Tests** — issue posts balanced entries on both kinds; product sale books COGS
   via the product's valuation method; payment transitions and amounts; void
   reverses ledger + stock; totals validation rejects mismatched lines; aging
   buckets; seeder idempotency.

---

## Build order & scope

**1 → 2 → 3–5 → 6–7 → 8 → 10 (Accounts API) → 11 (Fixed Assets) → 12 (Bank Reconciliation) → 13 (Financial Reports) → 14 (Bank Directory + Salary Bank File) → 15 (Transaction Types + Company Bank Accounts) → 16 (Account Register) → 17 (GnuCash Import) → 18 (Petty Cash Book) → 19 (Inventory) → 20 (Invoices)**, tests throughout. Roughly 28 new files + edits to `PayslipService`, `PayslipPolicy`, Nova `Payslip` resource, `PermissionSeeder`, `RoleSeeder`, API routes, and two instantiation sites; one vendor package (`spatie/laravel-activitylog`).

## Open decision

Seed the 2025-26 tax rates against the currently active `2026-2027` fiscal year, **or** create a separate `2025-2026` fiscal year record for them?
