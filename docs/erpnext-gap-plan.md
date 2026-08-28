# What ERPNext's accounting module has that we do not

**Status:** Analysis and plan. Nothing here is built.
**Created:** 2026-08-28
**Constraint:** additive only — nothing that works today changes behaviour, and every phase is built out of
machinery this application already has. §3 says what each one reuses, and lists the four things an earlier
draft of this document proposed that the constraint threw out.

**Decided 2026-08-28, and it is what makes §3 an order rather than a menu:**

- **A profit and loss by department, branch or project is wanted** — asked for, not inferred. So Phase 1 is
  justified in full, including the resolver and the grouping, rather than stopping at the audit-trail half.
- **The company does pay contractors under §153, and treats the deduction as optional here** — handled
  outside the application for now. Phase 4 therefore stays written down and **unscheduled**, rather than
  going first as an earlier draft of this section recommended. The risk that comes with that choice is named
  in §2.4 and is the company's to carry, not this document's to re-argue.

**Source read (2026-08-28, second pass):** the accounting module, not the index. Thirty pages —
`accounting-introduction`, `chart-of-accounts`, `how-transactions-affect-the-ledger`,
`accounts-settings`, `finance-book`, `sales-invoice`, `sales-return`,
`purchase-invoice`, `payment-entry`, `payment-reconciliation`, `payment_ledger`,
`accounts-receivable-and-payable`, `bank-transaction`, `setting-up-taxes`, `tax-rule`,
`item-tax-template`, `tax-withholding-category`, `cost-center`, `cost-center-allocation`,
`accounting-dimensions`, `budgeting`, `journal-entry`, `accounting-period`, `deferred-revenue`,
`dunning`, `subscription`, `process-statement-of-accounts`, `accounting-reports`, `opening-and-closing`,
`accounting-workflows-by-business-type`. Three are unreachable upstream: `bank-reconciliation` and
`bank-reconciliation-tool` redirect in a loop and `unreconcile-payments` is a 404, so bank matching is
read through `bank-transaction` and `payment-entry` instead.

**The first draft of this document read three pages and said so.** Reading the module changed four things
and added two features the earlier comparison had missed entirely; each is marked **[2nd pass]** where it
appears, and §6 now says what is still unread rather than what was never opened.

Two comparisons already exist in this directory — `gnucash-gap-analysis.md` and `akaunting-gap-plan.md` —
and both concluded the same way: the ledger core here is sound and the gaps are at the edges. ERPNext is a
harder comparison than either. It is the only one of the three that is an *ERP* rather than a bookkeeping
app, so its accounting module assumes purchasing, stock, projects and assets are in the same database and
posts from all of them. So does this application. That makes most of ERPNext's list answerable, and it makes
the places where the answer is *no* worth taking seriously.

## 0. One architectural difference, stated once

ERPNext is a single database with a `company` column on every document. This application is
**database-per-company** (`spatie/laravel-multitenancy`, a tenant connection swapped per request). Two
ERPNext features follow from their choice and not from any deficiency here:

- **Consolidated Financial Statement** — reporting across companies in one query. Here, two companies are
  two databases; a consolidated statement is a cross-database report, which is a different feature with a
  different failure mode. Out of scope, and it should stay out until somebody actually asks.
- **Inter Company Journal Entry** — one submit writing linked entries in two companies' books. Same reason.

Everything else on ERPNext's list is a fair question to ask of this codebase, and the rest of this document
asks it.

## 1. Feature by feature

Grouped under ERPNext's own headings, so the comparison can be checked against the source page.

