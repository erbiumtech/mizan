This chapter follows one month of salary all the way through: the records that
have to exist first, opening the month, what the arithmetic does, what reaches
the ledger, signing the month off, and producing the file the bank pays from.

The short version of the shape, because it surprises people: **there is no
"approve this payslip" step.** A payslip is correct or it is corrected. What
gets signed off is the *month* — see "Signing the month off" — and what the
employee does is *acknowledge* their own payslip, which is what releases their
payment.

## Before a month can be run

Four things have to be in place. Payroll will otherwise either skip people
silently or refuse to post.

1. **An active fiscal year.** Everything is keyed to one, and the year supplies
   the tax slabs.
2. **Salary slabs for that fiscal year**, under **Payroll → Salary Slabs**. Each
   slab stores `min_amount` as an *exceeding* threshold with a `fixed_tax` and a
   `percentage`, so tax is `fixed_tax + percentage% of (income − min_amount)`.
   With no slab matching an income, the tax computed is zero — a year whose slabs
   were never seeded quietly taxes nobody.
3. **A salary setting per employee** covering the month, under **Employees →
   Employee Settings**. This is the agreed package: basic wage, medical, petrol
   and device allowances, bonus, extra work hours, and the standing deductions
   (meal, ESI health insurance, advances). An employee with no setting covering
   the month **is skipped**, deliberately — raising an empty payslip would put a
   zero in the payroll and a name in the bank file.
4. **The payroll account mapping**, under **Company Settings → Payroll →
   Payroll Account Codes**, if you keep books in this system. Run
   `php artisan payroll:accounts` to check it before you need it; a mapping that
   points at a missing account, or at an account that has been given a
   sub-account, fails at the moment somebody saves a payslip.

## Opening the month

1. Go to **Payroll → Payroll Months** and open the month, or run
   `php artisan payroll:open-month --month=August`. Add `--dry-run` to see what
   it would raise without writing anything.
2. Every active employee with a salary setting covering the month who does not
   already have a payslip gets one. Attendance fields (working days, paid days,
   days lost, leaves) start at zero — payroll cannot know them, so they are
   defaults a clerk adjusts.
3. Employees with **no setting covering the month are reported, not silently
   passed over**. The command warns and names them. Treat that list as an
   oversight to fix rather than a decision.
4. Re-running is safe. An employee who already has a payslip for the month is
   **skipped rather than recalculated**, so a rerun cannot disturb a payslip
   somebody has since corrected by hand or an employee has already accepted.
5. Opening a month that has been signed off is refused. Reopen the run first.

A payslip added by hand from **Payroll → Payslips** goes through exactly the
same calculation as one the command raises — there is only one implementation.

## What the calculation does

The figures recalculate on **every save**, from the salary setting active for
that month. Anything typed into the payslip form for bonus, extra work hours,
device allowance, petrol allowance, advances, meal deduction, ESI or expense
reimbursement **overrides** the setting for that month; left empty, the setting's
value is used.

- **Earnings** are basic wage + medical + petrol + device + bonus + extra work
  hours, plus any pay components (see below).
- **Withholding tax** is not a flat slice of this month. The year's taxable
  earnings are projected, a **10% medical exemption** is taken off the annual
  total, the slabs are applied to what is left, tax already withheld in earlier
  months of the year is subtracted, and the remainder is spread over the months
  still to come. Correcting an earlier month therefore changes later months'
  tax, which is intended.
- **Deductions** are withholding tax + advances + meal + ESI + component
  deductions.
- **Net salary** is earnings + expense reimbursement − deductions. A
  reimbursement is money of the employee's own being returned, so it is added to
  what they are paid but is **not** treated as taxable income.

## Pay components: allowances added later

Everything payroll shipped with lives in its own column. Anything you add
afterwards is a **pay component** (**Payroll → Pay Components**): a row with a
kind (earning or deduction), whether it is taxable, and where it posts. Give
each employee an amount for it on their salary setting.

A component must name an account, or a payroll account key — saving one without
either is refused, because a payslip that pays an allowance with no debit behind
it produces a journal entry that will not balance. A component that has been
paid on any payslip cannot be deleted, only switched off: it is part of what
those payslips say.

## What each save writes

Saving a payslip does considerably more than store the figures. In order:

1. Recalculates the figures above.
2. Rewrites the employee's **annual tax** record for the year.
3. Books the advances deduction against their outstanding advances.
4. Records what was paid **component by component**, so a package corrected in
   September does not change what August's payslip says it paid.
5. Settles any approved **expense claims** the reimbursement covers.
6. Posts the payroll **journal entry**.

All of it is idempotent per payslip, because payroll recalculates on every save
and a second row would recover an instalment or reimburse a claim twice.

Deleting a payslip unwinds all of it: the ledger entry is reversed, recoveries
are given back, settled advances go back to active if they are no longer clear,
and reimbursed claims return to approved and unpaid.

## What reaches the ledger

If the Accounting module is off, nothing does — payroll works without it, it
just cannot post. Otherwise each payslip produces one entry:

- **Debits** the expense accounts: basic wage, medical, petrol and device
  allowances, bonus and overtime together, expense reimbursement, plus a line per
  data-driven component.
- **Credits** tax payable, ESI payable, employee advances recovered, meal
  recovery, and **Salaries Payable** for the net.

Whether that entry posts itself depends on **Company Settings → auto-post
payroll**:

- **On** — the entry is approved and posted as it is created, with no approver
  named. Balances are always current, and nobody signs off.
