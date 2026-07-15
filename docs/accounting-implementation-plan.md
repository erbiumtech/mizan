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

---

## Build order & scope

**1 → 2 → 3–5 → 6–7 → 8**, tests throughout. Roughly 28 new files + edits to `PayslipService`, `PayslipPolicy`, Nova `Payslip` resource, `PermissionSeeder`, `RoleSeeder`, API routes, and two instantiation sites; one vendor package (`spatie/laravel-activitylog`).

## Open decision

Seed the 2025-26 tax rates against the currently active `2026-2027` fiscal year, **or** create a separate `2025-2026` fiscal year record for them?