| ERPNext | Here | Verdict |
|---|---|---|
| **Chart of Accounts** — hierarchical, typed, `is_group` | `accounts`: `code`, `name`, 5 types, `normal_balance`, `parent_id`, `allow_manual_entry`, `currency_code` (`2026_07_15_082837_create_accounts_table.php`) | Present, and stricter — see the note below the table |
| **GL Entry** | `journal_entries` + `journal_entry_lines`, draft → pending → approved → posted, balanced at post | Present, and stricter: ERPNext has no approval step |
| **Payment Ledger Entry** — party subledger | Nothing. Party lives on `invoices`, never on a ledger line | **Gap — §2.2** |
| **[2nd pass] Payment Ledger, read properly** — "keeps that invoice-to-payment relationship" and *does not create duplicate accounting postings*; AR and AP are computed from it rather than from invoices or from the GL | `invoices.amount_paid` (a running total) and `InvoiceEvent` rows (a narrative). No record of which receipt settled which invoice beyond a memo | **Gap — §2.2, and not the one I described first** |
| **Accounts Settings**, frozen dates | Year-level only: `fiscal_years.closed_at`, enforced in `JournalEntryService::post` (:216-223) | **Partial gap — §2.3** |
| **Finance Books** — parallel depreciation bases | One basis, `DepreciationService` | Gap, low value — §4 |
| **Chart of Accounts Importer** | `GnuCashImportService` + `GnuCashImport` page | Present in effect |
| **Sales Invoice** | `invoices` with `kind = sale`, lines, tax, FBR digital invoicing | Present |
| **Credit Notes** | `credits_invoice_id` + `credit_reason` + `InvoiceService::creditNote()` (:537) with an adjustment window | Present |
| **Accounts Receivable / ageing** | `InvoiceService::aging()` (:919), `AgedReceivables`, `AgedInvoices` reports | Present — but computed from invoices, not the ledger (§2.2) |
| **Payment Requests** — customer pay links | Nothing | Gap, low value — §4 |
| **Dunning** — overdue follow-up | Nothing | Gap — §4, and cheaper here than in ERPNext |
| **Purchase Invoice** | `invoices` with `kind = purchase`, `purchaseEntryLines()` (:1081) | Present |
| **Debit Notes** | Credit-note machinery is sale-shaped | Small gap — §4 |
| **Accounts Payable / ageing** | `outstandingPayables()` (:914), `AgedPayables` | Present |
| **Payment Orders** — approval before release | `payments` draft → approved → exported → paid, `SecondApproverRule`, `BankPaymentExportService` | Present, and better: a real bank file comes out of it |
| **Tax Withholding** — deduct at source from suppliers | Payroll §149 only (`PayslipService`, `FbrTaxFile`). Nothing on supplier payments | **Gap — §2.4** |
| **[2nd pass] Tax Withholding Category, read properly** — rates by date range; single-transaction threshold *and* cumulative threshold; "deduct only on the excess"; round-off; a **Tax Withholding Group** so one category can hold two rates for two classes of party; deduction at invoice by default and at payment for advances, deduplicated between them; an account head per company; and a **Tax Withholding Entries** table recording category, taxable base, rate, amount and source document | — | **§2.4, and it corrects the plan I wrote — see there** |
| **Payment Entry** — receipts, payments, transfers | `PaymentService`, `BankTransferService`, and `InvoiceService::recordPayment()` (:264) one invoice at a time | **Partial gap — §2.2** |
| **Payment Reconciliation** — allocate unallocated | Nothing | **Gap — §2.2** |
| **Bank Accounts / Transactions / Reconciliation** | `company_bank_accounts`, `bank_statements`, `bank_statement_lines`, `journal_entry_lines.reconciled_at`, `BankReconciliationService`, `BankReconciliationStatement` | Present |
| **Tax Templates** | `tax_rates` — flat list, `is_default`, one account each | Partial — §4 |
| **Tax Categories & Rules** — select by party/address/item | Nothing. The rate is picked per line | Gap — §4 |
| **Item Tax Templates** | Nothing | Gap — §4 |
| **Purchase Receipts / Delivery Notes** | Invoices move stock directly (`recordMovement()`, :1164). Construction has `GoodsReceipt` + `Commitment` + `ThreeWayMatchReport` | Deliberately different — §5 |
| **Stock Entries**, perpetual inventory | `stock_movements`, `InventoryService` posts its own journal entries, `InventoryValuationService`, `StockOnHand` | Present |
| **Depreciation Schedules** | `fixed_assets`, `DepreciationService`, `FixedAssetRegister` | Present |
| **Expense Claims** | `Expenses` module, `ExpenseClaimsReport` | Present |
| **Deferred Revenue / Expense** | Nothing | Gap — §4, and small |
| **[2nd pass] Deferred revenue, mechanically** — per invoice *row*: a deferred liability account, service start/end/stop dates; recognition monthly or prorated by days (a company-wide setting); run by a background job or by a manual *Process Deferred Accounting*; optionally booked through Journal Entries rather than straight GL rows | — | Sharpens §5's estimate rather than changing it |
| **Subscriptions** — recurring billing | `RecurringInvoice`, `BillingRun`, `SubscriptionBillingService`, `BeneficiarySubscription` | Present |
| **Projects & Timesheets** — service billing | `Projects`, `Timesheets`, `UnbilledWip`, `TimesheetUtilisation`, `PlanVersusActual` | Present |
| **Cost Centers** | Nothing. `employees.department` is free text (`2026_06_19_105813:17`) | **Gap — §2.1** |
| **[2nd pass] Cost Center Allocation** — one posted cost centre split across several by percentage, applied when the GL rows are written, effective-dated and never retroactive | Nothing, and nothing to hang it on | Not a gap — evidence for §5: even ERPNext needed a second mechanism to correct a dimension after the fact |
| **Accounting Dimensions** | Nothing on the ledger. `invoices.project_id` exists, and `RevenueByDimension` reports it *from invoices* | **Gap — §2.1, the main finding** |
| **Budgets** — by account, cost centre, project, dimension | `budgets` + `budget_lines` by account, `BudgetService`, `BudgetVsActual` | Present by account; the other three axes wait on §2.1 |
| **[2nd pass] Budget *control*** — Stop / Warn / Ignore at Material Request, Purchase Order, actual expense and cumulative expense, checked annually *and* per distribution period | `BudgetService` plans and spreads; `BudgetVsActual` reports. Nothing blocks or warns on any document | **Gap — new, §4.** Ours is a budget you read; ERPNext's is a budget that argues back |
| **Exchange Rate Revaluation** | `CurrencyRevaluationService` + `CurrencyRevaluation` page | Present |
| **Journal Entry** — accruals, provisions, corrections | Present, with `entry_type` general/adjusting/closing/reversing | Present |
| **[2nd pass] Journal Entry *account row*** — carries Party Type/Party, Reference Type/Name, Is Advance, Cost Center, Project and every dimension, per line | `journal_entry_lines` carries account, amounts, the currency trio, description, `reconciled_at`. No party, no reference, no dimension | **Gap — §2.1 and §2.2 are the same missing column seen twice** |
| **[2nd pass] Journal Entry Template** — reusable rows for a repeated posting | `ScheduledTransaction` is dated recurrence, which is more; nothing is a plain template | Gap, negligible |
| **Period Closing Voucher** | `FiscalYearClosingService` — and its Opening Balance Equity blocker has no ERPNext equivalent | Present, ahead |
| **Accounting Period** — block by document type | Nothing finer than the year | **Partial gap — §2.3** |
| **Opening balance tooling** — Opening Journal Entry, Opening Invoice Creation Tool, Stock Reconciliation | `OpeningBalanceCsvImporter` takes the old system's trial balance and plugs the difference into Opening Balance Equity (3300); `FiscalYearClosingService` then refuses to close a year while that account is non-zero | The journal step is present and ahead; opening *invoices* and opening *stock* are the gaps — §4 |
| **[2nd pass] Process Statement of Accounts** — one PDF *per customer* (opening balance, their ledger for the period, closing balance, optional ageing), emailed in bulk, on a weekly/monthly/quarterly schedule | Nothing. Phase 8 of the reports plan sends *one report to a list of people*, and explicitly refused per-recipient row filtering | **Gap — new, and the most interesting one the second pass found. §4** |
| **[2nd pass] Credit limits** — a limit per customer, a Credit Controller role that may pass it, over-billing allowance, and an overdue-exposure threshold that blocks new billing | Nothing. `contacts` carries payment terms (`2026_08_07_100000`), not a limit | **Gap — new, §4** |
| **[2nd pass] Accounts Settings odds and ends** — stale exchange-rate tolerance, "unlink payment on cancellation", exchange gain/loss posting-date choice, multi-currency invoices against one party account | Some are decisions we have made differently and some are questions we have not asked | Not gaps; listed so the next reader does not re-derive them |
| **Financial reports** — GL, TB, BS, P&L, Cash Flow, registers, ageing, budget | All present, plus 50-odd reports this comparison does not need | Ahead — but ERPNext's TB, BS, P&L and GL all filter by cost centre, project, dimension and finance book, which is the §2.1 gap showing up in the report layer |

