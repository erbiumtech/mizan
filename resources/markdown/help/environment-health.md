## What this shows

One row per environment on every project: how many health checks ran, how many
failed, the resulting uptime, how many outages there were, and how long they lasted.

The health widgets on the dashboard show you **now**. This shows you what happened.

## The history is thirty days long

This is the most important thing to know about this report.

Health-check results are **pruned** — thirty days by default, set as
`PROJECT_HEALTH_RETENTION_DAYS`. Older rows are deleted, so there is no way to compute
last September's uptime: the checks it would be computed from no longer exist.

So the **Checks**, **Failed** and **Uptime** columns cover the retention window only,
never the whole financial year, and the subtitle and the note both say so. A report
that offered a year's uptime would compute it from whatever happened to escape pruning
and present one month as though it were eight.

**Incidents are not pruned.** So the Incidents and Downtime columns really do cover
the whole period. The two halves of this report deliberately span different windows;
shortening the incident history to match would throw away the only long record you
have.

If you need a longer uptime history, raise the retention days — but the checks already
deleted do not come back.

## Only confirmed incidents are outages

An incident row opens on the **first** failed check and is only *confirmed* once the
failure threshold is crossed. That is deliberate flap suppression: a single blip
should not page anybody or count as downtime.

The **Incidents** column counts confirmed ones only. Unconfirmed rows are reported in
the note as **suppressed blips** — visible, but not treated as outages. Counting them
would turn every transient failure into an incident, which is precisely what the
confirmation step exists to prevent.

## Uptime is a dash, not nought

An environment nobody has checked is the **opposite** of one that is down: nothing is
known about it. Showing nought per cent would report the worst possible health for the
absence of any information, so the column shows a dash and the standing says **Never
checked**.

That state is its own finding: the environment is monitored, it has a URL, and nothing
has ever run against it. The monitoring is nominal.

## Nobody was told

The finding neither dashboard widget can show. An outage happened while the
environment had **alerts off** or was **muted** — the record exists, the downtime is
real, and no alert went anywhere. Both widgets are point-in-time, and a mute has
usually expired by the time somebody comes looking.

**A limitation worth knowing:** this is judged on the environment's alert settings *as
they are now*, because nothing records whether an alert actually went out. An
environment muted today will show its earlier incidents as unalerted even if alerts
were live at the time. Read it as "these outages would not be alerted under today's
settings" rather than as a certainty about the past.

A mute or a disabled alert is shown in the standing even when nothing has gone wrong —
it is the reason nothing *will* be reported when something does.

## Standing

- **Not monitored** — nobody asked for this one to be watched, or it has no URL. No
  uptime to report and no outage to answer for.
- **Never checked** — monitored, but nothing has ever run.
- **Up** / **Down** — the last check's result, and *Down* also whenever an outage is
  still open.
- **Unknown** — checked at some point, but the current state is not recorded.

Suffixes: `N unalerted`, `muted`, `alerts off`.

## Using it

Both windows end at the **date you are reading**, so an open outage is measured to
that date rather than to the clock — reading last quarter does not credit an outage
with the months since.

An incident counts if it **overlaps** the period rather than fitting inside it. An
outage that began before 1 July and is still open is this period's problem.

Rows are grouped by project, production first within each.

The table is wider than the pane and scrolls sideways.

## Roles and permissions

Requires `ReportView`, gated behind the Projects module being enabled. Read-only —
muting, disabling alerts and resolving an incident all happen on the environment.
Credentials never appear here.
