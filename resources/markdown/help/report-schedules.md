# Scheduled reports

## What this is

A report, sent by email on a timetable, to the people who would otherwise ask you for
it: the aged receivables every Monday, the payroll register the day after a run, the
SLA summary on the first of the month.

Any report you can open can be scheduled — the built-in ones and the ones you have
built yourself.

## Setting one up

- **Report** — the list is what *you* can open. That matters: the report is rendered
  with your access every time it runs, so you cannot schedule something you could not
  read.
- **Period** — a rule rather than dates: *last month*, *this quarter*, *financial year
  to date*. It is resolved each time it runs, which is the whole point. A fixed range
  would answer last quarter's question for ever.
- **Timetable** — when, in a timezone you choose. "Every Monday at 07:00" means 07:00
  where you say, not on the server.
- **Format** — PDF, CSV, or both. The same files the export buttons produce.
- **Recipients** — email addresses.

## Who it actually reaches

The recipient list is checked **every time it runs**, not when you save it. An address
is sent the report only if, at that moment, it belongs to somebody who

- still has an account here,
- is still a member of this company, and
- may still read reports (`ReportView`).

Somebody who has left, or whose role changed, is simply left out — and the *Report
Deliveries* report records that they were, so a report that reached three of five
people says so rather than looking like it reached everybody.

**An address that matches nobody here is an external recipient.** Sending to one needs
`ReportSendExternal`, which only an Administrator holds, and every external delivery is
recorded. The reason is plain: an emailed report has left the application, and nothing
in here can tell who forwarded it afterwards.

## When a schedule stops itself

If the owner loses access to the report — a permission taken away, a module switched
off — the schedule is **suspended** and says why in the list. It is not rendered with
fewer rows, because a report that quietly shrinks is worse than one that stops: nobody
notices the first.

Fix the access, then press **Resume**. If it was not fixed, the next run suspends it
again with the same reason.

## One send per period

Each period is sent once, however many times the queue retries the render. If a render
fails it is tried three times, and then the owner is emailed — a scheduled report that
silently stopped arriving is worse than one that was never set up, because everybody
assumes the silence means nothing happened.

A report too large to attach (over 8 MB) arrives as a link instead. A 40 MB PDF does
not fail here, it fails at somebody's mail server, hours later, quietly.

## It needs cron and a queue worker

Schedules are dispatched by `reports:deliver`, which runs every fifteen minutes from
Laravel's scheduler. That needs `schedule:run` on cron **and** a running queue worker.
Without both, the list looks perfectly healthy and nothing is ever sent — look at
*Report Deliveries*: no rows at all means the command is not running; rows stuck at
*Pending* means the worker is not consuming.

## Roles and permissions

- **`ReportScheduleView`** — see the list. Accountant and upward.
- **`ReportScheduleCreate`**, **`ReportScheduleUpdate`** — keep your own. Accountant and
  upward, and editing is limited to schedules you own (an Administrator may edit any, so
  that somebody can switch one off while its owner is away).
- **`ReportScheduleDelete`** — CEO and Administrator.
- **`ReportSendExternal`** — send outside the company. Administrator only.

Reading the *Report Deliveries* log needs only `ReportView`: reading what the
application sent is not the same act as choosing what it sends.
