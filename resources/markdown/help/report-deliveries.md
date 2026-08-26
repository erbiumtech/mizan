# Report Deliveries

## What this is

A report of the reports: every scheduled report this company has sent in the last 90
days, who it reached, when, and what failed.

It exists because a scheduled report that quietly stopped arriving is worse than one
that was never set up — everybody assumes the silence means nothing happened. This is
where the silence becomes visible.

## Reading a row

- **Report** — which report was sent, by the name it has in the hub.
- **Period** — the span it covered, resolved at the moment it ran. "last_month:
  2027-01-01..2027-01-31" is one send of one month; the same schedule next month is a
  different period.
- **Status**
  - *Sent* — it went out.
  - *Not sent* — it rendered, and there was nobody left to send it to. Every recipient
    was refused at send time: they left the company, or lost permission to read
    reports. Not a failure — the report was fine and there was nobody to receive it.
  - *Failed* — the render or the mail failed. The reason is in the last column. Three
    attempts, and then the schedule's owner is emailed.
  - *Pending* — claimed but not finished. A row that stays pending means the queue
    worker is not consuming.
- **To** — "3 of 5": how many of the addresses on the schedule were authorised *at that
  moment*. A schedule naming five people that reaches three is correct behaviour, and
  this is where you see it.
- **What happened** — the error, or the reason nothing was sent.

## One send per period

The row is written *before* the work, and a schedule can have only one row per period.
That is what stops a retried render becoming a second email — the window between the
mail leaving and the record being written, which is where duplicates live, does not
exist.

So a report you expected and did not receive is not something to force by re-saving the
schedule: find the row, read the last column.

## Nothing here at all

Either nothing has been scheduled yet, or the scheduler is not running. Scheduled
reports need `schedule:run` on cron **and** a queue worker. See *Scheduled reports* for
the rest of that.

## Roles and permissions

`ReportView`, like every other report — reading what the application sent is not the
same act as choosing what it sends, which is `ReportScheduleView` and its siblings.
