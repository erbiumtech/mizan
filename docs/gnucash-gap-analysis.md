# What GnuCash has that we do not

**Created:** 2026-08-07

Read against [gnucash.org/features.phtml](https://www.gnucash.org/features.phtml)
— the feature list GnuCash advertises for itself — and checked claim by claim
against this codebase.

**The headline: we are not behind on accounting.** GnuCash is a single-user
desktop application; most of what it advertises is either already here, here in a
stronger form, or irrelevant to a multi-tenant web app serving Pakistani
businesses. There is **one real hole**, and it is budgeting.

A caution about the source: this is a marketing page, not a manual. It says what
GnuCash wants credit for, at the granularity of a bullet. Where a feature is
listed as one word — "budgeting tools" — I have compared against the word, not
against the depth of the implementation. See §5.

---

## 1. Feature by feature

| GnuCash advertises | Here | Notes |
|---|---|---|
| Double Entry | ✅ | `JournalEntry` + lines, balance enforced in `JournalEntryService::validateLines()` |
| Checkbook-style register, splits, cleared/reconciled, autofill | ✅ | Account Register; ≥2 lines with no upper bound; `journal_entry_lines.reconciled_at` |
| Scheduled Transactions + reminders | ⚠️ | Recurring **invoices** and subscription payments only. No general scheduled entry |
| Reports, Graphs (bar, pie, scatter) | ✅ / ⚠️ | Reports strong; only three charts exist, none on a report |
| Statement Reconciliation | ✅ | `BankStatement` → lines → auto-match → complete |
| Income/Expense account types | ✅ | Five types, hierarchy, normal-balance derivation |
| Small business: customers, vendors, jobs, invoicing, bills, terms, payroll, budgeting | ⚠️ | See §2 — most present, **budgeting entirely absent** |
| Multiple Currencies, fully balanced | ✅ | `Currency`, `ExchangeRate`, revaluation, realised/unrealised FX accounts |
| Stock / Mutual Fund Portfolios | ❌ | Nothing. Deliberate — see §4 |
| Online Stock & Mutual Fund Quotes | ❌ | Not our business |
| XML / SQLite3 / MySQL / PostgreSQL backends | n/a | MySQL landlord + a database per tenant. Different architecture, not a gap |
| QIF and OFX import with duplicate matching | ⚠️ | GnuCash import + CSV import + bank statement import. No QIF/OFX |
| HBCI (German home banking) | ❌ | Irrelevant |
| Multiplatform | n/a | Web |
| 61 languages | ❌ | Single language. Large effort, no signal of demand |
| Transaction Finder (query search) | ⚠️ | ⌘K palette + per-table filters. No cross-ledger query |
| Check Printing | ❌ | We produce **IBFT bank payment files**, which is how Pakistan actually pays |
| Mortgage & Loan Repayment Assistant | ❌ | Narrow, but a real fit for personal accounts |

**Where we are ahead**, and it is worth saying because the table above does not
show it: journal entries carry a draft → pending → approved → posted → reversed
workflow with segregation of duties (a creator cannot approve their own entry),
enforced at the service layer. GnuCash has no concept of approval at all. We also
have fiscal-year closing with an Opening Balance Equity check, aged receivables
and payables, a payroll module with FBR withholding output, and per-company
licensing of the whole lot.

## 2. The small-business row, unpacked

GnuCash lists this as one bullet. Broken out:

| | |
|---|---|
| Customer tracking | ✅ `Contact` |
| Vendor tracking | ✅ `Contact` + `Beneficiary` |
| Invoicing | ✅ `Invoice`, five states, FX gain/loss on payment |
| Bill payment | ✅ `Invoice` with `KIND_PURCHASE` (`BILL-` prefix), plus `Payment` |
| Payroll | ✅ Well beyond GnuCash's |
| Jobs | ⚠️ `Project` exists but is not linked to billing |
| Billing terms | ❌ No payment-terms concept on a contact or invoice |
| **Budgeting** | ❌ **Nothing** |

On budgeting being *nothing* rather than *thin* — grepping `budget` across `app/`
and `database/migrations` returns exactly one file, and it is an unrelated local
variable in `ExpenseClaimService` holding a reimbursement cap. There is no table,
no model, no screen, no report.

## 3. The recommendation: budgeting

**Build this one.** Three reasons, in order of weight.

**The hard half already exists.** A budget report is "what did we plan" against
"what did we actually spend on this account in this period". The second half is
`FinancialReportService::periodBalance()` — written, tested, and already the
engine behind Profit & Loss. This is not new accounting machinery; it is a
comparison against machinery that works.

**It lands on both audiences at once.** A company budgets by account and period.
And for the personal accounts added this week, budgeting is not a nice-to-have —
"I set 50,000 for food and I have spent 62,000" is substantially *why* people
track personal finance at all. Nothing else on this list serves both sides so
directly.

**It is the only major GnuCash feature we have zero of.** Everything else in the
table is present, partial, or deliberately declined.

Rough shape, given what is here:

```
budgets       fiscal_year_id, name
budget_lines  budget_id, account_id, period (month or year), amount
```

The report joins `budget_lines` against the posted-lines query the P&L already
uses, per account per period, and shows planned / actual / variance. A personal
account gets the same screen over its own chart.

Two decisions worth making before writing code, not during:

- **Monthly lines or one annual figure per account?** Monthly is far more useful
  and is more rows to enter. A sensible middle is an annual figure divided evenly,
  with per-month override.
- **Does a budget belong to a fiscal year or float?** Tying it to `fiscal_year_id`
  matches everything else here and makes year-on-year comparison natural.

Estimate: **2–3 days** including the screen, the report and tests.

## 4. Ranked list of everything else

**Worth doing, in order:**

1. **Scheduled transactions** (~2 days). Recurring invoices already prove the
   pattern — `routes/console.php`, `monthlyOn(1, '03:00')`, tenant-aware,
   `withoutOverlapping`. Generalising it to journal entries covers rent, salary,
   loan repayments and utility bills. For a personal account this is the
   difference between a ledger you maintain and one that maintains itself.
2. **Charts on the reports** (~half a day). Reports are text tables today. A
   category pie on Profit & Loss is how people actually read "where did my money
   go", and the `ChartWidget` pattern already exists three times over
   (`CashFlowChart`, `PayrollByEmployeeChart`, `ProjectHealthChart`) — all on
   dashboards, none on a report.
3. **Transaction finder** (~1–2 days). Cross-ledger query — "every transaction
   over 50,000 against this account between two dates". Today that means going
   account by account on the register.
4. **Loan / mortgage amortisation** (~1–2 days). Narrow, but a genuine fit for
   personal accounts, and liability accounts already exist.
5. **Billing terms** (~1 day). Net-30 on a contact, driving the invoice due date
   and therefore the ageing buckets that are already built.

## 5. What not to take from GnuCash

**Stock and mutual fund portfolios.** Large feature, not this application's
business — and it would actively collide with something already known to be
approximate: our capital gains rate is a flat 15% assuming a filer and an asset
acquired on or after 1 July 2024. Portfolio tracking would create an expectation
that holding periods and asset classes are handled properly. They are not, and
making them so is a tax-law project, not a features project.

**Check printing.** We emit IBFT bank payment files. That is how money moves here.

**QIF / OFX import.** The GnuCash importer and CSV import already cover the
migration path in, and Pakistani banks rarely export OFX. Bank statement import is
the surface that matters.

**HBCI.** German banking protocol.

**Multiple database backends.** Ours is MySQL with a database per tenant, which is
a deliberate isolation decision, not a limitation to fix.

**61 languages.** Real work, and nothing so far suggests demand.

## 6. Honest gaps in this analysis

- **Read from the marketing page, not the manual or the source.** "Budgeting
  tools" is one bullet there; GnuCash's actual budget feature may be deeper or
  shallower than the word implies. If budgeting gets built, half a day reading
  what GnuCash's budget screen really does is worth spending first.
- **No comparison of report *quality*.** Both have a Balance Sheet. Whether theirs
  answers questions ours does not, I have not checked.
- **"Jobs" is guessed at.** GnuCash jobs group invoices under a customer
  engagement. I have matched it to `Project` on the name, without confirming the
  semantics line up.
- **Nothing here is weighted by what users have asked for.** The ranking is my
  judgement about fit and cost, not evidence of demand. If somebody has been
  asking for one of the items in §4, that should outrank my ordering.
