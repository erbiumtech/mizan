![Employees](/images/help/employees.png)

## What an employee record is

An employee record holds the personal, employment, and banking details behind
one person: identity fields, their manager (for the reporting hierarchy), the
bank details used for the salary bank file, uploaded NIC images, and any
company-specific custom fields. It's linked to exactly one login (a **User**)
and, separately, to their compensation package (see Employee Settings).

## Employees are created from Users, not here

**The New button on this list does not create a standalone employee** — an
Employee record is created automatically as a side effect of adding a **User**
and assigning them the *Employee* role (Users → New). That flow generates the
Employee ID (`EMP-<user id>`) and marks them active; come back to this list
afterwards to fill in the rest — banking, NIC, manager, designation.

## Editing

Non-administrators may edit only contact and banking details.
**Employment fields — Employee ID, Status, Designation, Department — stay
locked** for everyone except an Administrator, who edits them directly.

**Bank details**: choose a bank from the directory (the banks the company
transfers *out* to) or, if the employee banks with the company itself, leave
Bank blank and fill in the short code instead — the company's own bank is
deliberately not in that directory. Either a Bank A/C No or an IBAN is
required, whichever the chosen bank's transfers actually use.

**Manager**: only Administrators, Managers, and CEOs may set this — it drives
the reporting hierarchy used elsewhere (downline visibility, approvals). The
picker excludes the employee's own subtree, so nobody can end up reporting to
one of their own reports.

## Self-service edits go through approval

When an employee (anyone without the Administrator, Manager, or CEO role)
edits their own name, email, personal details, NIC, bank, or address, the
change does **not** save directly — it creates a pending **Employee Change
Request** instead, and the record stays exactly as it was until someone
approves it. See Employee Change Requests for that workflow. Administrators,
Managers, and CEOs bypass this and edit directly, including on someone else's
record.

## Leaving the company

Deleting an employee is Administrator-only. Their direct reports are
automatically reparented to their own manager (or left with no manager) so the
reporting hierarchy stays connected — nobody is left pointing at a manager who
no longer exists.

## Related tabs

Opening an employee shows two read-only tabs: **Change Requests** (their
history of self-service edit requests) and **Projects** (current and past
project assignments). Both are managed from elsewhere — the request itself, or
the Projects resource — not edited from here.

## Roles and permissions

- **View** — an Administrator sees everyone; anyone else sees only their own
  record and their reporting downline (managers see their whole subtree).
- **Update** — same scope as View. Editing your own record always works, via
  the approval routing above.
- **Delete** — Administrator only.
- There is no dedicated Create permission: adding an employee happens through
  Users → New, as above.
- The Employees module must be enabled for the company, or this list and every
  employee-owned record (Employee Settings, Advances, Expense Claims,
  Projects' team tab) disappears with it.
