## What this shows

Quotations for the financial year to date, by the month they were issued: how many
went out, how many were accepted, declined or ran out of time, how many of the
accepted ones have actually been invoiced, the win rate, and how many are about to
lapse.

## Superseded versions are excluded

A quote revised three times is **one** opportunity, not four. Every figure here
ignores superseded versions.

That matters more than it sounds. Counting each version would inflate what was
issued by however often your company negotiates, and push the win rate *down* for
doing the thing that wins work. Whichever version is current carries the
opportunity; its predecessors are absent.

## Two conversions, not one

- **Issued → accepted** is whether the work was won. That is the win rate.
- **Accepted → invoiced** is whether anybody billed for it.

The second is the one nothing else in this application will tell you, and it is the
one worth checking first: an accepted quote with no invoice against it is revenue
the company has agreed and never asked for. The note counts them.

## The win rate

Accepted as a proportion of quotes that have been **decided** — accepted, declined,
or run out of time.

A quote still inside its validity is not counted, because it has not been lost. A
company that has just quoted a lot of work would otherwise look as though it were
losing it. An **expired** quote *is* counted as a loss: it ran out without anybody
saying yes.

A dash means nothing has been decided yet, which is a different fact from nought
per cent.

## Expiring

The last column counts quotes issued that month which are **still answerable** and
run out within 14 days. A draft is not counted — nobody has been given it — and a
quote that has already lapsed is not *expiring*, it has expired.

The column sits on the month whose quotes are running out, so the row is where
you'd have gone looking anyway.

## Using it

The period is the **financial year to date** — 1 July to your date, not 1 January.

Expiry is computed as well as read: a quote past its validity counts as expired even
if the nightly sweep has not yet changed its status, because an expired quote must
not be acceptable in the meantime.

Rows with nothing in a column show a dash rather than a nought, so the months where
something actually happened stand out.

## Roles and permissions

Requires `ReportView`, gated behind the Quotations module being enabled. Read-only —
nothing here accepts, declines or invoices a quote.
