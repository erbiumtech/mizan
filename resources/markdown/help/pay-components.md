![Pay Components](/images/help/pay-components.png)

## What a pay component is

Every earning or deduction that can appear on a payslip — basic wage, an
allowance, a bonus, a tax withholding — is a **Pay Component**. Eleven of them
shipped with the system (basic wage, four allowances, bonus, extra work, and
four deductions) and still live in their own columns on the employee's salary
setting and on the payslip; the calculation reads those columns directly, so
these eleven cannot be edited into a different shape here, and cannot be
deleted.

Anything you add here is different: it gets an amount per employee, a line on
the payslip, and a place in the ledger, all driven from this one row — no
migration, no code change.

## Adding one

Click **New** and fill in:

- **Label** — what it says on the payslip and the client statement.
- **Code** — a stable identifier (e.g. `fuel_card`). Once it has been paid on
  any payslip, the code locks — reports and exports refer to it by this value.
- **Kind** — Earning (added to pay) or Deduction (taken off). This locks too,
  once the component is one of the eleven shipped ones.
- **Taxable** — only shown for earnings. Turn it off for money that is not
  income, such as reimbursing the employee's own spending.
- **Posts to** — the ledger account this component's amount debits or credits
  when a payslip is posted. Only accounts with manual entries allowed appear
  in the list.
- **Payroll account key** — an alternative to naming an account directly:
  reuse an existing payroll mapping (e.g. `bonus_overtime`) instead. A
  component needs one or the other — without either, the payslip it appears
  on has nowhere to post and cannot be posted at all.
- **Sort** — where it falls on the payslip; the shipped components run 10–140.
- **Active** — switching this off stops the component being paid going
  forward without touching payslips that already used it.

## Editing and removing one

The **shipped, column-backed components can never be deleted** — they're part
of payroll's own arithmetic, and removing the row would leave the calculation
looking for a figure nothing names. Any component that has already been paid
on at least one payslip is protected the same way, shipped or not: it's the
record of what that payslip actually paid. In both cases, switch **Active**
off instead of trying to delete.

## Roles and permissions

Configuring pay components is treated as a payroll-configuration decision —
whoever sets the salary slabs is who decides an allowance exists — so it uses
the **Salary Slab** permissions rather than its own:

| Action | Permission required |
|---|---|
| View | `SalarySlabView` |
| Create | `SalarySlabCreate` |
| Update | `SalarySlabUpdate` |
| Delete | `SalarySlabDelete` (and only when not shipped and never paid) |

Of the seeded roles, only **Administrator** holds these — Accountant, Manager
and CEO do not manage salary slabs or pay components, only run payroll and
view payslips. This resource lives in the `payroll` module; disabling that
module for a company removes it from the sidebar entirely.
