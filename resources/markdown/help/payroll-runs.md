![Payroll Runs](/images/help/payroll-runs.png)

## What a payroll run is

A **Payroll Month** (internally a "payroll run") is created automatically the
moment the first payslip for that month is raised — there is no **New**
button here, and no form to fill in. This list exists to show which months
have been agreed and which are still open, and to sign a month off or reopen
one.

## Reading the list

Each row is one calendar month and shows:

- **Payslips** — how many exist in that month.
- **Accepted** — how many of those the employee has acknowledged, out of the
  total. Worth checking before signing a month off.
- **Gross** and **Net** — the month's totals across every payslip in it.
- **Status** — Open, or Signed off (with the date and who signed it), or
  reopened (with the reason given).

## Signing off a month <!-- requires: PayrollRunLock -->

Click **Sign off** on an Open month. This freezes it: none of its payslips
can be changed, added to, or deleted afterwards. A month with no payslips in
it cannot be signed off — there is nothing yet to agree to.

## Reopening a signed-off month <!-- requires: PayrollRunLock -->

Click **Reopen** and give a reason — it's required, and it stays on the
record. This is deliberate: a month that was agreed and then changed is
exactly what an auditor asks about later, so the reason has to exist before
the reopen is allowed to happen at all. Reopening puts the month back to
Open, which lets its payslips be edited again.

Payroll runs are never deleted — doing so would orphan the payslips inside
it and lose the record that it was ever signed off. Reopening is the only way
to change a locked month.

## Roles and permissions

| Action | Permission required |
|---|---|
| View | `PayrollRunView` |
| Sign off / reopen | `PayrollRunLock` |
| Create / delete | Not possible for anyone — runs are created automatically and never deleted |

Of the seeded roles, **Accountant** holds both `PayrollRunView` and
`PayrollRunLock` — running payroll and signing the month off is Accountant
work here, not held back for Manager/CEO the way approving a Journal Entry
is. Manager and CEO inherit the same permissions from Accountant.
**Administrator** also holds both. **Employee** holds neither and never sees
this list. This resource lives in the `payroll` module; disabling it removes
the list from the sidebar for the company.
