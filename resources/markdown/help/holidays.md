## What this is

The days your company does not work — public holidays, and any day the company
closes on its own account.

It lives in **Settings**, not inside Leave or Attendance, because both of them
read it. One list means a day off is the same day off wherever it is counted; two
lists means one screen says Eid was Monday and another says Tuesday, and nobody
finds out until a payslip is wrong.

## Adding a holiday <!-- requires: HolidayCreate -->

Click **New**, pick the **Date**, and name it as it would appear on a calendar —
"Eid ul-Fitr", "Independence Day". The weekday is shown next to each date in the
list, which is how you spot a holiday that already falls on a day nobody works.

**One row per date.** A second holiday on a date already listed is refused: every
part of the app asks this list the same question — "is this date a holiday" — and
two answers for one day is not extra detail, it is a day that gets counted twice.

**Notes** is for the reason, where there is one worth keeping: a factory shutdown,
a day granted after an election.

## Recurring <!-- requires: HolidayCreate -->

**Recurring** marks a holiday as one to expect again next year. It is a note to
whoever builds next year's calendar, and nothing more — **no date is ever worked
out automatically**.

That is deliberate. Eid, Ashura and Rabi ul-Awwal follow the lunar calendar and
land on a different date each year; a fixed holiday can shift too when it falls on
a weekend. A calendar that guessed would be wrong most years, and wrong in a way
nobody checks until leave and payroll have already been run against it. Somebody
confirms the date.

## What it changes <!-- requires: HolidayView -->

Leave counts the days a request actually consumed, so a holiday inside a leave
request is not taken off anyone's balance.

That count is worked out **when the request is approved, and not again**. Adding a
holiday afterwards does not go back and change leave already approved or a month
already paid — a settled figure that quietly moves is worse than one that is
slightly generous. If a holiday is added late and a request should be recounted,
the request has to be reopened.

## What this list does not decide

Weekends. Which days of the week a company works is a separate thing — a six-day
week, or a Friday-Saturday weekend, is not a holiday and is not recorded here.
This list only ever answers whether a specific date is a holiday.

## Roles and permissions

**View**: `HolidayView` — held by every role that needs to know which days are
closed. **Create**: `HolidayCreate`. **Update**: `HolidayUpdate`. **Delete**:
`HolidayDelete`. Setting the company calendar is the Administrator's.
