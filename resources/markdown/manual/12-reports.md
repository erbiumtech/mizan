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

## Sales and pipeline

Five reports off the CRM pipeline, and unlike everything above them they read no
journal entries at all — so the posting rule at the top of this chapter does not
apply to them. What limits these is what has been *recorded on the deal*: its
stage, its value, its expected close date and whether anybody has planned a next
action.

| Report | Answers | Period it uses |
|---|---|---|
| Pipeline by Stage | What is open right now, by stage, weighted and plain | None — it is a snapshot |
| Sales Forecast | What is expected to close this month | The month your date falls in, forward |
| Win / Loss | Won against lost, by source, owner and reason | The financial year to your date |
| Rotting Deals | Open deals that have stalled or have nothing planned | None — it is a snapshot |
| Target Attainment | Each salesperson against their target | Each target's own period |

Three things surprise people here, and all three are deliberate:

- **A won deal is not in the forecast.** It is an invoice waiting to be raised, so
  counting it as expected revenue would state the same money twice.
- **Win / Loss starts on 1 July**, like every other year-to-date figure in this
  application — not on 1 January.
- **Amounts in another currency are converted at the rate stored on the deal**, not
  today's rate. Where a total mixes currencies, the report names them.

These five cover **every deal in the company**, not just your own. If somebody
should not see the whole pipeline, they should not have the report permission.

## Operations

**SLA Performance** and **SLA Breaches** are the helpdesk's two, and the pair is
worth understanding as a pair: the first says what proportion of a month's
tickets met their commitments, the second names the open tickets that have missed
one while there is still something to do about them. One is read at a month end,
the other every morning.

The rule to hold on to is that **these clocks are reported and never enforced**.
Nothing in this application refuses an action, escalates a ticket or notifies
anybody because a commitment was missed — a category's SLA is a number of minutes
recorded against it, and these two reports are the only place that number has any
effect at all.

Three things about the figures:

- **Tickets are counted by when they were opened**, not when they were resolved.
  An SLA is the promise made when a ticket arrives, and counting by resolution
  would drop every still-open ticket out of the figures — so the report would
  improve as the backlog got worse.
- **A ticket nobody has answered yet counts as missed** once its response time is
  up. A breach that has not finished happening is the one still worth acting on.
- **The by-assignee half is a diagnosis, not a ranking.** Commitments differ per
  category, so somebody working the urgent queue is measured against a tighter
  clock. What the split is good for is one category being missed by one person
  and met by everybody else.

The totals row adds up the category rows only — adding both halves would count
every ticket twice.

## People and payroll

**Timesheet Utilisation** and **Plan vs Actual** read a month of booked time
across everybody, rather than one person at a time.

The first splits each person's hours into billable and non-billable and states
the **billable share** — of the time they recorded, how much was billable. There
is deliberately no capacity column and no percentage against one: this
application will not state how many hours somebody was expected to work, because
a rule that made timesheets and attendance reconcile would make people book the
difference somewhere to make the screen agree. That produces worse data than the
gap it closed.

The second is a grid of people against projects, and each cell reads
`12.5h / 50%` — hours booked, then the allocation the assignment promised. Three
cases are worth hunting for:

- an allocation with no hours against it,
- hours against a project with no allocation,
- and a person whose total is nowhere near any of their allocations.

Two things about that grid. It **scrolls sideways** rather than cutting columns
off, and on a wide report the header and totals rows stop following you down the
page — that is the trade for having every column present. And it draws columns for
the **twelve busiest projects**; if there are more it says so underneath, while
the Booked column on the right still totals every project, so a person's total
always matches their own timesheet.

Both reports count **every entry dated in the month, approved or not.** These are
management reports about what was recorded, not billing documents. Billing has its
own rules and its own screens.

### Documents Expiring

Also under People and payroll, and the one report here that is about compliance
rather than money: every employee document with an expiry inside the reminder
window, soonest first, with the already-expired at the top.

It is **not** the same list as the reminder emails. Those go quiet on purpose — a
document is warned about when it crosses 60, 30 and 7 days and is silent in
between, because a job that mails the same warning every morning trains people to
filter it. This report has no such memory and lists everything in the window,
warned about or not.

Two things follow from that. The window and the status bands come from the same
thresholds the emails use, so widening those widens this. And an expired document
stays on the list with its days shown as `12 ago` rather than a negative number,
because a deadline that has passed is a different problem from one approaching.

The report can only be as complete as what has been entered: a document that
should carry an expiry date and has none recorded appears nowhere.
