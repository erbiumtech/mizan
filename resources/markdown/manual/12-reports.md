## What this chapter covers

Where to look for a given question, and the one rule that explains most reports
that look wrong.

Everything is reached from **Reports**, a single hub page grouped by what the
reports are for. The hub only lists reports you actually have access to, so a
shorter list than a colleague's is a matter of permissions or licensed modules
rather than a missing feature.

## The rule that explains most surprises

**Only posted entries appear in any report.** A draft, a pending-approval, an
approved-but-unposted or a rejected journal entry contributes nothing, anywhere.

So when a figure is missing, check in this order:

1. Was the entry ever **posted**? Look it up on the Journal Entries list.
2. Is it dated inside the **period** the report covers?
3. Does it have a **fiscal year**? An entry dated outside every defined year gets
   none, and drops out of anything filtered by year.

That covers nearly every "the report is wrong" report.

## The financial statements

Two of these answer "as of a date" and two answer "over a period", which is the
distinction to keep straight:

| Report | Answers | Shape |
|---|---|---|
| **Balance Sheet** | What the company owns, owes and is worth | As of a date |
| **Trial Balance** | Every account with its balance, and proof the books add up | As of a date |
| **Profit & Loss** | Income less expenses, and the profit left over | Over a period |
| **Cash Flow** | Where money actually came from and went | Over a period |
| **Budget vs Actual** | What was planned against what was spent | Over a period |

The first four can additionally be scoped to a **fiscal year**; Budget vs
Actual always belongs to the year its budget plans.

The Trial Balance is the one to reach for first when something does not tie: it
shows total debits against total credits and whether they agree. It is also what
the year-end close checks before it will let you close, along with Opening
Balance Equity — see the chapter on reconciling and closing the period.

Remember that closing a fiscal year zeroes every income and expense account into
Retained Earnings. A Profit & Loss for a closed year still reports that year
correctly, because it reads the postings dated inside it; but the *balances* on
those accounts start again from nothing in the next year, by design.

The first four require `ReportView`. **Budget vs Actual requires `BudgetView`
instead** — what the company intended to spend is a plan somebody may be
trusted to read the accounts without being shown. It is covered in full in the
budgeting chapter.

## Receivables and payables

- **Aged Receivables** — what customers owe, in buckets by how overdue it is.
- **Aged Payables** — what the company owes suppliers, in the same buckets.
- **Contractor Payments** — what each contractor has been paid, over a period.

## Ledgers and books

- **Account Register** — one account, every transaction against it, with a running
  balance. This is the closest thing to a traditional ledger card, and unlike the
  other reports it can also *edit* the rows it booked itself. See the ledger
  chapter for the limits on that.
- **Find Transactions** — search the whole ledger at once: by account, dates,
  amount range, side or wording. The Account Register answers "what happened in
  this account"; this answers "where in the books is the thing I am looking
  for". It only finds — it never changes anything. Note that it shows **posted
  entries only** until you clear the status filter, so a draft you are hunting
  for will not appear until you do.
- **Petty Cash Book** — the float: what was spent, what is left, and
  replenishment.
- **Currency Revaluation** — foreign balances at the rate on a date. It is filed
  under reports but it *posts an entry*, so it is covered in the chapter on
  reconciling and closing the period rather than here.

## Payroll and tax

Three outputs live here, all covered properly in the payroll chapter rather than
duplicated:

- **Tax Summary** — tax withheld per employee for the year, with the slab it fell
  in.
- **FBR Tax File** — the withholding statement in the format FBR accepts.
- **Salary Bank File** — salary payments as a bank upload file, for one payroll
  month.

The first is a report; the other two produce files you hand to somebody outside
the company, so check the payroll month is locked before generating them.

## Bank files

**Bank Payment File** turns selected payments into a bank transfer file. Like the
salary file it is an outbound artefact rather than a report — releasing payments
into a file changes their state, so it is covered with payments rather than here.
