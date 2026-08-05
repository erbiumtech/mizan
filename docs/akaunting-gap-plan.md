# What Akaunting has that we do not: a plan

Read against [akaunting/akaunting](https://github.com/akaunting/akaunting) at
`master` by its actual surface — controllers, models, `app/Reports` — plus the two
paid apps that carry the features closest to ours:
[Expense Claims](https://akaunting.com/apps/expenses) and
[Payroll](https://akaunting.com/apps/payroll).

**Correction to the first version of this document.** It read core only and
concluded there was nothing to learn about payroll or expenses, with a caveat that
paid apps would not show up. That caveat was doing real work: both features exist,
both are sold at $60/year on-premise, and Expense Claims describes a workflow we
have no equivalent of. §3 is new and changes the ranking.

The headline still holds. Akaunting core is a **single-entry, cash-basis book**:
money is a `Transaction` against an `Account`, tagged with a `Category`, and its
"Profit & Loss" sums those. No journal, no double entry, no trial balance, nothing
that *can* be out of balance. We have a posted general ledger, a reconciling trial
balance, fiscal-year closing and an Opening Balance Equity check. **We are not
behind on accounting. We are behind on the paperwork around it, and on how
configurable our payroll is.**

## 1. Core surface, theirs against ours

| Akaunting core | Here | Verdict |
|---|---|---|
| Banking: Accounts, Transactions, Transfers, Reconciliations, RecurringTransactions | Accounts, Payments, bank statements + reconciliation, `BeneficiarySubscription` + `RaiseSubscriptionPayments` | Transfers and general recurring missing |
| Sales: Customers, Invoices, RecurringInvoices | Contacts, Invoices (`kind=sale`) | Recurring invoices missing |
| Purchases: Vendors, Bills, RecurringBills | Invoices (`kind=purchase`, numbered `BILL-…`) | Covered |
| `Document`, `DocumentItem`, `DocumentItemTax`, `DocumentTotal`, `DocumentHistory` | Invoice, InvoiceLine | **Per-line tax and document history missing** |
| Settings: Taxes | — | **No tax-rate entity at all** |
| Settings: Currencies | — | **Single currency** (only `BillingRun` quotes a second) |
| Settings: EmailTemplates | Hard-coded notifications | Missing |
| Settings: Categories | TransactionType | Covered, differently |
| Common: Items | Product + StockMovement | Covered, better — we track stock |
| Common: Import | GnuCash import only | **No general CSV import** |
| `app/Reports`: Income / Expense / IncomeExpense / Tax / Discount summary, P&L | Trial Balance, P&L, Account Register, Petty Cash Book, bank files, FBR file | **No Balance Sheet, no cash flow statement, no tax summary** |
| Common: Dashboards, Widgets | Filament dashboard, 8 widgets | Covered |
| Portal: Dashboard, Invoices, Payments, Profile | — | Out of scope — see §6 |
| `ContactPerson` | — | One contact per company |
| Modules + App Store | Module licensing per company, enforced on routes and the Gate | Ahead |
| Multi-company | Database per company, super admin, impersonation | Ahead |
| — | Payroll, withholding tax, advances, MPR, projects, client billing | Ahead; none of it in core |

## 2. Already written here, and unreachable

Cheapest item in the document, so it goes first.

`InvoiceService::outstandingReceivables()` and `outstandingPayables()` bucket open
invoices into current / 31–60 / 61–90 / 90+. The only caller in the repository is
`InvoiceTest` — **no page, route, widget or endpoint exposes either.** Aged
receivables and payables are two Blade pages over code that already works.

## 3. The paid apps

### 3.1 Expense Claims ($60/yr) — a workflow we do not have

What it does: an employee records an expense, attaches the receipt, marks it *paid
by employee*, and sends it to a named **approver**, who approves or **refuses with
a reason**. Claims carry due dates, are categorised, are reported per employee, and
a dashboard shows outstanding / approved / pending.

What we have, and it is closer than it looks:

- `payslips.expense_reimbursement` — the **destination already exists**. Money
  reaches the employee through payroll today; what is missing is the front door.
- `EmployeeChangeRequest` — a submit → notify approver → approve/reject-with-reason
  workflow, **already built and tested**, on a different subject.
- `PettyCashVoucher` — the office-cash side of the same problem.
- `TransactionType` — the categories.
- `TenantStorage` — per-company file storage for the receipt.

So the gap is one entity and a screen, over four things that exist. Today a
reimbursement is a number typed into a payslip: no receipt, no approver, no record
of what it was for, and nothing to show an auditor who asks why an employee was
paid 25,000 more in March. For a company whose expense ledger includes *AC gas and
kitchen exhaust*, *dinner* and *paddle court*, this is the highest
value-per-day item in the document.

### 3.2 Payroll ($60/yr) — we are ahead, except in three specific ways

| Their payroll app | Here | Verdict |
|---|---|---|
| Unlimited employees **and contractors** | Employees only | Contractors missing |
| **Pay calendars**: weekly, bi-weekly, monthly, per employee, with recurrence and set pay dates | `payslips.month` is a **month name string**; `PayrollMonth::firstDay()` parses `"{$month} 1, {$year}"` | **Month-locked. Weekly or bi-weekly is impossible without rework** |
| **Benefits and deductions as customisable lists** — bonuses, commissions, allowances, advance pay, loans | Fixed columns: `basic_wage`, `medical_allowance`, `device_allowance`, `petrol_allowance`, `extra_work_hours`, `bonus`; deductions `withholding_tax`, `advances`, `meal_deduction`, `esi_health_insurance` | **Structural gap — see below** |
| Create, edit, **approve** or remove a pay calendar; run payroll | No pay-run entity; payslips are independent rows | No run to approve or lock |
| Payslips auto-generated, printable/PDF | ✓, rendered on demand, never stored | Covered |
| Journal entries, categorised as expenses | `PayrollPostingService` posts a full double-entry payslip | Ahead |
| Payment methods per employee | Bank accounts, IBFT bank files, FBR tax file | Well ahead |
| Auto-send payment notifications | Email with PDF + WhatsApp on release, acknowledgement, resend | Well ahead |
| Attachments on records | Partial | Minor |
| Employee self-service | Payslip accept/reject with reason, change requests, API | Ahead |

**Why the fixed columns matter, measured.** Adding one allowance today means
editing every one of these — 13 files, excluding migrations:

```
MonthlyBillingService            EmployeeSettingForm        EmployeeSettingsTable
EmployeeChangeRequest            EmployeeSetting            PayslipForm
PayslipsTable                    Payslip                    PayrollPostingService
PayslipService                   EmployeeSettingSeeder      RealMonthlyBillingSeeder
resources/views/pdfs/payslip.blade.php
```

That is not a tidiness complaint. Two symptoms are already in the codebase: the
client statement carries a hand-maintained `SALARY_COLUMNS` map with an **"Other"
column invented to catch any gross the named columns cannot explain**, and
`PayrollAccounts` maps each component to an account by a hard-coded key. A
components table — code, label, kind (earning/deduction), account, taxable,
per-employee default — collapses 13 edits into one row, and the statement's
"Other" column stops being necessary.

## 4. The plan

Each item: what, why now, the sketch, the risk, and what proves it done.

### Phase 1 — Finish the reporting set · ~1 week

1. **Aged receivables / payables pages.** Surface §2's dead code. Filament page +
   `reports/{company}/…` route + `?format=pdf`, same shape as the Trial Balance.
   *Risk: none. Done when: a 95-day-overdue invoice appears in the 90+ bucket on a
   rendered page and in the PDF.* **½ day.**
2. **Balance Sheet.** The conspicuous hole: a trial balance and no statement of
   position, which is the first report an auditor asks for. Inputs are all in
   `GeneralLedgerService`; the work is asset/liability/equity sectioning and
   retained earnings. *Risk: getting retained earnings wrong across a closed
   fiscal year. Done when: assets = liabilities + equity, and the totals tie to the
   trial balance for the same date — that is the test.* **2–3 days.**
3. **Cash flow statement.** We ship a `CashFlowChart` widget and no statement.
   Indirect method from the ledger. *Done when: closing cash equals the 1100
   balance on the Balance Sheet for the same date.* **2 days.**
4. **Tax summary.** Withholding by employee and month already exists in
   `EmployeeWithholdingTaxExport`; this is the same data as a report page.
   **1 day.** *Note: worth little until item 6 exists — this covers payroll tax,
   not sales tax.*

### Phase 2 — Expense claims · ~1 week

5. **`ExpenseClaim` + lines + approval.** Employee submits with a receipt, names an
   approver, approver approves or refuses with a reason; an approved claim either
   flows to `payslips.expense_reimbursement` or raises a Payment.
   *Sketch: `expense_claims` (employee_id, transaction_type_id, claimed_on,
   description, amount, status, approver_id, decided_at, refusal_reason,
   receipt_path, payslip_id/payment_id); reuse `EmployeeChangeRequest`'s
   notify-approver pattern verbatim; reuse `EmployeeAccess` so a manager sees their
   downline's claims and nobody else's.*
   *Risk: low — no posting change; the reimbursement route already exists and is
   already posted by payroll. Done when: an employee's claim reaches an approver,
   a refusal carries its reason, and an approved claim lands on a payslip exactly
   once (idempotent, like `AdvanceRecovery`).* **4–5 days.**

### Phase 3 — Make invoices real documents · 1–2 weeks

6. **Tax rates as an entity.** `invoices.tax_amount` is a free decimal — a number
   somebody types, tied to no rate, provable against nothing. Needs `tax_rates`,
   per-line tax, inclusive/exclusive handling, and posting to a tax-payable
   account. **Prerequisite for item 4 meaning anything, and for invoicing anyone
   who charges sales tax.** *Risk: highest in the document — it changes invoice
   posting, and existing invoices must keep their totals. Done when: an existing
   invoice's total is unchanged by the migration, and a 15% line proves out in the
   trial balance.* **4–5 days.**
7. **Document history.** Their `DocumentHistory` records issued / sent / viewed /
   paid per document. Our activity log records *changes*, not events in a
   document's life, so "when was this sent, and did they open it?" — the first
   question on a late payment — is unanswerable. **2 days.**
8. **Bank transfers as an action.** Moving money between own accounts is a
   two-line entry anyone posts by hand today, which means it is posted
   inconsistently. Fixed posting, one action. **1–2 days.**

### Phase 4 — Payroll configurability · 2–3 weeks, highest leverage

9. **Pay components as data.** The 13-file problem in §3.2. `pay_components`
   (code, label, kind, account_id, is_taxable, sort) + `employee_pay_components`
   for per-employee amounts + `payslip_components` for what was actually paid.
   *Risk: real — it is a migration of live payroll data, and `total_earnings`
   must not move for any existing payslip. Do it with the columns still present,
   backfilled and cross-checked, then drop them in a later release. Done when:
   every existing payslip's gross and net are identical before and after, and
   adding an allowance touches one row and zero files.* **1.5–2 weeks.**
   *Pays for itself immediately: `MonthlyBillingService::SALARY_COLUMNS` and its
   "Other" column both disappear.*
10. **Pay runs.** A `PayrollRun` per period with a status, so a month can be
    approved and **locked** — today any payslip can be edited after it has been
    sent, and only `sent_at` hints that it should not be. *Depends on nothing;
    complements the send flow.* **3–4 days.**
11. **Pay calendars.** Weekly and bi-weekly. Requires payroll to key on a period
    (start/end dates) rather than a month name — `PayrollMonth` is the seam.
    **Only if anyone is actually paid other than monthly.** **1 week.**
12. **Contractors.** A payee that is not an employee: no payslip components, no
    withholding, but bank files and 1099-equivalent reporting. **2–3 days.**

### Phase 5 — Multi-currency · 2–3 weeks, and probably not yet

13. Currency and rate on accounts, documents, payments **and the ledger**, plus
    realised/unrealised FX accounts and period-end revaluation. The largest item
    here and the easiest to get subtly wrong. Books in PKR with one client quoted
    in EUR at a monthly agreed rate is honest and works. **Defer until a second
    currency has to be *booked*, not quoted.**

    **Shipped in full, against this recommendation and on the owner's decision.**
    What the advice above was protecting is preserved by one rule: `debit_amount`
    and `credit_amount` are *always* the base currency, so every report in the
    codebase reads them unchanged and no `SUM()` anywhere mixes currencies. The
    foreign amount and the rate sit alongside on the line. What is not done, and
    deliberately: **outgoing bank payments stay base-currency.** The SCB iPayments
    file is a rupee file, and a foreign supplier is invoiced as a bill, which is
    covered — so the only gap is paying a beneficiary in euros with no document,
    which the bank file could not carry anyway.

### Where this stands

Items 1–10, 12 and 13–16 are shipped; **item 11 (pay calendars) is not, by its own
gate — nobody is paid other than monthly.** Two clean-ups the plan created and left:
the eleven shipped pay components still have their old columns beside them, to be
dropped in a later release once every payslip has been cross-checked, and
`MonthlyBillingService::SALARY_COLUMNS` with its "Other" bucket goes with them.

### Ongoing, small

14. **CSV import** — contacts, products, opening balances, bank lines. We have the
    hard version (GnuCash); this is the version people need at setup. **2–3 days.**
15. **Email templates as settings.** Notification text is in PHP. Matters once a
    client reads the emails. **2 days.**
16. **Contact persons.** Several named people per client. **1 day.**

## 5. Order, and the decisions that change it

```
Phase 1 (1–4)  ──► Phase 2 (5) ──► Phase 4 item 9 ──► Phase 4 items 10–12
                          │
                          └──► Phase 3 (6–8) ──► Phase 5 (multi-currency)
```

Two decision rules, so this does not need re-litigating:

- **Item 6 (tax rates) jumps the queue if anyone is charged sales tax.** Nothing
  else unblocks correct invoicing or a meaningful tax report.
- **Item 9 (pay components) goes before anything else that touches a payslip
  column.** Every feature added on top of the fixed columns raises 13 to 14.

Recommended next: **Phase 1 in full, then item 5.** Both are additive, neither
touches posting, and together they close the two things somebody notices monthly —
no statement of position, and reimbursements with no paper trail.

Phase 5 is larger than everything above it combined, and should not start until a
second currency actually has to be booked rather than quoted.

## 6. What not to take from Akaunting

- **Its accounting model.** Single-entry with categories is simpler and weaker than
  what we have. Nothing here should move toward it.
- **Its app store.** Our module licensing already enforces per company; a
  marketplace is a business model, not a feature.
- **Its stack.** Blade + Vue + a custom UI kit against our Filament panel. Mixing
  buys nothing.
- **Its `Document` supertype.** It is how they share invoice/bill logic, but we
  already share it with `kind`, and collapsing invoices, bills, estimates and
  credit notes into one table is a migration with no user-visible result.
- **A client portal, and online payment with it.** Decided against, August 2026.
  It was Phase 5 of the first version of this plan: a client logs in, sees
  invoices, downloads PDFs, pays, backed by a second Filament panel scoped to a
  contact rather than a company. Recorded here rather than deleted so it is not
  re-proposed as an oversight — it is a decision. The pieces would exist if it
  ever comes back (invoices, PDFs, contacts, and the signed-link pattern from the
  payslip work for serving a document to somebody with no session), and one
  European client invoiced monthly needs neither a login nor a card gateway.
- **Their payroll's shallowness.** Their app has no withholding calculation, no
  bank-file export and no employee acknowledgement. Take the *configurability*,
  not the model.

## 7. Honest gaps in this analysis

- Read from the repository's structure, `app/Reports`, and the two paid apps'
  marketing pages. **Feature lists on a sales page are claims, not specifications:**
  §3 describes what Akaunting says its apps do, and any of it may be thinner in
  practice. Nothing here was verified against a running installation.
- The other paid apps are unread. If the ranking matters, the ones worth a look
  next are anything covering estimates/quotes, credit notes and inventory
  valuation.
- Effort figures are one developer's working days including tests, keeping the
  existing suite green. Item 6 (tax rates) and item 9 (pay components) are the two
  most likely to run over, because both change data that is already posted.
