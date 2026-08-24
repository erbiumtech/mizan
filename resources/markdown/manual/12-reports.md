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

### Attendance Register

Under People and payroll: one month of attendance for everybody, a letter per person
per day, with paid days, loss of pay, late minutes and overtime alongside.

**P** present, **A** absent, **L** leave, **H** holiday, **O** weekly off, **½**
half day, **W** from home, and **·** for a day nobody marked. The legend is
repeated under the grid.

That last one matters more than it looks. A day nobody marked is **counted as
worked** — a day nobody recorded is not a day anybody missed, and the alternative
docks somebody's pay for a clerk's omission. So a month full of dots reads as a good
month when it is really an unfilled one, and the note counts them. Check that figure
before trusting anything else on the screen.

**It also tells you when payroll disagreed.** A payslip records the paid days it
prorated on; this register computes the same figure from the same calendar, so if
they differ, pay has already been calculated on something this report does not
reproduce. Usually that means attendance was edited after the payslip was generated.

The grid scrolls sideways, and on a wide report the header and totals rows do not
follow you down the page.

### Hiring Funnel

Under Operations: every vacancy with its applications by stage, how many offers were
taken, how long hiring took, and how long the vacancy has been open.

Four figures worth reading carefully, because each is deliberately narrower than it
might appear:

- **Offers taken** is accepted over offers *answered*, not issued. An offer nobody
  has replied to yet is not a refusal.
- **To offer** counts to the offer being *sent*. A draft nobody has issued is not a
  milestone the candidate has reached.
- **To join** counts *accepted* offers only — a declined offer's joining date never
  happened.
- **Open for** is blank once a vacancy is filled or closed. Ageing is a question
  about something still open.

A **withdrawal is not a rejection** and appears in no stage column: that person left
of their own accord. They are still in the applications total, and the note says how
many withdrew.

Both time figures are averages over however many offers a vacancy produced, so read
them against the application count on the same row.

### Quotation Conversion

Under Sales and pipeline: quotations for the financial year to date by the month
they went out — issued, accepted, declined, expired, invoiced, the win rate, and how
many are about to lapse.

**Superseded versions are excluded from every figure.** A quote revised three times
is one opportunity, and counting each version would inflate what you issued by
however much you negotiate — pushing your win rate down for doing the thing that
wins work.

**Two conversions are worth reading separately.** Issued → accepted is whether the
work was won. Accepted → invoiced is whether anybody billed for it, and that is the
one nothing else here will tell you: an accepted quote with no invoice is revenue
you have agreed and never asked for. The note counts them.

The **win rate** is accepted over quotes that have been *decided* — accepted,
declined or expired. A quote still inside its validity is not counted as a loss;
one that ran out is.

**Expiring** counts quotes still answerable that run out within 14 days. A quote
past its validity is *expired* rather than expiring, and it counts as expired from
the day it lapses rather than from the day the nightly sweep notices.

### Revenue by Customer, Project and Product

Under Receivables and payables: what was invoiced in the financial year to date,
three ways over — by customer, by project, by product — gross, credited back, and
net. The project grouping is the one that existed nowhere before: invoices have
carried a project all along and nothing reported on it.

**Do not add the three groupings together.** They are the same money viewed three
ways: one sale appears under its customer, its project and each of its products.
The record row totals the **customer** grouping alone, and that is the figure to
quote.

**Credit notes follow the invoice they credit.** The revenue was recognised against
that customer and project, so the reversal belongs there — not against whatever was
typed on the credit note, which usually carries a customer and no project.

Three rows that look like gaps and are not: **No customer**, **No project** and
**Not a product**. The middle one is the figure that makes the project grouping
smaller than the customer grouping, so the note states it. The last is any line
typed straight onto an invoice — a service or a one-off — and dropping those would
make the product grouping quietly fail to add up.

Only **issued, partially paid and paid** invoices count. A draft is not revenue, a
void one never was, and purchases are cost.

### Credit Notes Issued

Under Statutory reporting, and it is a compliance report rather than a list.

A credit note may be issued against an invoice for a limited number of days — **180
by default** — and beyond that it needs the Commissioner's approval under rule 22.
**Nothing in this application refuses a late credit note**; the rule is reported and
not enforced, the same as the SLA clocks. So this is the only place a reversal made
without cover is visible.

The **Standing** column gives one of four answers:

- *Within 180 days* — nothing to do.
- *Approved · reference* — outside the window, with an approval somebody should be
  able to produce.
- *No approval recorded* — outside the window with nothing against it. This is the
  exposure.
- *No invoice named* — the window cannot be computed, so the report declines to call
  it compliant. A credit note can legitimately be standalone, but saying "within the
  window" would be a guess in your favour on a tax question.

**Without approval** at the top is an amount rather than a count, because one large
credit note matters more than five small ones. And the window follows your company's
own setting, so if you have changed it from 180 days the report judges by yours.

### Headcount Movement

Under People and payroll: joiners, leavers, headcount, turnover and average tenure,
month by month across the financial year to date.

Two columns deliberately read different sources. **Joiners** come from the joining
date, because a month's joiners is a fact about that month and somebody re-employed
has joined again. **Average tenure** is continuous service from the first
job-history record — the same rule a final settlement uses, because somebody
re-employed after a break has two spans and only the current one counts. Measuring
tenure from the original joining date would credit the company for the gap.

**Turnover** is leavers over the *average* of opening and closing headcount. That is
the conventional formula and the only one that behaves at both ends: against opening
headcount a company that halved would understate its rate, and against closing it
would overstate it — or divide by zero in a month that ended with nobody left.

A dash in the turnover column means there was nobody to leave, which is not the same
as nought per cent.

### Assets in Employees' Hands

