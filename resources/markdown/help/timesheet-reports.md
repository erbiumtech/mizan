## What these show

**Timesheet Utilisation** — hours booked per person for a month, split into
billable and non-billable, with the share of recorded time that was billable and
how many projects each person touched.

**Plan vs Actual** — a grid: one row per person, one column per project, and in
each cell the hours booked against the allocation the assignment promised. This
is the gap the module exists to make visible — allocation says 50%, timesheets
say 20%.

Both take a date and report on the **month** it falls in. A timesheet is filled
in weekly and reviewed monthly, and a year-to-date figure averages a bad month
into eleven others.

## There is no capacity column, and that is deliberate

A utilisation percentage normally divides booked hours by the hours somebody was
*expected* to work. This application will not state that expectation, and the
refusal is older than these reports: a rule that made timesheets and attendance
reconcile would make people book the difference somewhere to make the screen
agree, which produces worse data than the gap it closed.

So **Billable share** is the ratio instead: of the time somebody recorded, how
much of it was billable. It assumes nothing about what their month should have
held, and it cannot be improved by padding.

If you want booked hours against attendance for one person, that comparison
already exists on their own timesheet screen, where it is a comparison and not a
target.

## Reading Plan vs Actual

Each cell reads `12.5h / 50%` — hours booked, then the allocation on the
assignment covering this month.

The three cases worth looking for:

- **An allocation with no hours** (`— / 50%`) is somebody assigned to a project
  who has booked nothing against it. Usually the most interesting row on the
  report.
- **Hours with no allocation** (`12.5h`, no percentage) is time booked against a
  project nobody was assigned to. Either the assignment was never recorded or the
  time went to the wrong project.
- **An em dash alone** means neither side knows about this pairing. It is there so
  an empty cell in a wide grid looks deliberately empty rather than unrendered.

**The table scrolls sideways.** With many projects there are more columns than fit
on screen, so the grid scrolls horizontally rather than cutting columns off. On a
wide report the header row and the totals row do not follow you down the page —
that is the trade for having every column present.

**Columns are capped at the twelve busiest projects.** If there are more, the
report says so in the line underneath it — and the **Booked** column on the right
still totals *every* project, including the ones with no column, so a person's
total always matches their own timesheet.

## Where the figures come from

**Every timesheet entry dated in the month**, whether approved or not. These are
management reports about what was recorded, not billing documents — an unapproved
entry is still time somebody says they spent, and hiding it would make the report
disagree with the timesheet it came from. Billing has its own rules and its own
screens.

**Assignments overlapping the month.** An assignment with no end date is an open
one and counts for every month from its start.

**Both reports list everybody who appears on either side** — anyone who booked
time, and anyone who is assigned to a project. Somebody who did neither is absent
rather than shown as a row of zeroes.

Minutes are stored, not hours, and converted once at the end. A rate multiplied by
a rounded decimal of hours accumulates error across a month of entries.

## Roles and permissions

Requires `ReportView`, gated behind the Timesheets module being enabled for the
company. Read-only — nothing on either screen books, approves or locks time.

Both cover **everybody in the company**. Plan vs Actual is a comparison across a
team by construction; if somebody should not see the whole team's hours, they
should not have `ReportView`.
