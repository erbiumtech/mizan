# MPR — Payroll & Accounting System

Internal HR, payroll, and double-entry accounting platform built on **Laravel 12**,
**Laravel Nova 5**, and **Spatie Permissions** (MySQL, PHP ≥ 8.4).

## Features

### Payroll & HR
- **Employee management** — profiles with designation, department, NIC, bank details
  (bank directory relation, account no, IBAN, addresses), linked to login users.
- **Monthly payslips** — earnings (basic, medical, petrol, device allowances, bonus,
  overtime) and deductions (withholding tax, advances, meals, ESI) computed by
  `PayslipService`; PDF download (Browsershot/Chromium).
- **Dynamic Pakistan income tax** — FBR salaried slabs stored per fiscal year
  (`TaxCalculatorService`); Finance Act changes are a re-seed, not a code change.
  Medical allowance 10% exemption handled.
- **Annual tax projections** — per-employee year-to-date and projected annual tax,
  synced automatically on every payslip change.
- **Monthly progress reports (MPR)** — employee self-service reports with PDF export
  and month-to-month comparison.
- **Employee self-service** — employees see only their own payslips/MPRs via role
  scoping, with comment threads on payslips.

### Double-Entry Accounting
- **Chart of accounts** — hierarchical (assets/liabilities/equity/income/expenses)
  with normal-balance handling, contra accounts, and roll-up balances.
- **Journal entries** — draft → pending approval → approved → posted workflow with
  **segregation of duties** (approver ≠ creator), balanced-entry validation, reversing
  entries (no in-place un-post), and auto numbering (`JE-YYYY-NNNNNN`).
- **General ledger** — computed running balances per account, trial balance, full
  ledger export (`GeneralLedgerService`); balances are never stored, so they can't drift.
- **Payroll → ledger integration** — payslips automatically book their payroll journal
  entries; delete/regenerate reverses cleanly.
- **Fixed assets** — asset register with straight-line and declining-balance monthly
  depreciation, disposal booking (gain/loss), book values, Nova actions.
- **Bank reconciliation** — statement import, auto-matching (amount + date window),
  manual match/exclude, completion locked to a balanced statement.
- **Transaction types** — Salary, Rent, Food, Utilities, Fuel, Cleaning, Office
  Supplies, Equipment, Tax Payment… each mapped to a default account; journal entries
  taggable by type.
- **Audit log** — every domain model change recorded (spatie/laravel-activitylog),
  browsable in Nova with per-record history panels; logs are immutable.

### Payments & Banking
- **Bank directory** — 36 Pakistani banks with IMD codes for IBFT (seeded from the
  SBP list), managed in Nova.
- **Company bank accounts** — the firm's own accounts, each earmarked for a purpose
  (Salary / Rent / Miscellaneous) with one default per type.
- **Beneficiaries** — non-employee payees (landlord, caterer, vendors) with bank
  details, CNIC/NTN, and per-payee default payment type.
- **Payments** — unified payment instructions (Employee or Beneficiary), with
  per-transaction iPayments **Payment Type resolution** (manual override → RTGS ≥ 1M →
  same-bank BT → beneficiary default → IBFT) and a draft → approved → exported → paid
  lifecycle; approval books the journal entry.
- **Bank payment file (Standard Chartered iPayments)** — one UTF-8, 204-column CSV
  mixing salaries, rent, and other payments (H header / P rows / T trailer), with
  month + type filters, preview, and missing-bank-detail warnings.
- **Petty cash book (imprest)** — classic two-sided book: float top-ups, vouchers
  analyzed into per-category columns, c/d balance carried down, and month-end
  replenishment paid to the custodian **through the bank payment file**.

### Reports & Tools (Nova sidebar → Reports)
- **Trial Balance** — sectioned by account type, balanced badge, PDF download.
- **Profit & Loss** — income vs expenses over any period, net profit line, PDF.
- **Account Register** — GnuCash-style single-screen entry: ledger with Transfer
  (counter-account) column and running balance, plus a quick-add row that books
  balanced 2-line entries instantly.
- **Bank Payment File** — build and download the iPayments CSV.
- **Petty Cash Book** — the two-sided monthly book with add-voucher and replenish.
- **GnuCash Import** — migrate existing books from GnuCash CSV exports (Account
  Tree, Transactions, Active Register), auto-detected, with dry-run preview and
  idempotent re-imports.
- **Salary bank export** — per-month salary CSV in the iPayments format.

### Security & Roles
Spatie permission-based policies everywhere. Default roles:

| Role | Can |
|---|---|
| **Employee** | Own payslips/MPRs/comments only |
| **Accountant** | Record entries, manage accounts/banks/payments/petty cash — cannot approve or post |
| **Manager** | Accountant + approve/reject/post/reverse, depreciation, petty cash replenishment |
| **CEO** | Manager + deletions |
| **Administrator** | Everything |

### API (Sanctum)
- `POST /api/login`
- `GET /api/my-profile`, `/api/my-payslips`, `/api/my-mprs` (+ comparison)
- `GET /api/accounts`, `/api/accounts/tree` (chart CRUD)
- `GET /api/reports/trial-balance`, `/api/reports/profit-and-loss`

## Getting Started

```bash
composer install
cp .env.example .env          # set DB_*, NODE_BINARY/NPM_BINARY, IPAYMENTS_*
php artisan key:generate
php artisan migrate --seed    # full demo dataset (employees, payslips, ledger, assets…)
php artisan serve
```

Log in to Nova at `/nova` (`admin@erbium.tech` / `password` in dev).

**PDF generation** needs Node ≥ 22 + Puppeteer — set `NODE_BINARY`/`NPM_BINARY` in
`.env` (nvm installs are not on the web server's PATH).

**Bank file settings** — `IPAYMENTS_DEBIT_ACCOUNT`, `IPAYMENTS_DEBIT_BANK_ID`,
`IPAYMENTS_PURPOSE_CODE`, etc. (see `config/ipayments.php`), and
`PETTY_CASH_FLOAT` for the imprest amount.

## Documentation

- `docs/accounting-implementation-plan.md` — the phased implementation plan
  (tax engine → ledger → reports → payments → petty cash → GnuCash import).
- Reference files: `docs/ipayments_csv (002).csv` (bank file template),
  `docs/IMD CODES IBFT.xlsx` (bank directory source).