Two rows deserve a note because the obvious reading of them is wrong.

**`is_group` is not a gap; it is answered better.** ERPNext carries a flag saying an account is a group and
therefore cannot be posted to. Here the same rule is derived from the tree in both directions:
`Account::scopePostable()` is active, manual-entry-allowed and `whereDoesntHave('children')`;
`scopeGroupable()` is `whereDoesntHave('lines')`; and it is enforced at the service layer, not just in the
picker — `JournalEntryService` refuses a line whose account fails `canAcceptEntries()` (:339), as does
`RegisterEntryService` (:250). A derived rule cannot disagree with the hierarchy, and a flag can.

**Ledger-line multi-currency is present.** It is easy to assume missing: `journal_entry_lines`
carries `currency_code`, `foreign_debit_amount`, `foreign_credit_amount` and the `rate` it was converted at
(`2026_08_04_210000_create_currency_tables.php:65-73`), and settlement posts a realised difference
(`InvoiceService::realisedLine()`, :446). That is ERPNext's model, including the reason for storing the rate.

## 2. The four that matter

### 2.1 Dimensions — and the column that already exists, unfilled

`journal_entry_lines` is `journal_entry_id`, `account_id`, debit, credit, the currency trio, `description`,
`reconciled_at`. There is no cost centre, no department, no project, no store. So every question of the form
*"profit and loss for this branch"* or *"what did this project actually cost"* has to be answered from the
source documents instead of from the books, and only for the documents that happen to carry the tag.

`RevenueByDimension` is the evidence. It exists because "`invoices.project_id` exists and nothing reports on
it", and its docblock records that it groups three ways rather than offering a dimension filter — because a
dimension picker had nowhere to live. It reports *revenue*, from invoices. It cannot report project
*profit*, because the costs are ledger lines and ledger lines have no project.

This question has now come up three times in this repository, and the three answers do not agree:

- **Retail chose a column.** `retail-stores-pos-plan.md:24` states the gap in the same words as this section
  ("The ledger has no dimension column"), and its Phase 5 (:611) proposes
  `journal_entry_lines.store_id`, set by every posting that has a store.
- **Construction declined.** `2026_08_17_130000_create_construction_costing_tables.php:8-20` records the
  decision not to add a job dimension, with three reasons that are still correct: a cost entry carries
  quantity and unit rate, which money alone cannot express; not every job cost is a general-ledger event;
  and cost periods and fiscal periods are different clocks. Construction keeps a parallel cost ledger and
  reconciles to the books.
- **Invoicing improvised.** `invoices.project_id`, reported from the invoice.

**The mechanism already exists, and it is not a dimension column.** `journal_entries` carries
`nullableMorphs('source')` — indexed, normalised to a stable alias on write by `setSourceTypeAttribute()`,
and readable through the `forSource()` scope (`Accounting/Models/JournalEntry.php:23-34`). A posting that
records what produced it needs no dimension of its own: the source *is* the dimension, and every dimension at
once. An invoice knows its project and its customer; a payslip knows its employee and therefore their
department; a construction posting knows its job; a stock movement knows its location; a payment certificate
knows its contract. One column, already there, resolves all of them.

**What is missing is that almost nothing fills it in.** Measured on the demo company:

```
journal entries: 163, with a source: 70
  (null): 93        App\Models\FixedAsset: 60        App\Models\Payslip: 10
```

Only `DepreciationService`, the payroll posters, `LoanService`, `ScheduledTransactionService` and
`FiscalYearClosingService` set a source. `InvoiceService::postSystemEntry()` (:1309) does not, and neither do
inventory, payments, petty cash, bank transfers or the register — they build a three-key header
(`entry_date`, `entry_type`, `memo`) and pass it to `JournalEntryService::create()`, which forwards whatever
it is given.

That failure is already documented in this codebase, one function away, about a different column:

> Derived from the date rather than left to the caller. Payroll passed one and nothing else did, so two
> thirds of the ledger carried no fiscal year — invisible until something filtered on it, at which point
> those entries would simply be gone from the report with nothing to say why.
> — `JournalEntryService::create()`, on `fiscal_year_id`

`source_type` is that comment's next paragraph, unwritten. Two thirds of the fiscal years were missing; today
57% of sources are.

So the recommendation is **fill in the column that exists, and derive dimensions from it** — not add
`cost_center_id` and `project_id` to the ledger line, and not let retail's Phase 5 add `store_id` either.
Filling it in is one key in a header array that each posting path already builds — no new parameter, no
signature change, no behaviour change, and a nullable column going from null to populated cannot break a
reader. Deriving is one small resolver mapping a source alias to the dimensions reachable from it.

Two ceilings, named rather than hidden:

