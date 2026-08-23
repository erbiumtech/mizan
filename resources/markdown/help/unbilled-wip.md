## What this shows

Hours that have been worked, approved, and never invoiced — by project, with the
customer they belong to and what they are worth at the rate that would be charged.

Two ways to read it, and both are the point:

- **A balance sheet figure.** Work delivered and not yet billed is an asset, and
  before this report it appeared nowhere.
- **Revenue leaking.** An hour that has sat unbilled for five months is usually one
  nobody is going to bill. This is the list to work through.

## It is a balance, not a period

The date is "as at", and everything approved and unbilled up to it is on the
report — however old. An hour booked in March and still unbilled in August is
exactly the hour worth seeing, so there is deliberately no from-and-to: a
month-scoped view is the one shape that would hide it.

## This figure is in no account

Like Leave Liability, nothing in this application posts unbilled work in progress.
There is no WIP account for timesheet hours, so the value here appears nowhere in
the balance sheet, and the report says so under the total every time.

(Construction has its own WIP report and its own accounts. That is a different
figure about a different subject — construction jobs, not timesheet hours — and
the two do not overlap.)

## Unpriced hours

An hour can only be valued if a rate can be found for it: the project's rate, else
the employee's, else the company default. Where none of those is set, the hour
**cannot be priced**, and this report does what a billing run does — it names the
gap rather than guessing.

So there are two hour figures:

- **Hours** is everything unbilled. Those hours were worked.
- **Unpriced hours** is the part of them no rate could be found for. That part is
  *not* in the Value column, and the note says how many hours are missing from it.

A made-up rate would make this a wrong number on a balance sheet, which is worse
than an incomplete one that says so. If the unpriced figure is large, the fix is a
rate on the project, the employee, or the company default.

## Using it

**Approved only, if your company requires approval to bill.** That is the
`require_approval_to_bill` setting, and the report follows it rather than assuming:
a WIP figure including time billing would refuse is a figure no invoice could
realise.

**Already-billed hours are gone from here.** An entry is locked when it reaches an
invoice, and locked entries are not WIP.

**Internal projects say "Internal"** rather than leaving the customer blank. A
project with no client is not missing data — it is the reason those hours are not
billable to anybody.

**Without the Invoicing module** the customer column shows an id rather than a
name. The report still works; that module is not required for it, because a
company may invoice elsewhere and still want to know what is outstanding.

## Roles and permissions

Requires `ReportView`, and both the Timesheets and Projects modules — every hour
here belongs to a project, so a company without them has no WIP to read.

Read-only. Opening this report bills nothing and locks nothing.
