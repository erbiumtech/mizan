![Fiscal Years](/images/help/fiscal-years.png)

## What this is

The accounting periods journal entries and reports book into. Every journal
entry belongs to a fiscal year, and closing one locks it against further
posting.

## Creating one

Click **New**, give it a **Year Name**, and set whether it's **Active**.

## Closing a year

Click **Close** on an open year. Before locking, the app checks three
things, and shows you every one that's failing rather than just the first:

- **Opening Balance Equity must be cleared.** Every opening balance is
  entered as a credit to this account, so a leftover balance in it means some
  account's opening figure was never entered.
- **The trial balance must balance** — total debits must equal total credits
  as of the year's end date.
- **Every journal entry in the period must be posted.** Anything still
  Draft, Pending Approval, Approved-but-not-posted, or Rejected has to be
  posted or removed first — see the Journal Entries help for that workflow.

If none of these block it, closing takes effect immediately: **nothing may
post into a closed year** — not a manual journal entry, not one generated
automatically by a payment or invoice.

## Reopening a year

**Reopen** removes the lock and allows posting into that year again. There's
no separate "why" required — anyone with update rights on fiscal years can
reopen a closed one.

## Roles and permissions

**View**: `FiscalYearView`. **Create**: `FiscalYearCreate`. **Update**
(covers both Close and Reopen): `FiscalYearUpdate`. **Delete**:
`FiscalYearDelete`.