- **A manual journal entry has no source**, so it cannot be tagged and lands in an *unassigned* bucket. That
  is honest and visible, which is the property that matters; a required dimension picker on the manual form
  is available later if the bucket turns out to be big.
- **The dimension is per entry, not per line.** One entry split across two projects cannot be expressed. If
  that need ever arrives with a real example behind it, *that* is when a line-level column earns its
  migration. Invoice-level `project_id` is all that exists to report today anyway.

**[2nd pass] Both ceilings are exactly where ERPNext is different, and it is worth being precise about it
rather than discovering it later.** A Journal Entry *account row* there carries Party, Reference Type and
Name, Is Advance, Cost Center, Project and every declared dimension — so a manual write-off in ERPNext is
tagged and attributed at the line, and the two ceilings above do not exist for them. What this document
proposes is deliberately weaker and much cheaper: the *entry* is attributed by what produced it, which
covers every posting a document generated and covers no posting a human typed. That is the trade, stated in
one sentence: **we get project and department reporting over machine postings, and an honest unassigned
bucket for the rest.**

Two other things the module's own pages say, which support the design rather than argue with it:

- **"Accounting Dimensions do not retroactively tag older transactions… Treat dimension rollout as a
  controlled accounting change."** ERPNext's documentation says this in its own voice, and it is the same
  caution Phase 1's backfill item carries. Nobody gets history for free; the difference is that a source
  link is *sometimes* recoverable from `invoices.journal_entry_id`, where a dimension never is.
- **Cost Center Allocation exists** — a whole second document whose job is to split a cost centre after the
  fact, by percentage, effective-dated, never retroactive. A system that stores the dimension on the line
  still needed a mechanism to correct it. That is an argument for deriving the connection rather than
  duplicating the fact, which is §5's position.

Construction's decision stands and is not reopened — it declined a *cost* dimension carrying quantity and
unit rate, which is not what a source link is. If anything this is the same judgement: derive the connection,
do not duplicate the fact.

### 2.2 Party on the ledger line, and one payment across many invoices

Two halves of the same gap.

**The subledger.** ERPNext writes a Payment Ledger Entry per party per receivable/payable movement, so AR is
a view of the ledger. Here, ageing iterates `Invoice` rows (`InvoiceService::aging()`, :919 — verified: it
queries invoices and nothing else). The consequence is precise: a manual journal entry against Receivables —
a write-off, a contra, an opening balance — moves the control account and is **invisible to every ageing
report**, so the trial balance and the AR report disagree and nothing notices.

**Do not restructure ageing to fix this. Detect it.** Rebuilding AR from the ledger means a party on every
line, which means the posting paths again, and the reward is a report that already works. The actual defect is
that the two figures can disagree *silently* — so the fix is to make the disagreement loud: one check
comparing the Receivables control balance to the sum of open invoices, and the same for Payables. This
codebase has three places that already do exactly this shape of thing — `ConstructionCosting`'s
`ReconciliationService` and `ReconciliationReport`, and `FiscalYearClosingService`'s Opening Balance Equity
blocker, which refuses to close a year while a control figure is wrong. A fourth costs a class and a test.
Once the source link of §2.1 is populated, the same check can name the entries responsible instead of only
the amount.

**[2nd pass] The subledger, corrected.** The first draft refused ERPNext's Payment Ledger as "a second store
that can disagree with the first". Having read the page, that is not what it is: a Payment Ledger Entry
"keeps that invoice-to-payment relationship" and **does not create duplicate accounting postings** — it is an
allocation index, not a second ledger, and Accounts Receivable and Accounts Payable are computed *from it*
rather than from invoices or from GL rows. The refusal survives, but the reason changes and gets narrower:
what ERPNext gains from that index is that a manual write-off carrying a party is *in* AR automatically,
because their journal line has a party. Ours cannot be. So the check proposed below is not a poor substitute
for their design — it is the only thing that can detect the divergence a party-less journal line creates,
and it is one class.

**The allocation.** `recordPayment(Invoice, amount, …)` settles one invoice. A customer sending one transfer
for five invoices is five calls, and a customer paying more than the invoice, or on account before invoicing,
has nowhere to sit.

The lazy form of ERPNext's Payment Entry is a screen, not a document type: pick the invoices, enter the sum,
and call the existing `recordPayment()` once per invoice inside one `TenantTransaction`. No new posting
logic, no new table, and the FX and rounding behaviour of settlement stays the one path that is already
tested. What that does *not* buy is money on account with no invoice behind it; that genuinely needs
somewhere to sit, and it should wait until somebody has the problem.

**[2nd pass] And the reuse this paragraph originally named was wrong.** It proposed tying the batch together
with `payments.batch_reference`. `payments` is the *outbound* bank-file table — `morphs('payable')` is an
Employee or a Beneficiary, there is no invoice on it, and `recordPayment()` never creates a row in it: a
customer receipt writes a journal entry, updates `invoices.amount_paid`, and records an `InvoiceEvent`. The
column exists and is populated; it is on the wrong table, which is the failure mode of designing from a
column name. So the batch screen produces N ordinary settlements sharing one reference in the memo and one
`InvoiceEvent` narrative each, and the fact that they arrived as one transfer has no first-class home. If
that turns out to matter — a bank statement line that has to reconcile to five settlements is the case —
*that* is when a receipt row earns its table, and it will look like ERPNext's allocation index rather than
like a second ledger.

### 2.3 Period control finer than a fiscal year

`fiscal_years.closed_at` freezes a whole year and `JournalEntryService::post` enforces it (:216-223). There
is nothing between "the year is open" and "the year is closed", so the ordinary month-end request — *stop
backdating into July now that July is reported, while the year stays open* — cannot be expressed.