Under People and payroll: every laptop, phone, SIM, vehicle and access card issued
and not returned, who has it, since when, and what it is worth. Previously visible
one employee at a time on their own record, so nothing asked the company-wide
question.

**Value out is the recovery, not an estimate of it.** It is the same figure a final
settlement charges for unreturned kit, summed over the same items — not a second
calculation that happens to agree. Which is why an item with no value recorded shows
a **dash rather than a nought**: settlement recovers nothing for something nobody
priced, and a nought would read as kit that is genuinely worthless.

Three findings, each stated in the note when present:

- **A holder who has left** — marked `· LEFT`, sorted to the top, and totalled
  apart as *Held by leavers*. The urgent row: if the settlement is already paid, the
  recovery has been missed.
- **No value recorded** — the item will be recovered at nothing.
- **Disposed on the register while still out** — the accounts say the company no
  longer owns a thing somebody is holding.

The **Register** column reaches the fixed-asset register for anything capitalised.
*Not capitalised* is ordinary — a phone bought out of petty cash has a description
and no link. *Not on register* means the link cannot be followed, either because
Accounting is switched off or the asset row is gone; the report says so rather than
asserting the item is on the books.

Somebody's last day still counts as employed, matching Headcount Movement, so a
person is never on the payroll in one report and gone from another on the same date.

The date is an as-at, so a laptop issued after it does not appear.

### Final Settlements

Under People and payroll: what each leaver's settlement was made of — encashment and
gratuity owed to them, notice recovery, unrecovered advance, unreturned kit and other
deductions owed back — and what it came to.

It lists **leavers, not settlements**, and that is the point. Somebody who left and
was never settled appears nowhere else in the application, because every other view
of a settlement starts from one that exists. Those rows sort to the top and read
*Not built*.

**Nothing here posts.** A settlement is a proposal; approving one records that the
figure was agreed and pays nothing. So there is no ledger balance to reconcile
against — the Phase 2 rule does not apply — and the report's value is three
disagreements instead:

- **Not built** — a leaver nobody prepared a settlement for.
- **Net differs** — the stored net is no longer the sum of its parts. It is written
  on build and on approve but not on edit, and every component is editable. The Net
  column always shows the sum of the parts, so the row adds up; the status cell is
  where the disagreement is reported.
- **Kit moved** — a *draft* quoting an unreturned-kit figure that no longer matches
  what the employee holds. Drafts only: an approved settlement is frozen by
  agreement, and flagging it as stale would be arguing with the agreement.

**Net payable** and **Owed back** are kept apart rather than summed. A negative
settlement is legitimate — an unrecovered advance and an unreturned laptop can leave
somebody owing — and adding the two gives a figure that is neither.

A dash in a component column means nought; a row of nothing but dashes is a *Not
built* row. Notice recovery is never computed by anything, so a dash there means
nobody decided one was due. The footer totals each component.

### Onboarding / Offboarding Progress

Under People and payroll: every onboarding and exit checklist item still outstanding,
who it is for, whose job it is, when it was due and how late.

**Deliberately a progress report, not an overdue list**, because the two worst cases
are not late:

- **No due date** — the due date is optional, so an item without one can never
  *become* overdue. It sits outstanding forever and no report read for lateness will
  ever mention it. It sorts above items that simply are not due yet, because it is a
  finding rather than a future task.
- **Nobody** in the Owner role column — the owner is a role, not a person, so
  templates outlive whoever holds the job. An item with no role has not been asked of
  anyone. The cell says *Nobody* rather than sitting blank, which would read as a
  rendering fault.

And one with a security edge: an **exit item still open for somebody who has already
left**. Exit checklists are where access cards, accounts and keys get revoked, so this
is a door still unlocked. Their name is marked `· LEFT`.

"By owner role" is delivered in the note and the ordering rather than by grouping the
rows: the note splits the overdue count by role biggest-queue-first, the worst-blocked
role's items rise to the top, and every row still names one actionable task. Grouping
under role headings would answer whose queue is longest and lose which task for whom.
More than three roles and the note names the top three and says how many more — never
a silent truncation.

Progress counts only the checklists that still have outstanding work. Diluted by every
finished onboarding it would sit near 100% permanently.

An item due **on** the date you are reading is *Not yet due* — the day it is due is
still a day it can be done.

### Consent Register

Under Sales and pipeline: who may be contacted on which channel, and the evidence
behind each permission — state, source, date and recorder.

**Compliance evidence, not marketing statistics.** There is deliberately no opt-in
rate, no channel comparison and no trend. A percentage invites a target, and the moment
consent has a target somebody starts managing the number instead of the record.

The state is **derived, never stored**. There is no subscribed checkbox: every grant and
every revocation is its own row, so somebody who opted in, out and in again has three
rows and one current state. The **Changes** column counts that trail. The register shows
the latest row per subject per channel, resolved exactly the way the sender resolves it
when deciding whether to contact somebody — including the tie-break on record id when
two rows share a timestamp, so what this report permits is what a campaign will do.

Each channel is its own permission; agreeing to email is not agreeing to WhatsApp.

It is a true **as-at**: the latest record on or before your date, so a later revocation
does not rewrite the past. A subject whose only records come later is absent rather than
shown as revoked — nothing was recorded then, and **no record means no permission**. An
empty register is not "nothing to show", it means nobody may be contacted, and the
report says so.

The finding is a **grant with no source**: *they agreed* is worth nothing without *and
here is how*, and on every other screen such a permission looks identical to a
defensible one. Counted on grants only — removing somebody from a list needs no
justification, and only a permission has to be defended.

Rows are ordered by what needs doing: unevidenced grants, then grants with no recorder,
then sound grants, then revocations.
