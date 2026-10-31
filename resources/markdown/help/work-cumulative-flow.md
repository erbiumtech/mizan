## What this shows

Per project, at the end of each of the twelve weeks to the report's date: how many items were
waiting to be started, how many were in progress, and how many were done.

Read down a project's rows as a chart: the *in progress* band widening week after week is work
being started faster than it is finished, which is the thing a WIP limit exists to stop.

## How it is worked out

There is no nightly snapshot. Where an item stood at the end of a week is worked out from the three
dates the status engine stamps on it:

- **done** if it had been resolved by then;
- **in progress** if it had been started and not yet resolved;
- **to do** if it existed and had been neither.

That is why the report can be read for weeks before anybody thought to look at it.

## The one difference from a snapshot

An item that was finished and later reopened reads as having been open the whole time, because
reopening clears its resolved date. A snapshot taken at the time would have shown it as done for
the weeks in between. For most teams this is a handful of items; if yours reopens a great many,
read the *Done* column with that in mind.

## Per project, not per board

A board is a filter over a project's items, and a cumulative flow over a filter is a chart of the
filter as much as of the work. The project is the unit here.

## Roles and permissions

Reading this needs **ReportView** and the Work module. Nothing on it can be changed from here.
