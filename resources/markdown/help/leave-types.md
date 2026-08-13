## What this is

The kinds of leave your company grants, and the rules for each. Annual, casual,
sick, unpaid, and anything else you offer.

These are **rows you edit**, not settings we ship. A company that grants 18 annual
days changes a number here — no deploy, no toggle, no waiting for us.

## The day counts we shipped are a starting point <!-- requires: LeaveTypeView -->

**Confirm them against the law where you operate.** Statutory leave minima in
Pakistan are *provincial*: Sindh, Punjab, Khyber Pakhtunkhwa and Balochistan each
legislate their own shops-and-establishments rules, and they differ. What arrived
with your company is a reasonable default, not advice, and the same position this
application takes on tax slabs.

Maternity leave is the figure most likely to be wrong for you.

## How the days arrive <!-- requires: LeaveTypeView -->

**Earned** decides when an entitlement is credited:

- **The whole year at once** — the usual choice. All of the year's days on day one.
- **A twelfth each month** — the balance grows as the year goes on.
- **After twelve months of service** — nothing until somebody's first anniversary.
  The statutory shape, and the right one for Hajj or maternity leave.
- **Earned by working a day off** — compensatory time. This needs the Attendance
  module, which is not built yet, so a type set this way **credits nothing today**.
  The list says so rather than looking like it works.
- **Not counted down** — sick leave a company does not ration. Such a type has no
  balance at all, which is different from a balance of zero.
- **No entitlement** — unpaid leave, which is granted rather than earned.

A **mid-year joiner** gets a share of the first year, if that is switched on in
Settings: the joining month counts when they started on or before the 15th.

## Paid, and what that means <!-- requires: LeaveTypeView -->

**Paid** leave costs the employee nothing. **Unpaid** is the only kind that could
ever reduce pay — and only once pay pro-rating is switched on, which nobody has
done. Until then, marking a type unpaid records the fact and changes no payslip.

**Encashable** means the type is paid out in a final settlement when somebody
leaves. It has nothing to do with the year end: days that lapse at the year end
are never paid for.

## Carry-forward <!-- requires: LeaveTypeUpdate -->

**Days that may carry forward** is a cap, and it only does anything when
carry-forward is switched on for the whole company in Settings. With it off, this
number sits there doing nothing, and the list says so.

Leave it at **0** for a type that should never carry, even at a company that
carries other types. That is how "annual carries five days, casual carries none"
is expressed.

## Notice, documents and half days <!-- requires: LeaveTypeUpdate -->

**Notice expected** is a number of days. Whether it *blocks* a request or merely
warns is one company setting, in Settings → Leave, and it warns by default —
casual and sick leave are asked for late by their nature.

**Ask for a document** requires an attachment on the request. It is visible to the
approver and to HR only.

**Half days** can be switched off per type. Maternity and Hajj leave are set that
way as shipped.

## Deleting <!-- requires: LeaveTypeDelete -->

A type that anybody has taken leave against, or that has an entitlement, **cannot
be deleted** — it would orphan their record. Switch **Active** off instead: it
disappears from the request form and every existing record stays readable.

## Roles and permissions

**View**: `LeaveTypeView` — every role needs it, because the request form has to
name what somebody is asking for. **Create**: `LeaveTypeCreate`. **Update**:
`LeaveTypeUpdate`. **Delete**: `LeaveTypeDelete`. Setting the company's leave
policy is HR's and the Administrator's.
