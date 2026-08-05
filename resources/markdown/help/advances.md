![Advances](/images/help/advances.png)

## What an advance is

Money lent to an employee, recovered back in monthly instalments through
payroll. **Recovered** and **Remaining** are never typed in — they're
calculated from the actual recovery history every time, so they can't drift
out of step with what payroll has really taken.

## Creating one

Click **New**, pick the **Employee** (fixed once saved — the recoveries
already taken belong to that person), the **Advance amount**, and the
**Monthly deduction**. Payroll takes the monthly deduction from every payslip
run for that employee until the advance clears — **the last instalment is
automatically trimmed to whatever is left**, so it can never take more than
was actually lent.

**Status** controls what payroll does next:
- **Active — deducting** — payroll keeps taking instalments.
- **Settled** — set automatically once the balance reaches zero; also settable
  by hand.
- **Cancelled — stop deducting** — stops future instalments without writing
  off what's still owed; the balance simply stops moving.

## How recovery actually happens

There's no separate screen for taking an instalment — it happens as a side
effect of running payroll. Each payslip save books that month's deduction
against the employee's active advances, oldest first, and re-running or
correcting a payslip **updates** its recovery rather than adding a second one
(so re-saving a payslip never double-deducts). If more than one advance is
active for the same employee, each takes its own instalment before any extra
goes toward paying one down faster.

**Record repayment**, on this list, is for the other case: money handed back
outside payroll — cash repaid directly, or a correction. It can't exceed
what's still outstanding, and updates the same recovery ledger a payslip
instalment would.

## Deleting

Only possible while nothing has been recovered yet — once payroll has taken
even one instalment, the advance *is* the record of it, and deleting it would
leave that payslip's deduction pointing at nothing.

## Roles and permissions

- **View / Create / Update** (`AdvanceView/Create/Update`) — Accountant,
  Manager, CEO, Administrator.
- **Delete** (`AdvanceDelete`) — Manager, CEO, Administrator (not Accountant),
  and only while unrecovered, per above.
- Employees do not see this resource at all — it isn't in their permission
  set, even for their own advances.
- Depends on the Advances module being enabled, and on Employees (an advance
  always belongs to one).
