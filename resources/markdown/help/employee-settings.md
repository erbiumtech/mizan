![Employee Settings](/images/help/employee-settings.png)

## What this is

An Employee Setting row is a dated **compensation package**: basic wage, the
built-in allowances (medical, device, petrol), bonus, extra work hours, and
standing deductions (advances, meal, ESI/health insurance) — plus whichever
extra pay components have been added to it (see the "Added allowances and
deductions" tab). Payroll reads whichever row is active for the month it's
running, by date range.

## Creating and editing

Click **New**, pick the **Employee** and the **Fiscal Year**, and set a
**Start Date** — the figures below apply from that date until an **End Date**.
Leave End Date blank and it's filled in automatically: to the end of the
fiscal year the start date falls in (a July–June year, so a package starting
in, say, October defaults to the following June 30).

**Giving someone a raise is a new row, not an edit** — start a new package
dated from the raise, and the previous one is automatically end-dated the day
before (trimmed further if that would run past its own fiscal year). This is
why payroll can always find the exact figures a past month was actually paid
under, even after several raises.

## Self-service edits go through approval

An employee editing their **own** compensation figures doesn't change the row
directly — it raises a pending **Employee Change Request** instead (only the
earnings/deductions figures are requestable this way; the employee, fiscal
year, and date range are never editable by anyone but an approver). See
Employee Change Requests for that workflow. Administrators, Managers, and CEOs
edit directly.

## Added allowances and deductions

The tab on an open settings row lists extra pay components attached to this
specific package (defined once under Settings → Pay Components, then added
per-package with an amount). The four built-in allowances above are columns on
the package itself; this tab is for anything beyond those. It only appears
with something to show — if the Payroll module isn't enabled, there are no
components to attach and the tab is simply empty.

## Roles and permissions

- **View** (`EmployeeSettingView`) — an Administrator sees everyone; anyone
  else sees only their own row and their reporting downline. Employees hold
  View by default (to see their own package), but not Create/Update/Delete.
- **Create / Update / Delete** — Administrator, Manager, and CEO. An employee
  editing their own row is a special case of Update that's always allowed,
  routed through the approval flow above rather than the permission itself.
- Depends on the Employees module being enabled.
