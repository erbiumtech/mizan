## What this is

Time booked against a project: what people worked on, and what of it can be billed.

## This is not attendance <!-- requires: TimesheetView -->

Worth being clear about, because the two look similar and are deliberately kept apart.

**Attendance** says somebody was at work. **A timesheet** says what they worked on. An
eight-hour day with six hours booked is completely normal — the rest was email, a
meeting, a corridor conversation — and forcing the two to reconcile to the minute would
make both unusable.

They are **compared in a report, never enforced against each other**. A rule that made
the numbers agree would only teach people to book the difference somewhere, which
produces worse data than the gap it closed.

## Booking time <!-- requires: TimesheetCreate -->

Pick the project, the date, and the **minutes** — not hours. 90 is an hour and a half.
Minutes because a rate multiplied by rounded hours drifts noticeably across a month of
entries, and the drift is money.

**Billable** is on by default. Non-billable time is still worth recording: it is what
utilisation is measured against. It never reaches an invoice.

**Task** is a short label — "Migration", "Support" — and is what reports group by.

## Approving <!-- requires: TimesheetApprove -->

Approved time is what a billing run can pick up. Approving matters more here than in
most places in this application, because an approved hour becomes a line on an invoice
a client pays.

Booking your own time is every employee's; approving it is not.

## Billing hours <!-- requires: TimesheetView -->

For a client billed by time and materials rather than by headcount, the monthly billing
run turns approved, billable, unbilled time into invoice lines — **one line per person
per project**, hours × rate, because forty lines of two hours is a document nobody
reads.

The rate is looked for in three places, in order: **the project's rate, then the
employee's rate, then the company default.**

If none of the three says, **that time is not billed, and the run says so by name.** A
made-up rate produces an invoice that looks right and charges the wrong amount, which is
worse than forty hours visibly missing.

**Billed time is frozen.** Once an invoice is built the entries behind it are locked and
can no longer be edited or deleted — a client has paid for those hours, and changing
them afterwards would make the invoice impossible to reproduce. Previewing a breakdown
locks nothing.

## Plan versus actual

Project assignments already carry an allocation percentage and dates. Booked time is
compared against it: allocation says 50%, timesheets say 20%. That gap is usually the
reason a company wants this module.

## Roles and permissions

**View**: `TimesheetView` — your own and your reports'. **Book and edit**:
`TimesheetCreate`, which every employee holds, and which stops applying once an entry has
been billed. **Approve**: `TimesheetApprove`, which they do not.