- **Off** — the entry is created as **Pending Approval** and a Manager or CEO
  must approve and post it. That is real segregation of duties, and it is also
  how a month's payroll ends up accrued nowhere while its payments are posted.

That second case is worth understanding, because nothing looks wrong until money
moves: with the entry unposted, Salaries Payable was never credited, so the
payment debits a liability that does not exist and the books say the company
paid salary it never owed. Turning the setting on fixes payslips saved from then
on and does nothing for the backlog — `php artisan payroll:post-pending` is for
the backlog, and it posts payroll entries only.

Once posted, an entry cannot be edited or deleted, only reversed. Correcting a
payslip does that for you: the old entry is reversed and a replacement written.

## Sending payslips out

From **Payroll → Payslips**, send the payslip to the employee. `sent_at` records
that it went; sending again needs Resend, so a second click cannot quietly
re-notify everybody. The PDF is rendered from the payslip **as it stands**, never
from a stored file — an employee who downloads it after a correction gets the
corrected figures rather than a stale copy.

## What the employee does

The employee opens their payslip and either **accepts** or **rejects** it.

- Acceptance is what lets their payment be released. Until then the salary is
  **held back** from the bank file.
- Rejection is **advisory**: it records the objection and notifies everyone
  holding `PayslipUpdate`, but blocks nothing except the release.
- An administrator signed in as the employee may enter this for staff who
  cannot, and the payslip then records that it was accepted *on their behalf*,
  and by whom, rather than presenting it as the employee's own consent.

Accepting or rejecting does not recalculate the payslip or re-post its entry —
the figures and the ledger stay exactly as issued.

## Signing the month off

**Payroll → Payroll Months**, lock the month. This is the real control.

- A month with no payslips in it cannot be locked; there is nothing to agree.
- Once locked, no payslip in it can be added, changed or deleted. This is
  enforced on the record itself rather than by permissions, **because
  Administrators and super admins pass every permission check** — a sign-off the
  most privileged user walks through is not a sign-off.
- Reopening requires a **reason**, and records who reopened it and when. That is
  the question an auditor asks: a month that was agreed, then changed, with
  nothing saying why.

Locking needs `PayrollRunLock`, which Accountant, Manager, CEO and Administrator
hold.

## Paying it: the bank file

**Reports → Salary Bank File**. Pick the fiscal year, month and value date.

1. Every payslip of the month is listed, each carrying the state of the payment
   that pays it, and whether it may go in this batch.
2. A row is **releasable** only if the employee has accepted their payslip, the
   payment has not already been released, and the employee's bank details are
   usable. Each held row shows why it is held.
3. The one bank-detail problem that blocks a release outright: an employee who
   **banks with us but has only an IBAN on file**. An intra-bank transfer is
   keyed on the plain account number, so the payment would not go through. Add
   the account number to their record. (Someone at another bank is paid on their
   IBAN, which is correct and not a problem.)
4. **Download CSV** releases the selected payments as one batch and streams the
   file. The rows in the file and the payments marked released are always the
   same set, and a released payment does not appear in the next batch.
5. The file is a Standard Chartered iPayments bulk-payment CSV. Payments at or
   above the RTGS threshold are marked RTGS automatically.
6. If a batch was released in error, **void** it — that needs `PaymentUpdate`.
   Release state lives on the payment rather than the payslip, so this page and
   the Bank Payment File cannot disagree about what has already been sent.

Upload the CSV to the bank. That is the point at which people are actually paid.

## Year end

- **Payroll → Annual Taxes** holds a per-employee record for the year, rewritten
  on every payslip save: total annual income, taxable income, projected net,
  total annual tax, tax paid so far, and what is left to withhold.
- **Reports → Tax Summary** is the withholding summary on screen.
- **Reports → FBR Tax File** exports the withholding-tax file. Its month labels
  count only taxed payslips — the rows the file actually contains.

## Roles and permissions

| Role | What it can do in payroll |
|---|---|
| Employee | `PayslipView` — own payslips (the list scopes to own and downline), and accept or reject them |
| Accountant | Raise, edit and view payslips; view and lock payroll months; `ReportView` for the bank file and tax exports |
| Manager / CEO | Everything the Accountant can, plus approving and posting journal entries when auto-post is off |
| Administrator | All of the above, plus deleting a payslip |

`PayslipDelete` exists but **no seeded role holds it** — only Administrator, who
holds every permission. If deleting a payslip appears impossible for a Manager,
that is why.

Payroll needs the **Payroll** module. Posting to the ledger additionally needs
**Accounting**; advance recovery needs **Advances** and reimbursement needs
**Expenses**, and payroll runs without any of them, simply doing less.

## Quick answers

**An employee is missing from the month.**
They have no salary setting covering it, or they are not active. The open-month
command lists exactly who it skipped and why.

**Tax jumped this month.**
Something earlier in the year changed. Tax is a projection of the year less what
has already been withheld, spread over the months remaining, so a correction in
an earlier month lands on the ones still to come.

**A salary is held back from the bank file.**
The employee has not accepted their payslip, they rejected it, the payment has
already gone out in an earlier batch, or they bank with us and have only an IBAN
on file. The reason is shown against the row.

**The month is agreed but a figure is wrong.**
Reopen the run with a reason, correct the payslip — its ledger entry is reversed
and rewritten automatically — then lock it again.

**Balances do not reflect this month's payroll.**
Auto-post is probably off and the entries are sitting at Pending Approval. Have
a Manager or CEO approve and post them, or run
`php artisan payroll:post-pending` for a backlog.
