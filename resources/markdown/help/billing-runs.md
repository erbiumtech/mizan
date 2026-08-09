![Billing Runs](/images/help/billing-runs.png)

## What a billing run is

A billing run is one client's bill for one payroll month, assembled
automatically from figures that already exist elsewhere in the system rather
than typed by hand: that month's payslips, office expenses, and advance
repayments. What comes out of it is an ordinary draft **Invoice** — issuing,
paying, ageing and printing it from there on is Invoicing's job, unchanged.

## Creating one <!-- requires: BillingRunCreate -->

Pick the **Client** (a contact flagged as a customer), the **Payroll month**
and **Fiscal Year** the bill covers, an **Invoice Date** and **Due Date**, and
the **Client currency** with the **Rate** agreed for that month — the invoice
itself is always raised in the company's own currency; the rate is only used
to show the client what they're quoted in.

## What gets billed

Click **Build invoice** (or **Rebuild invoice**, once one already exists) to
assemble the draft:

- **Salaries** — one line per employee with a payslip in that month, at their
  full gross earnings (`total_earnings`), not what they were actually paid.
  Tax withheld and other deductions are a settlement between the company and
  the employee; the client funds the whole cost either way.
- **Expenses** — the month's office payments that aren't tied to a payslip and
  aren't salary payments, grouped by transaction type ("Rent", "Utilities").
- **Credits** — if the Advances module is enabled, what employees repaid on
  their advances that month is credited back, since the client already funded
  that money once when the advance was originally paid out.

**Rebuilding replaces the invoice's lines entirely rather than adding to
them** — build it after the month's expenses are finished entering, or you'll
need to rebuild again. Once the invoice has been **issued**, the run can no
longer be rebuilt at all; the "Build invoice" action disappears.

## Statement

**Statement** opens the bill as the client is meant to read it — broken into
the same salary columns their own sheet uses (Basic Salary, Extra Work,
Petrol/Medical/Device Allowance, Bonus, Other), the expense items listed
individually rather than grouped, and the credits and currency conversion
underneath. **PDF** produces the same thing as a printable document. Both
total to exactly what the linked invoice bills — the two are checked to agree.

## Deleting <!-- requires: BillingRunDelete -->

Only possible while the run's invoice is still rebuildable — i.e. not yet
issued — and only for Administrator; the other roles that can build and edit
runs cannot delete them.

## Roles and permissions

- **View / Create / Update** (build and rebuild) — `BillingRunView`,
  `BillingRunCreate`, `BillingRunUpdate`. Accountant, Manager and CEO all hold
  these.
- **Delete** — `BillingRunDelete`, held only by Administrator.
- Lives under the **Billing** module — disabling it removes Billing Runs from
  the sidebar for the company. It reads from Payroll (and Advances, if
  enabled) but doesn't require either to be licensed to appear.
