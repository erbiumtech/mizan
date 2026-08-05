Both of these are money moving between the company and an employee outside
normal salary, and both **settle through payroll** rather than having a
screen of their own where you mark them paid. An advance is recovered from
payslips; an approved claim is reimbursed on one. Understanding that handoff is
most of understanding these two modules — the walkthrough in "Running payroll:
from employee to money in the bank" is the other half.

## Advances: lending money

1. Record it under **Advances → Advances**: the employee, the **total amount**,
   the **monthly instalment**, the date it started, and a reference. It begins
   **active**.
2. From then on, payroll deducts it. When a payslip is calculated, the advances
   deduction comes from the advance ledger rather than from the employee's salary
   settings, so **the figure deducted and the balance still owed are the same
   fact** rather than two records that can drift.
3. Each active advance takes **its own instalment, oldest first**. If somebody has
   two advances, the oldest does not swallow the whole deduction and pay itself
   off years early while the other sits untouched.
4. The last instalment is floored at whatever is actually left, so an advance
   never over-recovers and leaves the company owing money back.
5. Once nothing is outstanding the advance becomes **settled** on its own, and
   stops being deducted.

The amount recovered and the balance remaining are **derived from the recovery
history**, never stored. A stored balance drifts the first time a payslip is
corrected or deleted, and the balance is the number somebody is owed.

### Overriding an instalment

A payroll clerk may type a different advances figure on a payslip for one month —
that is a legitimate correction and it wins over the ledger. Anything beyond the
instalments goes against the remaining balances in the same oldest-first order.

### Repayment outside payroll

Cash handed back, or a correction, is recorded as a manual recovery against the
advance. It must be positive and cannot exceed what is still outstanding.
Manual recoveries always count, whichever payslip is being recalculated.

### When a payslip changes or goes away

Recovery is idempotent per payslip: re-saving a payslip **updates** its recovery
rather than adding a second one. Reduce a payslip's advances deduction to nothing
and the recovery is given back. Delete the payslip and the recovery goes with it —
the money was never taken, so the balance goes back up, and an advance that had
been settled returns to **active** if it is no longer clear.

A recovery is dated the **last day of the payslip's month**, not the day somebody
pressed save. July's payroll is often processed in August, and dating it by the
keystroke would put July's instalment in August.

## Expense claims: paying money back

1. The employee submits a claim under **Expense Claims**: what it was for, the
   amount, and the date. It starts **pending**.
2. Somebody else approves or refuses it. **You cannot decide your own claim** —
   that is checked on the record itself, not merely left to permissions. A
   refusal **requires a reason**; being told no without being told why is the
   complaint the approval step exists to answer. The submitter is notified either
   way.
3. An approved claim is **owed but unpaid**. Nothing else happens until a payslip
   picks it up.
4. When a payslip is calculated, the expense reimbursement figure defaults to the
   **sum of that employee's approved claims**, so the amount on the payslip is a
   total of things somebody approved rather than a number typed with nothing
   behind it. A figure entered by hand still wins — paying a reimbursement
   outside the claim process is a legitimate correction.
5. Saving the payslip marks the claims it covers **settled** and links them to
   it. Reimbursement is added to net pay and is **not taxed**: it is the
   employee's own money coming back, not income.

Claims are matched whole. A payslip covers a claim or it does not — partial
reimbursement of a single claim is not modelled. Claims are taken oldest first,
with any the payslip already carried kept in preference to newer ones.

### When a payslip changes or goes away

The same shape as advances. Edit a reimbursement down and the claims it can no
longer cover are **released** back to approved and unpaid; edit it to nothing and
all of them are. Delete the payslip and every claim it was reimbursing is owed
again.

## What reaches the ledger

Neither module posts anything by itself. Both appear in the payslip's journal
entry, which is described in the payroll chapter:

- **Advances recovered** are credited to the employee advances account, reducing
  the asset the loan represents.
- **Expense reimbursement** is debited as an expense and is included in the net
  salary credited to Salaries Payable.

So an advance instalment and a reimbursement both reach the books at the moment
the payslip does, under whatever posting rule the company has chosen.

## Roles and permissions

| Role | Advances | Expense claims |
|---|---|---|
| Employee | — | `ExpenseClaimView`, `ExpenseClaimCreate`, `ExpenseClaimUpdate` — own claims only; approving is deliberately absent |
| Accountant | `AdvanceView`, `AdvanceCreate`, `AdvanceUpdate` | View, create, update, and `ExpenseClaimApprove` |
| Manager / CEO | As Accountant | As Accountant |
| Administrator | Everything, including `AdvanceDelete` | Everything |

`AdvanceDelete` is held by no seeded role — only Administrator, who holds every
permission.

Advances needs the **Advances** module and expense claims the **Expenses**
module. Payroll checks each before touching it, so with either switched off
payroll still runs and simply stops deducting or reimbursing. Switching one back
on does not retrospectively adjust payslips already saved.

## Quick answers

**The advances deduction on a payslip is not what I expected.**
It is the sum of each active advance's instalment, capped at what remains. Check
the employee's advances for a second one, or for one that has settled.

**An approved claim was not reimbursed.**
The payslip's reimbursement figure did not cover it — claims are matched whole
and oldest first — or the figure was typed by hand and is lower than the claims
outstanding.

**A settled advance is active again.**
A payslip that had cleared it was corrected downwards or deleted, so it is no
longer clear. That is the intended behaviour, not a fault.

**Can somebody approve their own expense claim?**
No. It is refused on the record for every role, including Administrator.
