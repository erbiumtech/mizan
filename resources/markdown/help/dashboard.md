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
