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

## Roles and permissions

Administrator only.
