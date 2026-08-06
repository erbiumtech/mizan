![Currency Revaluation](/images/help/currency-revaluation.png)

## What this does

Restates every foreign-currency balance at the exchange rate in force on a
chosen date, and posts the difference as a single adjusting journal entry.
Balances in the company's own currency are never affected.

## Using it

Set **As at** — normally a month end — and the page previews the adjustment
that date would produce, using whatever exchange rate was recorded for it. If
nothing has moved since the last time this was run for that rate, the preview
shows no adjustment and **Post revaluation** is disabled — running this
repeatedly is safe and changes nothing unless a rate or a transaction has
moved since.

Posting requires confirmation: it books an adjusting entry dated as at the
date above. Like any other posted entry, correcting a revaluation you ran by
mistake means finding the resulting entry on the **Journal Entries** list and
reversing it from there — this page has no undo of its own.

## Roles and permissions

Requires `JournalEntryCreate` — the permission for posting a journal entry,
not `ReportView` — because this page's whole purpose is to post one. If you
can create journal entries, you can run this; if you can only view reports,
you can't, even though it looks and reads like a report.