ERPNext has two mechanisms: a frozen date in Accounts Settings, and an Accounting Period that blocks
selected document types. **Take the first and refuse the second.** A `ledger_frozen_before` date in
`Setting` plus one guard beside the closed-year guard is roughly twenty lines and answers the actual request;
per-document-type blocking is a permission matrix crossed with a calendar, and nobody here has asked for it.

**[2nd pass] With one addition, and it is the part that makes the feature usable rather than annoying.**
Both of ERPNext's mechanisms carry a role escape hatch — Accounts Settings has *"Role Allowed to Set Frozen
Accounts and Edit Frozen Entries"*, and an Accounting Period has an optional exempted role. Without one, the
first genuine month-end correction forces somebody to unfreeze the whole period, make the entry, and
remember to freeze it again — which is worse than no freeze, because the window is silent and nobody
records that it was open. So the guard is *frozen before this date **unless** the user holds a named
permission*, which is one `can()` in the same guard and no new concept: this application already gates
posting, approval and reversal that way.

### 2.4 Withholding tax on supplier payments

The only withholding in this application is income tax on salary (§149), which `FbrTaxFile` files. A company
paying contractors and suppliers in Pakistan withholds under §153 as well, at a rate that depends on the
nature of the payment and on whether the payee is a filer, and files a §165 statement. Today that deduction
would have to be typed as a journal line and remembered.

This is the one gap on the list that is a **compliance** gap rather than a capability gap. The shape already
exists to hang it on: `Beneficiary`, `payments` with an approval flow, `ContractorPaymentSummary` as the
annual statement, and `CertificateDeduction` in construction as proof the deduction-on-a-payment pattern is
already understood.

**[2nd pass] The claim that local requirements exceed ERPNext's Tax Withholding Category was wrong, and the
correction changes this plan's Phase 4.** Read properly, that document carries: rates **by date range**; a
**single-transaction threshold** and a **cumulative threshold** with either disableable; *deduct only on the
excess*; a round-off flag; a **Tax Withholding Group**, which is how one category holds two rates for two
classes of party — that is filer and non-filer, exactly; deduction at the invoice by default and at the
payment for advances, with the second reading the first's entries so the deduction is not taken twice; an
account head per company; and a **Tax Withholding Entries** table recording category, taxable base, rate,
amount and source document.

Three consequences, in the order they bite:

- **`tax_rates` is the wrong table to put this in**, which is what an earlier version of Phase 4 proposed.
  It is name, code, rate, account and `is_default`: no validity window, no thresholds, no party class. The
  thresholds *are* §153 — a payment below the limit is not withheld and the limit is annual as well as
  per-payment — so putting withholding in `tax_rates` means either five new columns on a table that eleven
  other things read, or a rate that is silently wrong for every small payment. A small table of its own is
  the additive answer, and it is the one place in this document where "reuse the existing table" is refused.
- **The deduction needs its own record, not just a journal line.** A §165 statement wants the taxable base
  and the rate per deduction, and a journal line carries neither — it carries an amount. ERPNext learned
  this and has the entries table. That record is also what makes the advance-then-invoice double deduction
  detectable, which is a real case here: a supplier advance paid in June against an invoice booked in July.
- **Deducting at the payment is right for §153 and is *not* ERPNext's default**, which deducts at the
  invoice. Worth knowing before copying their shape: the withholding obligation here arises when the payment
  is made, so the deduction belongs where this plan already put it — on the payment at approval — and the
  invoice-side machinery ERPNext has is machinery we do not need.

## 3. The plan

**The constraint this plan is written to: do not disturb the current flow, and use what is already here.**
That is not a softening — it is what makes the plan feasible. Every phase below is additive, and the test of
each item is the same: *if this is only half done, does anything that works today work differently?* If the
answer is yes, the item is wrong and there is a lazier one.

Concretely, the constraint rules out four things that a first draft of this document contained: new columns on
`journal_entry_lines`, a new parameter through the twenty-one files that reference `JournalEntryService`,
rebuilding ageing, and a new document type for receipts. What replaces them is listed against each phase as
*reuses*, because that is the part worth checking before starting.

Estimates are judgement, not measurement.

Order, after the 2026-08-28 decisions above: **Phase 1**, then **Phase 2 item 1** (half a day, and worth
taking out of order), then stop. Phases 3, 4 and 5 are written down and unscheduled — 3 and 5 because nobody
has asked, 4 by decision.

**Phase 1 — Fill in `journal_entries.source`, then derive dimensions from it. ~1 week. Scheduled.**
*Built 2026-08-28. Two of its five items were built differently than written, and both deviations are
recorded in §7.*
*Reuses:* the existing `nullableMorphs('source')`, its alias-normalising mutator, the `forSource()` scope,
`ModuleMap::alias()`, and the report pane's URL-carried filters.
1. One key added to the header array each posting path already builds — `InvoiceService::postSystemEntry()`
   first, then inventory, payments, petty cash, bank transfer, register entries. Each is one line and one
   test; none changes a signature; a nullable column going from null to populated cannot break a reader.
2. A `LedgerDimensions` resolver: source alias → the project, party and department reachable from it. One
   class, `match` on the alias, no interface and no registry until a third module needs to extend it.
3. A backfill command for entries already posted where the link is inferable (invoice number in the memo,
   `invoices.journal_entry_id` — which already exists and points the other way, so most of the history is
   recoverable). Where it is not inferable, leave it null: a guessed source is worse than none.
4. Grouping by project and by department on General Ledger and P&L, with the untagged remainder shown as its
   own *unassigned* row. Never folded into a total silently — an incomplete dimension that looks complete is
   the one outcome worse than not having it.
5. **Not** a `cost_centers` table. `employees.department` is the department, and a P&L by department that
   reads a free-text column is available now; a dimension table earns its place when somebody needs a
   hierarchy, a rename that does not rewrite history, or a code.

