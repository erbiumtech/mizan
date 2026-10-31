## What this shows

For every item resolved in the period, grouped by project and type: how many, how long they took
once somebody started them, and how long the person who asked waited altogether.

- **Cycle time** is from the first time an item entered an *in progress* status to the time it
  entered *done*.
- **Lead time** is from when the item was created to when it entered *done*.

The difference between the two is the queue: time the item spent waiting for somebody to pick it
up. It is the figure a team can do least about by working harder.

## Medians, not averages

Both columns are medians. One item that sat for a quarter would otherwise make a week-long cycle
read as a month, and the median is what a typical item actually experienced.

## Never started

Items resolved without ever being in progress — closed as *won't do*, marked as a duplicate, or
moved straight to done. They count in *Resolved* and in lead time, but have no cycle time, and the
column is there because a high number makes the cycle time beside it mean less.

## Where the dates come from

The three timestamps are written by the status engine when an item moves, and never by hand. That
is what makes these figures worth reading: nobody can set a start date to make a cycle look short.

## Roles and permissions

Reading this needs **ReportView** and the Work module. Nothing on it can be changed from here.
