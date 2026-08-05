## What this report shows

What the company still owes its suppliers, grouped by how overdue it is. Only
**Purchase** invoices (bills) that are **Issued** or **Partially Paid** count
— a Draft has no effect on the books yet, and a Paid or Void bill has nothing
outstanding to settle.

## Reading it

Pick an **As of date** (defaults to today) and the report buckets every open
bill by days overdue against its due date (or invoice date, if no due date was
set): **Current** (0–30), **31-60**, **61-90**, and **90+**. The list
underneath is sorted oldest-first — the row that needs paying first sits at
the top.

Amounts are shown in each bill's own outstanding balance, but the bucket
totals are in the company's base currency, converted at the rate each bill was
actually issued at. Adding foreign-currency bills together in their own
currencies would produce a number that means nothing; the base-currency
totals are the ones that add up correctly.

## Download PDF

**Download PDF** opens a printable version of the report as of the date
currently selected, in a new tab — useful for a payment run review or a
month-end snapshot.

## Roles and permissions

Gated on `ReportView`, not on any Invoice permission — the same permission
that controls the Reports hub generally. It's reached from the **Reports**
hub rather than the sidebar directly. Lives under the **Invoicing** module —
disabling it removes this report along with Invoices, Contacts and Tax Rates.
