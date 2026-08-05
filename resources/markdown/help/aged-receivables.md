![Aged Receivables](/images/help/aged-receivables.png)

## What this report shows

What customers still owe, grouped by how overdue it is. Only **Sale**
invoices that are **Issued** or **Partially Paid** count — a Draft has no
effect on the books yet, and a Paid or Void invoice has nothing outstanding to
chase.

## Reading it

Pick an **As of date** (defaults to today) and the report buckets every open
sale invoice by days overdue against its due date (or invoice date, if no due
date was set): **Current** (0–30), **31-60**, **61-90**, and **90+**. The
invoice list underneath is sorted oldest-first — the row that needs chasing
sits at the top.

Amounts are shown in each invoice's own outstanding balance, but the bucket
totals are in the company's base currency, converted at the rate each invoice
was actually issued at. Adding foreign-currency invoices together in their own
currencies would produce a number that means nothing; the base-currency totals
are the ones that add up correctly.

## Download PDF

**Download PDF** opens a printable version of the report as of the date
currently selected, in a new tab — useful for sending to whoever chases the
debt, or filing a snapshot as of month-end.

## Roles and permissions

Gated on `ReportView`, not on any Invoice permission — the same permission
that controls the Reports hub generally. It's reached from the **Reports**
hub rather than the sidebar directly. Lives under the **Invoicing** module —
disabling it removes this report along with Invoices, Contacts and Tax Rates.
