## What these show

Two views of the same commitments, read at different times.

**SLA Performance** — what proportion of the month's tickets met their response
and resolution commitments, broken down by category and then by the person
assigned. Read at a month end.

**SLA Breaches** — the open tickets that have missed a commitment, or whose time
is already up. Read every morning, because everything on it is still fixable.

## The rule that matters most

**These clocks are reported, never enforced.** Nothing in this application
refuses an action, escalates a ticket or notifies anybody because a commitment
was missed. A category's SLA is a number of minutes recorded against it, and
these two reports are the only place that number has any effect at all.

That is deliberate, and it is why the reports say it on their own faces. A
percentage that looks like a penalty invites somebody to close tickets in order
to improve it, which turns a measure of service into a measure of nothing.

## Using SLA Performance

The date picks the **month**. Everything on the report is the tickets *opened* in
that month — not the ones resolved in it. An SLA is a promise made when a ticket
arrives, so a ticket that arrived this month and is still open belongs to this
month's figures. Counting by resolution date would quietly drop every ticket
still in breach, which is the population most worth looking at.

The table has two halves and they are in that order for a reason:

- **By category** is the report. The commitment belongs to the category — it is
  the only thing carrying an SLA figure — so a rate here is measured against one
  clock and means what it says.
- **By assignee** is the diagnosis, not a ranking. Somebody working the urgent
  queue is measured against a tighter clock than somebody working the general
  one, so two people's percentages are not directly comparable. What the split is
  good for is one category being missed by one person and met by everybody else.

The totals row adds up the **category** rows only. Adding both halves would count
every ticket twice.

**Reopenings, not reopened tickets.** A ticket reopened three times counts three
times, because that is three failures to resolve it.

**Satisfaction shows a dash where nobody rated anything.** An average of no
ratings is not a bad score.

## Using SLA Breaches

The list is **open tickets only**, and it includes tickets nobody has answered yet
whose response time has already run out — not just ones answered late. A breach
that has not finished happening is the one still worth acting on.

A closed ticket that breached is history: it is in the performance figures and not
here.

**Unassigned is counted in its own tile.** A breached ticket with nobody on it is
the worst row in the table, so it is stated rather than left as a blank cell.

Age is shown in hours up to two days and in days after that.

## Where the figures come from

A ticket has breached its response when the minutes from **opened** to **first
external reply** exceed the category's response commitment — or, for a ticket with
no reply yet, when the minutes from opened until now do. Resolution works the same
way against the resolution commitment.

**An internal note does not start the response clock.** Otherwise a company could
meet its commitment by writing a note to itself, which is the exact failure a
measured SLA exists to make visible.

**A category with no commitment never breaches.** Both figures blank means the
category is not measured, not that it is always met.

## Roles and permissions

Requires `ReportView`, gated behind the Support module being enabled for the
company. Read-only — nothing on either screen changes a ticket, a reply or a
category.

Both reports cover **every ticket in the company**, including those assigned to
other people. That is what the by-assignee split is for; if somebody should not
see the whole helpdesk, they should not have `ReportView`.
