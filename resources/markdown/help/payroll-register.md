## What this shows

One month of payroll as a grid: a row per payslip, a column per part of pay, and
totals down every column and across every row. Until this report existed you could
open any one payslip and there was no way to see a month.

The three columns on the right — **Earnings**, **Deductions**, **Net** — are that
person's row added up. The record row along the bottom is every column added up.
Both are computed from the cells you can see, so the report can be checked by
adding it up.

## The two figures at the top

**Net pay** is the register's own total. **Salaries payable** is what the ledger
says: the credit to that account from this month's payslip entries.

Those two should be the same number, and the line underneath says whether they are.
When they are not, it says which of two things is happening:

- **Some payslips are not posted.** The ordinary case, and not a problem — the
  register counts every payslip and the ledger only has the posted ones. The
  difference is exactly the unposted payslips' net pay.
- **Everything is posted and they still differ.** This one is worth
  investigating. It means a payslip changed after its entry was posted, or an entry
  was edited by hand.

If the payroll account mapping is incomplete, the comparison says so rather than
showing a nought. A missing figure and a figure of nought mean different things.

## Using it

**The month comes from the date.** Payslips in this application are filed by month
*name* and fiscal year rather than by a date, so the report takes the month the
date falls in and the fiscal year containing it. It prints the month it chose, so
you can always see which one you are reading.

**The table scrolls sideways.** With eleven built-in components plus whatever your
company has added there are more columns than fit on screen, so the grid scrolls
rather than cutting columns off. On a wide report the header and totals rows do not
follow you down the page — that is the trade for having every column present.

**A dash is not a nought.** A blank cell means that component was not part of that
person's pay at all. A component that was part of their pay and came to nothing
would be a nought.

## Where the figures come from

**Every component of every payslip**, whether it lives in a payslip column or was
added as a component. Those are recorded in the same place for exactly this
reason, so this report keeps no list of its own about what pay is made of — add a
component and it appears here with no further work.

**A component you have since deactivated still gets a column** if a payslip that
month paid it. Dropping it would take the amount out of the columns and leave it in
the row total, and the register would stop adding up.

**Earnings here includes expense reimbursement.** A payslip's own "total earnings"
excludes it, because a reimbursement is the employee's own money coming back rather
than something earned. This column is every earning component added up, so that
Earnings less Deductions equals Net exactly — and the reimbursement is visible in
its own column so you can see the difference.

**One row per payslip, not per person.** Two payslips for the same person in one
month appear as two rows; collapsing them would hide that it happened.

**Only posted entries count towards the ledger figure** — the same rule the Trial
Balance and the Balance Sheet follow, so all three agree.

## Roles and permissions

Requires `ReportView`, gated behind the Payroll module being enabled for the
company. Read-only — nothing here recalculates a payslip, posts an entry or
changes a figure.

The report covers **everybody's pay**. If somebody should not see other people's
salaries, they should not have `ReportView`.