**Phase 2 — Prove ageing and the ledger agree; then allocate in a batch. ~1 week.** *Built 2026-08-28.*
*Reuses:* `ReconciliationService`/`ReconciliationReport` as the pattern, `app/Health/` as the home, and the
tested `recordPayment()` path. **[2nd pass] Not `payments.batch_reference`** — that column is on the outbound
bank-file table and a customer receipt never writes a row there. See §2.2.
1. A check comparing the Receivables control account to the sum of open invoices, and Payables likewise.
   Register it beside `TenantDatabaseCheck` and `BackupConfigurationCheck`, which is where facts about the
   installation being sound already live, and let it fail loudly. This is the whole of §2.2's first half.
2. A batch receipt screen: pick invoices, enter one sum, allocate down the list, call `recordPayment()` per
   invoice in one transaction, sharing one reference in the memo. No new table, no new posting code — and no
   first-class record that the five settlements were one transfer, which §2.2 names as the ceiling rather
   than hiding.
3. **Not** unallocated money on account, and **not** a party column. Both wait for a real example.

**Phase 3 — `ledger_frozen_before`. ~1 day.** *Built 2026-08-28, and it was a day: a setting, a guard, a
section, a permission and six tests. The exemption is `JournalEntryBackdate`, granted to nobody below
Administrator, and the closed-year guard is untouched beside it — the two are separate rules and a test says
so.* *Reuses:* `TenantSettings`, the closed-year guard's own shape,
and the permission gating that already surrounds posting.
A setting key, a guard beside the existing one in `JournalEntryService::post`, a field on company settings,
and a test that a backdated post is refused and a same-period one is not. **[2nd pass] Plus the escape
hatch** — both of ERPNext's mechanisms carry one, and without it the first month-end correction forces a
global unfreeze that nobody records re-freezing. One `can()` in the same guard. Cheapest item here by an
order of magnitude, and the only one a user would notice on the day it ships.

