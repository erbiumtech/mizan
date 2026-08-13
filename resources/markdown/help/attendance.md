## What this is

One row per person per day: who was in, who was not, and what nobody has answered for
yet.

## "Not marked" is not "absent" <!-- requires: AttendanceView -->

This is the most important thing on the screen, and the badge in the sidebar counts it.

A day shows as **Not marked** until somebody says what it was. That is *not* the same
as absent, and it is deliberately grey rather than red. A month nobody filled in must
never read as everybody being away, because absent is the one status that can cost
somebody money.

Nothing in this application treats an unmarked day as an absence. When pay pro-rating
is switched on, unmarked days are counted as **worked** — a day nobody recorded is not
a day anybody missed, and the alternative docks pay for a clerk's omission.

The way out of "not marked" is a **correction**, not an administrator quietly editing
the row. See Attendance Corrections.

## Opening a month <!-- requires: AttendanceCreate -->

**Open a month** creates the rows so there is something to fill in. Weekly offs and
public holidays are written as such, days already covered by approved leave are marked
on leave, and everything else starts as not marked.

It never touches a day that already exists, so running it twice is safe.

## Importing <!-- requires: AttendanceCreate -->

**Import a CSV** takes `employee_id, date, status, check_in, check_out, note`. Status
accepts `P`, `A`, `L`, `H`, `W`, `HD`, `WFH` or the full word — whatever your device or
your clerk actually writes.

**Check the file first.** The preview runs every check the real import runs, including
the refusal to contradict approved leave, and writes nothing. Problems are reported
with their line number and the employee code that caused them.

Re-importing the same file changes nothing the second time: a day is keyed on the
employee and the date, so it is updated rather than duplicated. That is also what makes
a biometric device straightforward to add later — the device is not integrated, but any
device that can produce a file can feed this.

## Hours, overtime and lateness <!-- requires: AttendanceView -->

**Worked** is the time between in and out. A shift that ends after midnight is handled;
it is not read as a negative day.

**Overtime** is time beyond the day's expected hours, which come from the work pattern.
On a weekly off or a holiday, *every* worked minute is overtime — there were no expected
hours to exceed.

Overtime shows as **recorded, not paid** until a company switches overtime pay on. That
is honest rather than lazy: until an hourly rate, a multiplier and caps all exist,
recorded minutes have no defined route into money, and inventing one would be worse than
the gap.

**Late** is measured against the pattern's start time past a grace period. It is
recorded and nothing more — no pay is docked for lateness anywhere in this application.

## A day covered by leave <!-- requires: AttendanceView -->

Cannot be edited here, and cannot be imported over. Approved leave and attendance
disagreeing about whether somebody was at work is the one contradiction this module
refuses outright rather than resolving quietly. Withdraw the leave first if the person
actually worked.

## How long these are kept

Three years by default, then pruned automatically. This is the only table in the system
that grows with usage rather than with headcount, so it is cleaned up from the first
day rather than after somebody notices. A company that needs longer can say so.

## Roles and permissions

**View**: `AttendanceView` — your own and your reports'. **Record and edit**:
`AttendanceCreate` / `AttendanceUpdate`, which is HR's rather than every employee's.
**Delete**: `AttendanceDelete`. Employees get View and the right to ask for a
correction, and nothing else.
