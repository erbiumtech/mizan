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

### Loans Outstanding

Filed with the ledgers, and the one report in this application that checks itself
against the accounts: it states what the loan schedules say is still owed beside
what the liability accounts say, and tells you whether the two agree.

That comparison is the reason to open it. The two sides of a loan are maintained
separately — the schedule when an instalment is recorded, the account by whatever
journal entries have been posted — so they drift, and each cause is worth knowing
about: an instalment paid straight through the bank, a manual entry against the
liability account, or a loan restructured without its schedule being rebuilt. The
report cannot know which side is right, so it states both figures and the gap
between them.

Per loan it shows what is left, the interest still to come, the instalments
falling due in the next twelve months — the figure a balance sheet note wants for
the current portion of long-term debt — how far through the schedule the loan is,
and the next payment date.

Two things that are easy to misread. A loan with nothing recorded yet shows its
**full principal**, not nought. And only **active** loans are listed, with their
accounts excluded from the comparison too — so a deactivated loan whose balance is
still sitting in the accounts will not show up here as a difference.

### Cash Commitments

The one forward-looking report in the hub: everything committed over the next
ninety days from the date you give it — recurring journal entries, subscriptions,
and recurring invoices — on a single timeline.

**Raised** is the column to read first. *Yes* means the entry or invoice exists,
so it is a payable or receivable somebody can chase. *Not yet* means nothing has
been created and the decision is still open: the agreement can be ended, the
amount changed, the schedule edited. A report that showed all of it as fact would
be a forecast the ledger had to honour.

Amounts carry no sign; **Direction** is its own column. The two totals at the top
are money leaving and money arriving, and the figure on the record row is the net
— a column mixing both directions has no meaningful sum.

Two things worth knowing. Opening this report **raises nothing**, unlike the bank
payment file, which creates rows as a side effect of being opened. And a module
you do not have simply contributes nothing: without invoicing there are no
recurring invoices here and no arriving total, rather than an error.

### Payroll Register

The month of payroll as a grid: a row per payslip, a column per part of pay, and
totals down every column and across every row. Before this, the only way to read a
month was one payslip at a time.

The two figures at the top are the point. **Net pay** is the register's own total;
**Salaries payable** is what the ledger says, from this month's payslip entries.
The line underneath says whether they agree, and when they do not it says which of
two things is happening:

- **Some payslips are not posted.** Ordinary, and not a problem — the register
  counts every payslip and the ledger only has the posted ones.
- **Everything is posted and they still differ.** Worth investigating: a payslip
  changed after its entry was posted, or an entry edited by hand.

Three things about the grid. The month comes from the date, because payslips are
filed by month name rather than by date — the report prints the month it chose. A
**dash is not a nought**: it means that component was not part of that person's pay
at all. And **Earnings here includes expense reimbursement**, which a payslip's own
"total earnings" excludes — so that Earnings less Deductions equals Net exactly,
with the reimbursement visible in its own column.

### Leave Liability

What unused encashable leave would cost if everybody took it as cash on the date
you give it. Also under People and payroll.

**This figure is in no account, and the report says so under the total.** Nothing
in this application posts a leave provision — there is no leave-liability account
and no entry creates one — so the amount here appears nowhere in the balance sheet.
Every other report in this chapter that quotes a liability can check itself against
the ledger; this one cannot, because the balance it would check against does not
exist. What to do about that is a decision for whoever owns the accounts, and this
is the number they need to make it.

Only **encashable** leave types count, because only those become money — a type
that lapses at year end costs nobody anything. If none of your types are
encashable, the report says that rather than showing a nought, which is a different
fact from "nobody has any left".

The amount is the same calculation a final settlement uses: the same types, the
same positive-balance rule, and basic wage over the encashment divisor. That is
deliberate, so the accrual and what actually gets paid cannot disagree.

Two readings to know. Only people **still in service** appear — a leaver is a
payable or a debt, not a provision. And somebody with **no recorded wage** shows
their days with dashes for the money, because those days are worth an amount nobody
has recorded; the note then says the total is incomplete.

