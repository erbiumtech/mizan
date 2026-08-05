## Users and employees are two halves of one person

Almost every confusion about access in this app comes from missing this
distinction:

- A **User** is a login. It lives centrally and can belong to more than one
  company.
- An **Employee** is an employment record — salary, bank details, NIC, manager,
  designation. It lives in one company's database and belongs to exactly one
  user.

Payroll, advances, expense claims and the employee's own payslips all hang off
the *Employee* record. The login on its own gets somebody into the panel; the
employee record is what makes them a person who can be paid.

## 1. Add somebody

Go to **Access Control → Users → New**. Not to Employees — **the New button on
the Employees list does not create an employee.** Adding a user and giving them
the *Employee* role creates the matching employee record for you, with an
Employee ID of `EMP-<user id>`, marked active.

One thing to know if you are a super admin creating an account for a *different*
company from this screen: the employee record belongs in that company's database,
so it is deliberately not created here. Create their login from the company they
actually work in.

Then go to **Employees**, open the new row, and fill in the rest — bank and
account identifier (payroll cannot pay them without it), NIC, designation, and
**manager**, which is what builds the hierarchy the next section relies on.

## 2. Give them a role

**Access Control → Roles** shows the five roles every company gets, and they are
**per-company**: a role belongs to exactly one company even though every company
has one called "Accountant". Editing Accountant here changes it for this company
only. Switch companies to manage another company's roles; the Platform Roles page
is where a super admin sees every company's side by side.

What each role can actually do:

| Role | In one line |
|---|---|
| **Employee** | Their own payslips and salary settings (read-only), their own expense claims, comments, and projects. |
| **Accountant** | Records everything — accounts, payments, invoices, payslips, journal entries, stock, bank statements — but cannot approve or post journal entries. |
| **Manager** | Everything the Accountant has, plus the approvals: journal entries, employee change requests, petty cash replenishment, stock adjustments, voiding invoices, asset depreciation and disposal. |
| **CEO** | Everything the Manager has, plus deleting reference records — accounts, assets, banks, contacts, products. |
| **Administrator** | Every permission in the system, including deleting journal entries and impersonating another user. |

Two deliberate gaps in that table are worth pointing out, because they look like
bugs when you meet them:

- **The Accountant cannot approve or post journal entries.** That is segregation
  of duties, not an oversight: the person recording an entry is not the person
  who lets it into the books. See *Recording a journal entry*.
- **The CEO cannot delete a journal entry.** Deleting a ledger transaction is
  Administrator-only. A CEO corrects the books by reversing, which leaves both
  rows on the ledger where an auditor can see them.

## 3. What an employee can see

Visibility follows the **manager hierarchy**, not the role alone. For anyone
without a privileged role — and *Employee* is the only seeded role that is not
privileged — the rule is: **your own record, plus everyone below you in the
reporting line**, however deep.

That scoping applies consistently to employees, payslips, employee settings,
annual taxes and MPRs. A manager with three reports who each have reports of
their own sees all of them; a leaf employee sees only themselves. Administrator,
Accountant, Manager and CEO see everything.

So if a manager reports "I can't see my team's payslips", the answer is almost
always the **manager** field on their reports' employee records, not their
permissions.

## 4. When an employee edits their own details

This is the part people are most often surprised by.

An employee **may** open their own employee record and their own salary settings
and change them. But for a plain Employee, saving **does not write the change**.
It creates a pending **Employee Change Request**, and the record stays exactly as
it was.

1. The employee edits their own record and saves.
2. A change request is created, holding the proposed values. Everyone who holds
   `EmployeeChangeApprove` is notified.
3. An approver opens **Employee → Employee Change Requests** and approves or
   rejects it.
4. **On approval the values are written onto the record.** On rejection nothing
   changes, and a reason can be recorded.

Administrators, Managers and CEOs bypass this entirely — their edits save
immediately, on their own record and on anyone else's.

Some fields cannot be requested this way at all: an employee cannot change the
period a salary setting covers, or who owns it. Those are not editable-by-request;
they are simply not theirs.

## 5. Administrator, and super admin

Three levels of privilege, and the difference matters:

- **Administrator** is a role inside one company. It holds every permission, for
  that company.
- **Super admin** is a flag on the user account itself, not a role. It applies
  across every company, and it is what gets somebody into the **Platform** panel
  to create companies, grant licences and see the installation-wide audit trail.
  A super admin can switch into any company without being a member of it.
- **Neither of them can reach a module the company has not licensed.** An
  unlicensed module is refused before any permission is considered, and that
  refusal applies to super admins too. It is not a permission question, so no
  amount of privilege answers it — the fix is to license the module, as described
  in *Setting up a company*.

That last point is the one to remember when a screen is missing for someone who
"has every permission". Check the module first, the role second.

## 6. Watch what happened

**Audit & Taxes → Activity Logs** records changes inside this company; the
Platform panel has the installation-wide equivalent. Module changes are logged
too — who switched Payroll off, and when, is usually the first question after
something disappears.

## Next

- Run a month of payroll — *Running payroll*.
- Let people claim expenses and take advances — *Advances and expense claims*.
