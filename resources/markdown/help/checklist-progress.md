## What this shows

Every onboarding and exit checklist item still outstanding across the company: who it
is for, what the task is, whose job it is, when it was due and how late it is.

## Why it is not just an overdue list

The obvious version of this report lists what is late. That version cannot show the
two worst cases, because neither of them is late:

**An item with no due date can never become overdue.** The due date is optional, so an
item without one sits outstanding forever and no report anybody reads for lateness will
ever mention it. It shows here as **No due date**, and it sorts *above* items that
simply are not due yet — because it is a finding, not a future task.

**An item with no owner role has not been asked of anyone.** The owner is a role — IT,
HR, Line manager — rather than a person, so that templates outlive whoever holds the
job. An item with no role at all shows as **Nobody** in the Owner role column. The
cell says *Nobody* rather than sitting blank, because an empty cell reads as a
rendering fault instead of the state of the record.

And one that is about more than tidiness: **an exit item still open for somebody who
has already left**. Exit checklists are where access cards, accounts and keys get
revoked, so an open exit item for a former employee is a door that is still unlocked.
Their name is marked `· LEFT` and the note counts them.

## Whose queue is blocking

The note splits the overdue count **by owner role, biggest queue first**, and the rows
are ordered to match — the role holding up the most work appears at the top of the
report rather than scattered through it, and within a role the longest-overdue item
comes first.

Rows are not grouped under role headings on purpose. Grouping would answer "whose
queue is longest" and lose which task, for whom, and somebody acting on this report
needs to know that it is the laptop for the new starter in accounts.

If more than three roles have overdue work, the note names the top three and says how
many more there are. It never truncates silently.

## Progress

The note leads with **how many items are done** — but only across the checklists that
still have outstanding work, not across every checklist ever run. A completion figure
diluted by years of finished onboardings would sit near 100% permanently and tell
nobody anything; measured against the live ones it moves.

## Using it

The date is an **as-at**: lateness is counted to the date you are reading, so you can
ask what was overdue at the end of last quarter.

An item **due on** the date you are reading is *Not yet due* — the day it is due is
still the day it can be done.

Somebody's last day still counts as employed, so they are not marked `LEFT` until the
day after. The same reading Headcount Movement and Assets in Employees' Hands use.

## Roles and permissions

Requires `ReportView`, gated behind the Lifecycle module being enabled. Read-only —
completing an item, setting a due date or assigning an owner all happen on the
employee's checklist.
