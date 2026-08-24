## What this shows

Every employee document with an expiry date that falls inside the reminder window
— visas, licences, passports, contracts — soonest first, with the ones that have
**already expired** at the top.

Until this report existed, the only way to learn that somebody's visa lapses next
month was to receive the email about it. That made it a compliance fact nobody
could check, only remember.

## It is not the same list as the reminder emails

The daily reminder deliberately goes quiet. It warns when a document *crosses* a
threshold — 60 days, then 30, then 7 — and says nothing on the twenty-nine days in
between, because a job that mails the same warning every morning trains people to
filter it, and then the one that mattered is filtered too.

**This report has no such memory.** It lists everything inside the window whether
a warning has been sent or not. Built the other way it would have shown fewer
documents the more reliably the reminders went out, and been at its emptiest for
the company that had been most diligent.

## Using it

The date is "as at". Everything is measured from it, so a date in the future shows
what will be expiring by then.

**Days** counts down to the expiry. Once a document is past its date the column
reads `12 ago` instead — an overdue count rather than a countdown, because the two
mean opposite things and a bare negative number invites being read as the first.

**Status** names the band the document falls in, using the same thresholds the
reminders are configured with. If your company warns at 90 days, this report
covers 90 days and shows a *Within 90 days* band; nothing here needs changing to
follow it.

**Already expired documents stay on the list.** An expired visa is not a warning
that stops being true, and it has its own tile so it is not lost among the
deadlines that have not yet passed.

## Where the figures come from

**Only documents with an expiry date recorded.** Many — a degree certificate, a
CNIC copy — never expire, and those never appear here. A document that *should*
have an expiry and has none recorded will not appear either, which is worth
knowing: this report can only be as complete as what has been entered.

**Number reads "Not recorded"** where none was entered, rather than being left
blank. A blank cell in a compliance list looks like a fault in the report.

Days are whole days, from midnight to midnight, so a document expiring later today
shows 0 rather than a fraction.

## Roles and permissions

Requires `ReportView`, gated behind the Lifecycle module being enabled for the
company. Read-only — nothing here renews a document, marks it verified, or
silences a reminder.

The report covers **everybody in the company**. If somebody should not see other
people's document details, they should not have `ReportView`.
