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

## The income certificate <!-- requires: EmployeeView -->

**Income certificate** on an employee's own page produces the letter a bank, an embassy or a
landlord asks for: that this person works here, what they are paid, and that the salary comes
through a bank with tax deducted at source.

**The employee can do this themselves.** Anyone who may already view the record — the person
themselves, their reporting line, an administrator — can issue it, because the whole reason it
exists is somebody applying for a visa or a loan on a Sunday.

It asks for three things that are not on the record: what the letter is **for**, the
**father's/guardian's name** (banks match it against the CNIC), and a line describing the
**duties**. The address is filled in from the record and can be corrected for one letter.

**The figure is the recurring package** — basic plus the standing allowances, in force on the
day it is issued. Bonuses and overtime are deliberately excluded: "gross monthly salary" is
read as *every* month, and a bonus printed there is a promise the company has not made and a
loan instalment somebody cannot pay. The letter says so in its own footnote.

**It refuses rather than guesses.** No CNIC, no joining date, no designation, no salary package,
or an unfilled letterhead means no letter — and it names each gap *and the screen that fixes it*.
A certificate reading "PKR 0", or with a blank where the NTN goes, looks official and is wrong.

**The salary comes from the package, which is not on the employee record.** It is a row of its
own under **Employees → Employee Settings**: pick the employee and the fiscal year, give it a
start and end date, then the basic wage and the standing allowances. That is what the certificate
adds up, and what a payslip pays from — one figure, one place, so the letter and the payslip
cannot disagree.

Nothing is stored. Each download is rendered from the record as it stands, so a copy already
given to a bank never disagrees with the salary on file. The account number is printed as its
last four digits only.

The letterhead itself — registered name, address, NTN, incorporation number, who signs — is set
once in **Company Settings → Letterhead**.

## The experience letter <!-- requires: EmployeeView -->

**Experience letter** produces the service certificate a next employer asks for: that this
person worked here, in which roles, for how long, and how their conduct was found.

**It lists every role held**, taken from the job history — somebody who joined as a developer
and left as a lead gets a letter that says both, which is the thing that makes it worth having.
An employee hired before that history existed falls back to the designation on the record.

**It reads in the present tense until there is a leaving date.** Somebody job-hunting asks for
this before they resign, so a letter that assumed they had already gone would be no use to them.
Once the record has a last working day, the letter is past tense, bounded by that day, and ends
with the good-wishes line.

**Length of service is whole months**, counted from the joining date. "2 years 11 months" is a
fact; rounding it up to three years on a document somebody verifies is the small lie that
discredits the whole letter.

**The salary is off by default.** What an employee earned here follows them into their next
negotiation, and volunteering it on a letter they hand over is not the company's to do. The
toggle is there because some employers demand a last-drawn figure, and refusing outright would
just mean a second trip to HR.

The conduct wording — satisfactory, very good, exemplary — is your own list, under
**Settings → Dropdown Options**.

Unlike the income certificate, this issues for somebody with **no salary package on file**: it
states service, not pay, so it has nothing to refuse over. It still needs a joining date, a
designation and the letterhead.

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
