## What this does

Salary withholding tax, by employee and by month, for a fiscal year. The FBR
Tax File answers "what do we file this month" — this page answers "what have
we withheld from this person this year," which is the question an employee
asks and the one a year-end reconciliation needs. Reached from the Reports
hub, not the sidebar.

## Using it

Pick a **Fiscal year** and, optionally, a **Month** — leave the month as "The
whole year" for a full-year view. The page shows two breakdowns built from
the same underlying payslips:

- **By employee** — each employee who had any tax withheld, their taxable
  amount and tax total for the selection, and how many months are included,
  sorted by tax paid (highest first).
- **By month** — the same totals grouped by month instead, in fiscal-year
  order (starting from the fiscal year's own start month, not January).

Only payslips with withholding tax greater than zero are counted — a payslip
below the taxable threshold withheld nothing and appears on no tax return, so
it's excluded here exactly as it is from the FBR file. The **Taxable**
figure is always total earnings, deliberately kept identical to what the FBR
export puts in its own Taxable_Amount column, so this summary can never
disagree with the return actually filed against it.

Click **Download PDF** to get the same report as a file, for the fiscal
year/month combination currently selected.

## Roles and permissions

Gated on `ReportView` plus the `payroll` module being enabled. Of the seeded
roles, **Accountant**, **Manager**, **CEO** and **Administrator** hold
`ReportView`; **Employee** does not and never sees the Reports hub this is
reached from.
