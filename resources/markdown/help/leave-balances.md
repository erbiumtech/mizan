## What this is

What each person is entitled to, of each leave type, in each leave year — and what
is left.

You see your own and, if people report to you, theirs.

## The balance is worked out, never stored <!-- requires: LeaveEntitlementView -->

**Left** = opening + carried in + accrued + adjustments − taken.

Every term is shown as its own column so the number can be explained. There is no
stored balance field anywhere, on purpose: a stored total drifts away from the days
actually taken, and nothing tells you when it has. The same reason an account's
balance here is added up from its entries rather than kept on the account.

**Taken** counts the days approved requests actually consumed — not the length of
the ranges. **Awaiting a decision** is shown under the balance rather than deducted
from it, because asking for leave has not spent it.

A negative balance in red is somebody who was allowed to go over. That is permitted
and is not an error.

## Correcting a balance <!-- requires: LeaveEntitlementUpdate -->

Use **Adjust**. Enter days — negative to take them away — and a reason.

**Every adjustment is kept, and none can be edited or deleted.** Two adjustments in
one year both stand, each with its own reason and the name of whoever made it. A
mistake is fixed by a *second* adjustment in the opposite direction.

That is the whole point of recording them this way. "Who gave me these three days,
and when" is the first question a disputed balance opens with, and it is the one
question an editable field can never answer.

## Where these rows come from <!-- requires: LeaveEntitlementView -->

Normally nobody types them. A nightly job opens the current leave year for every
active employee and works out the accrual from each type's rules.

**Add an entitlement** by hand is there for two cases: opening data when the module
first starts, and a leave type added part-way through a year where waiting until
tomorrow is not an answer.

**Opening days** is the set-up figure — what somebody already had when this module
started. Later corrections are adjustments, so they keep their reason. **Accrued**
and **Carried in** are normally written by the job and the year-end roll; typing
over Carried in bypasses the per-type cap.

## The leave year <!-- requires: LeaveEntitlementView -->

Each row carries the leave year it belongs to, and **that window never moves**.

This matters when a company changes its leave-year basis — calendar to fiscal, say.
The change applies to leave years opened afterwards. A year already under way keeps
the window it started with, so balances part-way through do not restate and approved
leave does not land in a year that no longer exists.

## The year end

Unused days **lapse**, and nothing is paid for them. If carry-forward is switched on
for the company, each type carries up to its own cap first and the rest lapses.

Switching carry-forward on later does not give back days that have already lapsed.
The decision was made when the year turned, and it stays made.

Days are only ever paid out on **separation**, for types marked encashable.

## Roles and permissions

**View**: `LeaveEntitlementView` — your own and your reports'. **Create**:
`LeaveEntitlementCreate`. **Adjust**: `LeaveEntitlementUpdate` — this is what moves
somebody's balance, so the Employee role does not hold it; nobody credits themselves
days. **Delete**: `LeaveEntitlementDelete`, and never once an adjustment exists.
