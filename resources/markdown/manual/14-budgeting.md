Every other chapter is about recording what happened. This one is about deciding
what should happen, and then finding out whether it did.

A **budget** is a figure per account for a fiscal year. Once one exists,
**Reports → Budget vs Actual** puts it beside the ledger and shows the gap.

It works the same way for a company and for a personal account: the accounts
differ — Rent Expense and Service Revenue on one, Food and Salary on the other —
but planning, adjusting and reporting are identical.

## 1. Before you start

You need two things, and both are already there on a company that has been set
up: an **open fiscal year**, and a **chart of accounts** with the income and
expense accounts you intend to plan against.

The budget's months come from the fiscal year's dates. A July-to-June year gives
twelve; a short first year gives however many it really has.

## 2. Drawing the plan up

**Accounting → Budgets → New budget.**

Give it a name — the year on its own is fine — and pick the fiscal year. **The
year cannot be changed afterwards.** The plan is stored as dated monthly rows, so
moving a saved budget to a different year would leave every row pointing at a
month the new year does not contain, and the report would find nothing to
compare.

Then add a line per account with the figure **for the whole year**. Nobody wants
to type twelve numbers per account, so you type one and it is divided evenly
across the months.

Two limits, both deliberate:

- **Income and expense accounts only.** A bank balance or a loan is a position at
  a moment, not activity over a period, so there is nothing monthly to compare it
  against.
- **Only accounts that can take a posting.** A heading like "5000 Expenses" never
  receives entries — its children do — so a plan against it would show the whole
  year as unspent forever.

Save. You do not have to plan everything: whatever you leave out still shows on
the report, marked **unbudgeted**.

## 3. Putting the money where it really falls

An even twelfth is wrong for a good deal of real spending. School fees land in
three months. A bonus lands in one. Heating costs four times what it costs in
July.

Open the budget and use the **Monthly Plan** tab. Filter to an account and edit
the months that differ. The year's total is always the sum of its months, so
raising December raises the year by the same amount — nothing is adjusted behind
your back to keep a total looking tidy.

**Saving the budget form afterwards will not undo this.** A yearly figure is
re-spread only when you actually change it, so renaming a budget or editing its
notes leaves every month exactly where you put it.

## 4. Reading the result

**Reports → Budget vs Actual.** Choose the budget and the dates. It opens on the
current year's plan, from the start of the year to today.

| Column | What it is |
|---|---|
| Full year | The whole year's plan for the account |
| Planned | The part of that plan falling in the dates chosen |
| Actual | What was posted to the account in those dates |
| Variance | Signed so that **positive is good news** |
| % of year | Actual against the full-year plan |

Two things about this report are worth knowing before you rely on it.

**A part month counts as a part month.** Ask on 7 August how a year that started
in July is doing, and the plan you are measured against is July plus seven days
of August — not July plus all of August. Counting the whole current month is the
ordinary way an overspend is made to look like an underspend, and it does it
quietly, every time, until the month ends. The Full year column is always there
so nothing is hidden.

**Variance is signed by whether it is good, not by arithmetic.** Spending less
than planned and earning more than planned are opposite subtractions and both
good news, so both are green and positive. A single column that meant "ahead" on
one half of the page and "behind" on the other would be unreadable.

Below the table, **Month by month** shows planned against actual for each month
of the year, with a bar for each. Months you have not reached yet show their plan
and no actual, so the rest of the year reads as still to come rather than as a
sudden collapse to zero.

## 5. What counts as actual

Only **posted** journal entries — the same rule the Profit & Loss uses, which is
why the two reports cannot disagree about the same period.

A draft entry, one waiting for approval, and a rejected one are all invisible
here. If a cost looks missing from the budget report, the first thing to check is
whether its entry was ever posted. See chapter 9.

## 6. Revising a plan

Do not edit last quarter's budget into this quarter's. Switch the old one's
**Active** toggle off and create a new one — "2026-2027 revised after Q1" — for
the same year. The old plan stops being offered on the report but stays here, so
you can still show what was originally agreed alongside what replaced it.

## 7. After the year closes

Closing a fiscal year freezes its budgets along with its ledger: they can no
longer be edited or deleted. Nothing can post into that year any more, so the
only thing changing the plan could achieve is to alter what the year gets
measured against — which is the one number a variance report has to be unable to
move.

## Who can do what

| Role | Budgets |
|---|---|
| Administrator | Everything, including deletion |
| Accountant | Create, edit, adjust months, read the report |
| Manager | Create, edit, adjust months, read the report |
| CEO | The same, plus deletion |
| Employee | No access |

The accountant draws the plan up; deleting one is kept with the CEO for the same
reason deleting an account is. A budget that has been reported against is
evidence of what was agreed.
