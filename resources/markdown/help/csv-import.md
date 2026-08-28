![CSV Import](/images/help/csv-import.png)

## What this is

A quick way to bring in existing records at setup — a spreadsheet of
contacts, a spreadsheet of products, or a trial balance from whatever you
used before. For a full chart of accounts and history from GnuCash
specifically, use GnuCash Import instead; this page is for the simpler,
spreadsheet-shaped case.

## Importing

1. Pick **What are you importing?** — the column layout expected changes with
   this choice.
2. Click **Download template** to get a CSV with the exact headers expected.
3. Choose **Balances as at** if you're importing Opening Balances — this is
   the date the opening journal entry is posted on, usually the day before
   your first month in the app.
4. Upload your **CSV file**.
5. Click **Check the file** before importing anything. This previews how many
   rows are ready and how many would be skipped, and why — nothing is written
   to the database at this step.
6. Click **Import**. This button only appears once a preview has run and
   found at least one importable row — there's no way to import blind.

Changing what you're importing, or re-uploading a file, clears any existing
preview, since it no longer describes what's about to happen.

## Bringing an existing company onto the system

The opening trial balance carries your *totals*. Two of the import types carry the
documents behind two of those totals, and without them the totals are all you have:

- **Opening invoices and bills** — the individual invoices that make up your
  receivables and payables. Without these, Aged Receivables shows a company that is
  owed millions as owed nothing, because it reads invoices rather than the ledger.
  Each row carries its own date, because that date is what ages it.
- **Opening stock** — what is on each shelf, and what it cost. Without these, the
  ledger has your inventory value and the valuation engine has an empty shelf, so
  every sale takes its cost from nothing.

**Neither of them posts anything.** The value is already in the ledger from the
trial balance, so posting again would double it. What they do is fill the other side
— the documents and the lots — and the *Control accounts* health check is what tells
you the two sides now agree.

**Do it in this order**, running a trial balance after each stage so a mistake
belongs to one batch:

1. contacts, then products;
2. opening balances (the trial balance);
3. opening invoices and bills;
4. opening stock;
5. a journal entry for anything left over.

Both are re-runnable: fix the file and upload it again. An opening invoice that has
actually been posted in this system is refused rather than overwritten, and
re-importing stock replaces the opening lot rather than adding a second one — it
never touches a movement a real delivery or sale created.

## Roles and permissions

Administrator only.
