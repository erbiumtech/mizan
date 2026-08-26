## What this shows

What was invoiced in the financial year to date, three ways over: by **customer**,
by **project**, and by **product**. Gross, credited back, and net.

The project grouping is the one that did not exist anywhere before: invoices have
carried a project since the projects module shipped and nothing reported on it.

## Do not add the three groupings together

They are the same money viewed three ways. A single sale appears once under its
customer, once under its project and once per product line — so summing every row
would treble the revenue.

The record row therefore totals the **customer** grouping alone, and the note says
so. If you want a figure to quote, that is the one.

## Net of credit notes

Credit notes are stored as positive amounts and posted as the reverse, so netting
them off is a subtraction.

**A credit note is attributed to the invoice it credits**, where it names one. The
revenue was recognised against that customer, that project and those products, so
the correction belongs in the same place. Attributing it by its own columns would
move the reversal to whatever happened to be typed on the credit note — which is
usually the customer and rarely the project.

A credit note that names no invoice is attributed by its own columns, because there
is nothing better to use.

## Reading the groupings

**No customer**, **No project** and **Not a product** are real rows, not gaps.

- *No project* is invoicing that was not attributed to any project. The note states
  the amount, because it is the figure that makes the project grouping smaller than
  the customer one.
- *Not a product* is any line typed straight onto an invoice — a service, a one-off,
  a bespoke charge. Common, and dropping it would make the product grouping quietly
  fail to add up.

Rows are ordered biggest net first within each grouping.

## Where the figures come from

**Issued invoices only** — issued, partially paid or paid. A draft is not revenue and
a void invoice never was.

**Sales and credit notes only.** Purchases are cost and appear nowhere here.

**The period is the financial year to date** — 1 July to your date, not 1 January.

Customer, project and product names are read from their tables directly, so a
project renamed after being invoiced shows its current name.

## Roles and permissions

Requires `ReportView`, gated behind the Invoicing module being enabled. Read-only —
nothing here issues, voids or credits an invoice.
