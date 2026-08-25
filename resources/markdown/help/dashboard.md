## What this is

The company on one screen: a row of headline figures, then money, sales,
service, people and stock — each panel fed by the same service as the report
behind it, so a figure here and the report it comes from are the same number
rather than two attempts at it.

Every panel is optional in the only sense that matters: **you see a panel if
your company has that module and your role may open the report it summarises.**
A panel you cannot use is absent rather than shown and refused, so two people
signing in to the same company can see different dashboards — that is the gating
working, not something misconfigured.

## The period applies to everything

The button at the top right sets the span every figure covers — **this month**,
**this quarter**, **the financial year to date**, or a **custom range** — and the
span in force is printed under the title so the figures are never unlabelled.

**Every named period ends today.** "This quarter" is the quarter so far, not a
quarter two thirds empty, because the question a dashboard answers is how things
are going rather than how they will have gone.

**Quarters are financial, not calendar.** The year runs 1 July to 30 June, so its
quarters begin in July, October, January and April — the same quarters the
accounts use. A dashboard calling January to March "this quarter" while the
ledger called it Q3 would be two screens using one word for two spans.

**The period is in the address**, so a dashboard you send somebody opens on the
period you meant. Bookmarking "the year so far" works; so does sending a colleague
a custom range.

Not every panel can use a span, and those that cannot say so. Stock on hand,
money owed to you and documents about to expire are **balances**: they are true at
a date rather than over a period, so they use the period's *end*. Reading a year
end gives the valuation that stood there, which is the figure that ties to the
accounts.

## Arranging it

**Arrange** opens a list of every panel you can see. Drag by the handle on the left to
reorder, pick a width — half, two thirds or full — and use the eye to hide one. **Done**
puts you back on the dashboard. Nothing needs saving: each change is kept as you make it.

The widgets are deliberately not on screen while you arrange. Each drag is a round trip
to the server, so leaving twenty-odd panels rendered would mean re-running every total
behind them each time you moved a card.

**What you arrange is yours.** Nobody else's dashboard changes, and a panel you cannot
open is not in the list at all — arranging can never show you something your role or
your company's modules do not include.

**Hidden panels stay in the list**, greyed, which is the only way to bring one back.

**Widgets added later appear on their own.** Your arrangement records the order you
chose, not the list of panels you had, so a chart added next month turns up at the end
of your dashboard rather than never turning up at all.

**Reset to the company default** discards your arrangement and puts you back on the one
everybody starts from — and keeps you on it, so if an administrator changes the default
later you move with it.

### If you are an administrator

Two extra options appear while arranging:

**Make this the company default** saves what you are looking at as the arrangement
everybody starts from. It takes nothing away from anybody: people who have arranged
their own dashboards keep them, and this is what new people, and anybody who resets,
will see.

**Apply the default to everybody** discards every personal arrangement in the company —
including your own, unless you have made it the default first — so that everybody sees
the company default. It asks before doing it, because arrangements people made for
themselves cannot be recovered.

## Some figures are up to five minutes old

The four most expensive panels — the revenue and expenses chart, billable share,
largest debtors and stock on hand — are cached for **five minutes**. Opening the
dashboard four times in a morning then costs one set of totals rather than four.

**The reports are never cached.** If a figure here matters, open the report behind
it: the report reads the ledger as it stands, which is why a report whose rows have
to add up to its total cannot be quietly five minutes behind. So the dashboard and
a report can disagree for a few minutes after a posting, and the report is the one
that is right.

The panels you would most want fresh are deliberately not cached at all: leave
requests awaiting a decision, tickets that have breached their SLA, and today's
attendance. A queue five minutes old is a queue somebody has already dealt with.

**Nothing polls.** The dashboard does not refresh itself in the background —
reload the page when you want the figures again.

## Roles and permissions

Reaching the dashboard needs nothing beyond being signed in to a company; each
panel carries its own permission, named for the report it summarises. Everything
here is read-only: opening the dashboard creates nothing, posts nothing and
approves nothing.
