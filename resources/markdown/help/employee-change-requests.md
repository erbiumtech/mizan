![Employee Change Requests](/images/help/employee-change-requests.png)

## What lands here

When an employee (anyone without the Administrator, Manager, or CEO role)
edits their own **Employee** record or their own **Employee Settings**
(compensation) row, the edit doesn't save directly — it's parked here as a
pending request instead. Nothing on the underlying record changes until
someone with approval rights decides it.

Two kinds of request show up, distinguished by the **Changes To** column:

- **Employee profile** — name, email, personal email, NIC, date of birth,
  phone, bank details, address.
- **Salary settings** — the compensation figures on an Employee Settings row
  (basic wage, allowances, bonus, deductions). The period and fiscal year a
  settings row governs are never requestable — only administrators set those.

There is no **New** button here — a request can only be created by an
employee editing their own record, never directly.

## What you see

Approvers (anyone holding the approve permission) see every request.
Non-approvers see only their own — this list is scoped, not filtered, so an
employee never sees another employee's pending change.

## Deciding a request

Click **View Changes** to see exactly what's being requested against what the
record currently holds, then:

- **Approve** — writes the change immediately. For a profile request, the
  name/email pieces update the linked login as well as the employee row (or
  just the login, if that's all that changed) — that part happens outside the
  main save, so it can succeed even if nothing else does.
- **Reject**, with an optional reason — the underlying record is untouched;
  the request itself stays on record as rejected.

Both actions are also available as bulk operations from the toolbar over a
selection. Approving or rejecting a request that's already been decided is
rejected outright — the buttons for it disappear once it leaves **Pending**.

## Deleting

Administrator only, and rare in practice — a request is a decision record, not
working data.

## Roles and permissions

There's no per-record ExpenseClaim-style permission group for this resource —
approval rides on the single `EmployeeChangeApprove` permission, held by
Manager, CEO, and Administrator (not Accountant, not Employee). Anyone with it
sees the pending count as a sidebar badge and can decide any request; anyone
without it only ever sees, and can never act on, their own.
