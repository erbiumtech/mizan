## What this shows

One month of attendance for everybody: a letter per person per day, and the four
figures payroll reads underneath — paid days, loss of pay, late minutes and
overtime hours.

The figures themselves are not new. Each employee's month has always been computed
this way; what did not exist was a way to read the whole month at once.

## The letters

- **P** present
- **A** absent
- **L** on leave
- **H** holiday
- **O** weekly off
- **½** half day
- **W** worked from home
- **·** not marked

The legend is repeated under the grid, because a legend you have to leave the
screen to read is not a legend.

## `·` is not an empty cell

A day nobody marked is **counted as worked**. That is deliberate and long-standing:
a day nobody recorded is not a day anybody missed, and the alternative silently
docks somebody's pay for a clerk's omission.

The consequence is that a month full of dots reads as a *good* month when it is
really an unfilled one. So the note counts them, and that count is the first thing
to look at before trusting anything else on the screen.

## Disagreement with payroll

A payslip records the paid days it prorated on. This register computes the same
figure from the same calendar, so the two either agree or something is wrong — and
if they disagree, **pay has already been calculated on a figure this register does
not reproduce.**

The note counts how many payslips disagree. It does not mark the agreeing rows,
because thirty-one columns are already competing for space and a column of ticks
earns none of it.

Common causes: attendance edited after the payslip was generated, or a payslip
generated before the month was finished being marked.

If the Payroll module is not enabled there are no payslips to compare against, and
the report simply does not mention it.

## Using it

The date picks the **month**; everything else is derived from it.

**The table scrolls sideways** — thirty-one day columns plus totals is well past the
width of the pane. On a wide report the header and totals rows do not follow you
down the page, which is the trade for having every day present.

**Who appears:** everybody active, plus anybody who left on or after the first of
the month. Somebody who left in June is not on July's register.

## Where the figures come from

**Paid days** is expected days less absent days, where *expected* comes from each
employee's shift pattern and the company holiday calendar. Unknown days count as
paid, as above.

**Loss of pay** is unpaid absence and nothing else. Leave is excluded — a paid leave
day costs the employee nothing, and an unpaid leave day reaches payroll through the
leave module rather than here, so counting it in both places would dock it twice.

**Overtime** is the recorded overtime minutes, in hours. **Late** is the sum of
recorded late minutes for the month.

## Roles and permissions

Requires `ReportView`, gated behind the Attendance module being enabled. Read-only
— nothing here marks a day, approves overtime or changes a payslip.
