## What this is

One row per employee per fiscal year, projecting their annual income and tax
from the payslips issued so far. This is the reconciliation between what's
actually been withheld month to month and what the employee's slab-based
annual tax liability comes to.

**This is a calculated record, not really a hand-maintained one.** Every time
a payslip is saved for an employee, their Annual Tax row for that fiscal year
is fully recalculated from every payslip issued so far — **Total Net
Income**, **Annual Taxable Income**, **Total Annual Tax**, **Paid Tax** and
**Leftover Tax** are all overwritten. If every payslip for that employee and
year is later deleted, the row is deleted too. A **New** and **Edit** form
does exist, but only the Employee and Fiscal Year are actually yours to set —
the moment that employee has another payslip saved for that year, the figures
you typed are replaced by the recalculation. Use this list to see where each
employee stands, not to correct a figure by hand.

## How the projection works

Annual income is projected from whatever payslips exist plus, for the months
not yet paid, either the matching salary setting for that period or the
average of the months paid so far. A 10% medical exemption is applied to
reach a taxable figure, which is then run through the fiscal year's Salary
Slabs (see that page) to get the total annual tax owed. **Leftover Tax** is
whatever remains after subtracting the withholding tax already paid on
payslips issued so far — the amount still to be collected before the year is
out.

## Reading the numbers

- **Total Net Income** — projected annual net salary.
- **Annual Taxable Income** — annual earnings after the medical exemption,
  the figure the slab lookup actually uses.
- **Total Annual Tax** — what the slab calculation says is owed for the year.
- **Paid Tax** — withholding tax already deducted across this year's
  payslips.
- **Leftover Tax** — Total Annual Tax minus Paid Tax; what's left to collect.

## Roles and permissions

| Action | Permission required |
|---|---|
| View | `AnnualTaxView` (Employees see only their own and their downline's; privileged roles see all) |
| Create / Update | `AnnualTaxCreate` / `AnnualTaxUpdate` (bearing in mind the next payslip save overwrites the figures) |
| Delete | `AnnualTaxDelete` |

None of the seeded roles (Accountant, Manager, CEO, Employee) hold these
permissions by default — only **Administrator** does, since this is normally
a byproduct of running payroll rather than something anyone manages directly.
This resource lives in the `payroll` module.