### Unbilled WIP

Under Operations: hours that have been worked, approved, and never invoiced — by
project, with the customer and what they are worth at the rate that would be
charged.

Two ways to read it. It is an **asset** — work delivered and not yet billed — and
it is **revenue leaking**, because an hour that has sat unbilled for five months is
usually one nobody is going to bill.

It is a balance rather than a period: everything unbilled up to the date, however
old. That is deliberate, since a month-scoped view would hide exactly the hours
worth chasing.

**Two hour figures, and the difference matters.** *Hours* is everything unbilled.
*Unpriced hours* is the part of them that no rate could be found for — not on the
project, the employee, or the company default. Those hours are **not** in the Value
column, and the note says how many are missing from it. A made-up rate would make
this a wrong number on a balance sheet, which is worse than an incomplete one that
says so; if the unpriced figure is large, the fix is to set a rate.

Like Leave Liability, **this figure is in no account** and the report says so.
Nothing here posts work in progress for timesheet hours. (Construction's WIP report
is a different figure about construction jobs and does not overlap.)

Two smaller readings: it follows your company's "require approval to bill" setting
rather than assuming one, and an internal project says *Internal* rather than
leaving the customer blank.

### Stock on Hand

Under Operations: every active product with how much is on hand, what it cost on
average, what it is worth, its reorder level and any flags — all as at a date.

**Stock value** is the valuation added up; **Inventory accounts** is what the ledger
says those accounts hold. They should match, and the line underneath says whether
they do. Two things explain a difference:

- **Stock on deactivated products.** The rows are active products, but switching a
  product off does not unpost the entries that put its stock in the accounts. This
  is the commonest difference and nobody's mistake — the report names the amount.
- **Anything else** means a movement posted against the account by hand, or a
  product moved between accounts after stock was booked to the first.

A product that names no inventory account is *not* outside the accounts — its stock
sits in the default one, and the report reconciles against the same account the
posting used.

**The flags share one column** on purpose, because a product both below its reorder
level and untouched for months is the case worth acting on:

- **Reorder** — on hand is at or below the reorder level. A level of zero means
  there is no level, so those products are never flagged.
- **No movement** — nothing in or out for 90 days.
- **Never moved** — no movement history at all, usually something set up and
  forgotten. Deliberately distinguished from the above: "no history" and "moved
  long ago" are opposite facts.

A product with nothing on hand shows a dash for average cost rather than a nought,
which would claim the stock was free.

### Advances Outstanding

Under People and payroll: every advance still being recovered — what was lent, what
has come back, what is left, the instalment and how many months it has to run. It
is a receivable from staff, and it feeds final settlement, so a wrong figure here
leaves the company out of pocket when somebody leaves.

**The register and the advances account will usually not agree**, and that is not a
fault. Nothing posts an advance when it is entered: recording one here says money
was lent, and the ledger only learns of it if the payment out was also booked
against the advances account — while a payslip's recovery credits that account. The
report says which way round the difference falls, because the directions mean
opposite things. The account holding *less* is advances lent without a payment
booked. Holding *more* is either a payment that was not an advance, or an advance
settled without its recovery being recorded.

One row per advance rather than per person, because two advances on different
instalments have two different answers to "when is this cleared".

### Expense Claims

Also under People and payroll, and not to be confused with the Expense Claims
screen itself — this is the report about them.

**Owed to staff** is the figure to look at: everything approved and not yet paid,
which is a liability nothing in this application posts. A claim reaches the ledger
only when a payslip reimburses it, and by then it is an expense rather than
something owed.

Two periods are at work, deliberately. The rows are the **financial year to date**
— 1 July to your date, not 1 January. But the amount owed is a **balance**: a claim
approved last March is owed just as much as one approved yesterday, so it counts
whenever it was claimed.

The reimbursed total is **not** checked against an account, and the report says why:
reimbursements post to the same account code as meal recovery, and one account
holding two unrelated flows cannot be attributed to either.
