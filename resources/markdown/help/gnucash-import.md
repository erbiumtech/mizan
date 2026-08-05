## What this does

Brings a GnuCash CSV export into the books. This is a one-off setup job, not
something anybody reads day to day — it lives under **Settings** rather than
Reports for that reason.

There are two kinds of import, decided by whether you set a target account:

- **Leave "Register target account" empty** for a full import — chart of
  accounts and transactions, built fresh from the export.
- **Choose a target account** if the CSV is a register export for one
  specific account — its rows are imported as transactions against that
  account instead.

## Using it

1. Upload the **GnuCash CSV export** (up to 10 MB), and set the target account
   only if this is a register export.
2. **Preview** — parses the file and shows what it would create, without
   committing anything yet.
3. **Confirm** — commits the preview for real.

The uploaded file is held temporarily between Preview and Confirm. If you
wait too long, it can expire — if Confirm reports the file has expired,
upload it again and re-preview.

Every completed import is written to the activity log with a full record of
what was created, since this is exactly the kind of one-off action worth being
able to look back on later.

## Roles and permissions

Requires its own `GnuCashImport` permission, gated behind the Accounting
module being enabled for the company — it is not tied to Account or Journal
Entry permissions, since importing a whole book is a different kind of trust
than editing one entry.
