## What this does

Exports the FBR "MONTHLY DETAILS" file: the withholding-tax return format
Pakistan's FBR expects for tax withheld from salaries under section 149.
Reached from the Reports hub, not the sidebar.

## Using it

Pick a **Fiscal Year** and a **Tax month** — the month picker's counts only
include payslips that actually have withholding tax deducted, so a month
showing zero really will produce an empty file. The list below shows exactly
those payslips (with tax > 0) for the selection.

Click **Download FBR Tax File** to get an `.xlsx` named `MONTHLY DETAILS
<month>.xlsx`, one row per taxed payslip, with the columns FBR's own upload
format expects: taxpayer NTN/CNIC, name, city, address, status, business
name, taxable amount, and tax amount. The company's name is used as the
business name on every row. This is a read-only export — nothing about the
payslips or their tax is changed by generating it, and it can be regenerated
as many times as needed.

## Roles and permissions

Gated on `ReportView` plus the `payroll` module being enabled. Of the seeded
roles, **Accountant**, **Manager**, **CEO** and **Administrator** hold
`ReportView`; **Employee** does not and never sees the Reports hub this is
reached from.
