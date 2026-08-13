## What this is

Which days of the week your company works, and how long a working day is.

## Why this is not one setting <!-- requires: WorkPatternView -->

Because companies here run more than one. A factory floor on six days and an office on
five is ordinary, not an edge case, so the pattern is a row an employee is assigned to
rather than a company-wide switch.

Assignments are **dated**. Somebody who moves from the floor to the office in March was
still on the floor pattern in February, and last February's attendance has to be read
against the pattern that was actually in force — otherwise a worked Saturday
retrospectively becomes a weekly off, and time in lieu is credited for it.

## Expected hours is the field to get right <!-- requires: WorkPatternUpdate -->

It does two jobs, and the second one is money.

**Overtime** is time worked beyond it. And when overtime pay is switched on, the hourly
rate is derived by dividing the basic wage by the contracted days and these hours —
this is the only place the system records how long a working day is. A wrong figure
here is a wrong overtime rate for everybody on the pattern.

Leave it blank for a day with no fixed length; nothing will be treated as overtime on
that day.

**Start time** is used only to work out lateness, which is recorded and never docked.

## The default pattern <!-- requires: WorkPatternUpdate -->

One pattern can be the default, and it covers everybody who has not been assigned one
of their own — which is most companies, most of the time. Setting a new default clears
the old one, so there is never a question of which applies.

## What this replaces

Before this module, leave worked out weekends from a single company-wide setting. With
Work Patterns in place, leave reads the employee's own pattern instead, so a six-day
worker's leave is counted correctly. The old setting remains for companies that have
not bought Attendance.

## Deleting <!-- requires: WorkPatternDelete -->

A pattern anybody has been assigned to cannot be deleted: it would retrospectively
change which days were working days, and with them what every past month means.
Reassign people to another pattern instead.

## Roles and permissions

**View**: `WorkPatternView` — everybody, since forms name the pattern somebody is on.
**Create / update / delete**: `WorkPatternCreate`, `WorkPatternUpdate`,
`WorkPatternDelete` — HR's and the Administrator's.