**Phase 4 — §153 withholding. ~1 week, and the only compliance item.**
*Reuses:* `tax_rates` (name, code, rate, posting account — already the right shape, and `code` is there for
the authority's own code), the `payments` draft → approved → exported flow, `ContractorPaymentSummary` as the
statement, `FbrTaxFile` as the filing-export pattern, `CertificateDeduction` as the precedent for deducting on
a payment.
1. **[2nd pass, corrected] A small withholding table, not rows in `tax_rates`.** Section, rate, validity
   window, per-payment threshold, annual threshold, party class (filer / non-filer), posting account. The
   first draft said "no second tax table" and that was designing from a column name: `tax_rates` has no
   validity window and no thresholds, and the thresholds are what §153 *is*.
2. One deduction line added to the payment's journal entry at approval — the same place the second-approver
   rule already runs, so the flow gains a line and not a step. This stays: §153 withholds at payment, which
   is *not* where ERPNext deducts by default, so their invoice-side machinery is machinery we do not need.
3. **[2nd pass] A deduction record per withholding** — beneficiary, section, taxable base, rate, amount,
   payment. A journal line carries the amount and neither of the two figures the statement needs, which is
   why ERPNext keeps Tax Withholding Entries beside its postings.
4. The §165 statement as a report over those records, built like `FbrTaxFile`.

**Unscheduled by decision, 2026-08-28.** Contractors are paid and the deduction is handled outside this
application, so this is not the emergency an earlier draft of this section made it. Worth writing down once
and not repeating: a deduction computed by hand is a deduction somebody can forget, and the §165 statement is
then assembled from records the ledger does not hold. When that becomes the annoyance rather than the risk,
this phase is a week.

**Phase 5 — the small ones, each one a reuse.** Debit notes through `creditNote()`'s existing window and
approval logic; deferred revenue as a `ScheduledTransaction`, which is already a dated recurring posting with
a source link; dunning as a `ReportSchedule` over an overdue query with an `EmailTemplate` — the Phase 8
delivery machinery makes this nearly free and it needs no new concept; opening invoices and opening stock as
two `CsvImporter` registrations beside `OpeningBalanceCsvImporter`, which is one class each because
`CsvImporters::register()` already exists; tax categories only *if* picking a rate per line ever becomes the
annoyance ERPNext designed Tax Rules for.

## 3.1 What Phase 1 turned out to be

Built 2026-08-28. The five items landed as written except for two, and the difference is worth reading before
Phase 2 starts from the same assumptions.

- **Item 2's "no registry" was wrong, and the module boundary is why.** The plan asked for "one class,
  `match` on the alias, no interface and no registry until a third module needs to extend it". A `match`
  naming Invoice, Payslip and Payment has to live somewhere, and both candidates are forbidden: in
  Accounting it re-creates the `accounting -> invoicing` and `accounting -> payroll` edges that
  `docs/module-packaging-plan.md` spent four registries removing, and in `App\Support` it breaks the rule
  that shared code names no module — `ModuleBoundaryTest` enforces both. So `App\Support\LedgerDimensions`
  is a registry each module writes to from its own provider, which is the fifth instance of an inversion
  this codebase already had four of. A third module did not have to ask; the first one did.
- **A resolver had to declare what it reads.** `register()` takes the relations its closure walks, because
  resolving a few hundred documents one relation at a time is an N+1 — and `preventLazyLoading` turned that
  into a failing test rather than a slow report, which is the guard working exactly as intended.
- **Item 4 is a new report, not a grouping on the existing two.** The plan said "grouping by project and by
  department on General Ledger and P&L". Adding a dimension picker to `ProfitAndLoss` would put a new
  grouping inside the statement a company closes its year with, and this plan's own constraint asks: *if
  this is only half done, does anything that works today work differently?* So `ProfitAndLossByDimension`
  is its own report, and the two are tied together by a test asserting that its total **is** the profit and
  loss for the same dates — which is a stronger guarantee than sharing a code path would have been.
- **Item 3 is stronger than estimated.** The plan hoped "most of the history is recoverable" from
  `invoices.journal_entry_id`. Reading `App\Support\JournalEntryOwners` showed that *five* document types
  carry that column and each module registers its own, so `accounting:backfill-entry-sources` walks the
  registry rather than naming a table: invoices, payments, petty cash vouchers, stock movements and fixed
  assets are all recoverable, and a module that is not installed contributes nothing to the backfill for
  the same reason it contributes nothing to the report.
- **And the registry it walks is the one §8 Group B wanted deleted.** `JournalEntryOwnersTest` asserted the
  premise for keeping it — that only depreciation stamped a source — and said in its own message to reopen
  the question when that stopped being true. Phase 1 is that moment, and the answer is still no: stamping
  is forward-looking and the backfill is opt-in, so a company that upgrades and never runs it has years of
  postings with a null source, and the register's edit guard has to hold for exactly those.

**One bug worth recording, because it was silent.** The aggregate selected its signed sum as `amount`, and
`JournalEntryLine` has a `getAmountAttribute()` accessor — debit or credit, whichever is set. An accessor
wins over a selected column of the same name, and a row hydrated from the aggregate has neither debit nor
credit, so every project reported a profit of exactly zero. Plausible, well-formatted and entirely wrong.
The alias is `movement` now.

## 3.2 What Phase 2 turned out to be

Built 2026-08-28, and closer to the plan than Phase 1 was. Both items landed; one reuse named in the plan
turned out to be the wrong table, which §2.2 already records.

- **The check is a registry too**, for the same reason Phase 1's resolver became one: `app/Health` is where
  facts about the installation live, and a check importing Invoicing would put a module's model in shared
  code. So `App\Support\LedgerControls` holds the pairs and Invoicing registers Receivables and Payables —
  it owns both sides, since `InvoiceService` raises the invoices *and* names accounts 1250 and 2400 itself.
  A company without Invoicing has no receivables to reconcile and registers nothing.
- **It runs hourly, not every minute.** `health:check` is scheduled every minute because its neighbours are
  a PDO connect and a disk stat; this one sums each company's ledger. A reconciliation drift found
  fifty-nine minutes late is found in time. The gate is a run condition rather than a second command, so the
  result still appears on the same page as everything else.
- **A tolerance of one unit, and it is tested from both sides.** Both figures round to two places, so a
  company with many invoices in several currencies differs by pennies through arithmetic alone; a check that
  fires on that gets muted, and a muted check is worse than none. The test asserts that a 0.40 difference
  passes *and* that tightening the tolerance catches it — otherwise the tolerance is indistinguishable from
  the comparison being blind.
- **The receipt screen refuses money it cannot place, and that refusal is the interesting part.** The
  allocations must equal the receipt, because there is nowhere in this schema to hold a balance that is not
  against an invoice. Over-allocating invents money and under-allocating leaves it on account; the plan says
  to wait for somebody to actually have that problem rather than to build an advances table because ERPNext
  has one, so the screen says so in a sentence instead.
- **All of it or none of it.** One transaction around the batch, with a test that a failure on the second
  invoice leaves the first unsettled. Half a receipt recorded is worse than none: the customer's balance is
  then wrong in a way that reconciles to nothing.
- **`recordPayment()` gained one optional parameter** — a reference, which lands in the memo of each
  settlement so five rows can be recognised as one transfer. That is the ceiling §2.2 named: there is no
  receipt row to put it on, because a customer receipt writes a journal entry and updates the invoice and
  nothing else.

## 4. Ranked, everything not in §3

Two of these are **[2nd pass]** entries: the first read of the module missed them entirely, and one of them
is now the highest-value item on the list.

1. **[2nd pass] Customer statements** — ERPNext's *Process Statement of Accounts*: one PDF per customer,
   with their opening balance, their ledger for the period, their closing balance and an optional ageing
   summary, emailed in bulk on a weekly, monthly or quarterly schedule. Every part of that exists here —
   `ReportSchedule`, `ReportDelivery`, `EmailTemplate`, the pane's PDF export, `AccountRegister` as the
   per-party ledger — with **one** thing missing, and the reports plan named it while refusing it: a
   schedule sends one rendered report to a list of recipients, and this needs one rendered report *per
   recipient*, authorised as that recipient's own. That refusal ("Per-recipient row filtering in a schedule
   … is a different feature and should be named as one") is exactly this feature, now named. Ranked first
   because it is the only item on this list a customer sees, and because the machinery is a phase old.
2. **Debit notes** — the purchase mirror of `creditNote()`. The window and approval logic is written, and
   ERPNext confirms the shape: theirs is the same invoice with `is_return`, not a separate document.
3. **Deferred revenue and expense** — a schedule generated from an invoice line, recognised monthly. The
   mechanics are now known rather than guessed: service start/end on the row, a deferred liability account,
   monthly or day-prorated recognition chosen once for the company, run by a job. `ScheduledTransaction` is
   the dated-posting half; the generator from an invoice line is the new part.
4. **Dunning** — overdue reminders. Cheaper here than in ERPNext for the letter, and *not* cheaper for the
   part I had not read: theirs books interest and a fee through the payment's deductions, which is a posting
   decision and not an email. Take the reminder, leave the interest until somebody charges it.
5. **[2nd pass] Credit limits** — a limit per customer, a role that may override it, and an overdue-exposure
   threshold that blocks new billing. Nothing here has any of it: `contacts` carries payment terms, not a
   limit. Ranked here rather than lower because it is the one control on this list that prevents a loss
   rather than reporting one, and it is a column, a setting and a guard in `InvoiceService`.
6. **Opening stock** — ERPNext's Stock Reconciliation. `stock_movements` can express it; there is no screen,
   so a company arriving with stock on hand has a valuation the ledger knows and the shelf does not.
7. **Opening invoices** — the trial balance import brings in the Receivables *total*; the individual open
   invoices behind it have to be typed, so ageing starts empty. A CSV importer alongside
   `OpeningBalanceCsvImporter`, not a new document type. ERPNext's own guidance is worth copying with it:
   load the chart, then invoices, then payments, then stock, then assets, then the journal for the
   remainder — running a trial balance after each stage so an error belongs to one batch.
8. **[2nd pass] Budget control** — ours plans and reports; theirs stops or warns at material request,
   purchase order, actual expense and cumulative expense, annually and per period. A warn-only version over
   purchase orders is the useful half and needs no new table.
9. **Tax categories, tax rules, item tax templates** — three ERPNext documents, one question: which rate.
   Now read: a Tax Rule is priority-ordered and specificity-broken, an Item Tax Template overrides the *rate*
   on an existing tax row rather than replacing the template. If the "which rate" question ever gets asked
   here, that split — templates decide accounts, rules decide templates, item templates override rates — is
   the design worth copying.
10. **Payment Request** — a customer-facing pay link. Needs a gateway; that is a different plan.
11. **Finance Books** — parallel depreciation bases. Real in jurisdictions with divergent tax books, and
    nobody here has asked. Their model, for when somebody does: a book per transaction, blank meaning
    "common and included in every book's view", and a depreciation row per book on the asset.
12. **Payment Ledger Entry as its own table** — still refused, on a better-informed reason: it is an
    allocation index rather than a second ledger (§2.2), and what makes it work for them is a party on the
    journal line, which is the actual difference.

## 5. What not to take from ERPNext

- **Generic Accounting Dimensions.** Choosing a DocType and having the framework generate custom fields
  across every transaction and report is the flexibility that makes a schema unreadable.
- **A dimension column on the ledger line — of any kind, including the ones this repository has proposed to
  itself.** `store_id` (retail Phase 5), `cost_center_id`, `project_id`, `party_id`: each is one migration and
  twenty-one posting paths that have to learn a new argument, and the source morph already reaches all four
  facts. If a genuine line-level split ever turns up, that example is the justification and this bullet is
  what it has to argue against.
- **Consolidated Financial Statement and Inter Company Journal Entry.** §0.
- **Purchase Receipt and Delivery Note as separate documents.** Invoices already move stock, and where the
  three-document dance genuinely matters — construction procurement — `Commitment` + `GoodsReceipt` +
  `ThreeWayMatchReport` already exist. Adding them application-wide would impose a purchasing department on
  companies that do not have one.
- **Accounting Period blocking by document type.** §2.3.
- **A Dunning document type.** A dunning *letter* is an email with a query behind it. Modelling it as a
  document buys a status field and a list view nobody opens.
- **Country-specific Chart of Accounts templates.** The GnuCash importer plus a seeded chart covers this,
  and a template that is nearly right is worse than an import somebody checked.

## 6. Honest gaps in this analysis

- **The module is now read; three of its pages are not, because they are broken upstream.**
  `bank-reconciliation` and `bank-reconciliation-tool` redirect in a loop and `unreconcile-payments` returns
  404, so what this document knows about their bank matching comes from `bank-transaction` and
  `payment-entry` — enough to say what a Bank Transaction carries and how it is matched, not enough to
  describe their matching *rules*. Our verdict there is "present", so the risk of being wrong is that we are
  *behind* in a way this comparison cannot see. `understand-debit-and-credit` and
  `chart-of-accounts-importer` were skipped deliberately: the first is bookkeeping fundamentals and the
  second is a feature we answer with the GnuCash importer.
- **What the second pass changed, listed once so it is not re-derived.** Four corrections and two
  additions: the Payment Ledger is an allocation index rather than a second ledger (§2.2); a journal
  *account row* in ERPNext carries party and dimensions, which is the same missing column as §2.1 and
  reframes both ceilings; `payments.batch_reference` is on the wrong table for a customer receipt (§2.2);
  `tax_rates` cannot hold withholding because thresholds and validity are what §153 *is* (§2.4). The
  additions are customer statements and credit limits, both now in §4, and the first is ranked top.
- **The documentation is a manual, not a specification.** Several pages describe behaviour without the
  field-level detail behind it — the deferred-revenue schedule generator, ERPNext's fallback when no Tax
  Rule matches, whether Accounting Periods may overlap. Where a page was silent this document says so rather
  than inferring; anyone implementing from these notes should read the source, which is the only place the
  answer actually is.
- **I did not test the claims, only the schema and the code.** Every "present" above is a table, a service
  or a report I opened. None of it is a claim that the feature is *correct* — the existing test suite makes
  that claim, not this document.
- **Construction and retail were read for their decisions, not their coverage.** Some of what §4 lists as
  missing may exist inside `ConstructionCosting` for construction companies. That is not the same as
  existing in Accounting for everybody, and this comparison is about the second.
- **Estimates are judgement.** Phase 1's week is the one I would least trust, and the risk is concentrated in
  item 3: how much of the existing 93 sourceless entries is actually recoverable from
  `invoices.journal_entry_id` and the memos is unknown until somebody tries it. The forward-looking part —
  one key per posting path — is the part I am confident about.
- **The measurement is one company's demo data.** 163 entries, 57% sourceless. The proportion on a real
  company's books will differ; the fact that invoices, payments and inventory set no source is from the code
  and does not depend on the sample.
- **Two claims in this document were wrong, in opposite directions, and both are worth knowing about.** I
  recorded `is_group` as a gap before reading `Account::scopePostable()`, which answers it from the tree —
  this codebase tends to *derive* rules that other systems store, so anything marked "gap" on the strength of
  a missing column deserves a second look. And I proposed reusing `payments.batch_reference` after reading
  its name and its migration comment but not its table: `payments` is the outbound bank-file table and a
  customer receipt never writes a row in it. A column that exists, is populated, and is on the wrong table is
  the failure mode of designing from a grep.
