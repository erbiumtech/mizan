## What this shows

Expense claims for the financial year to date, one row per person, split by the
state each claim is in: awaiting a decision, approved and unpaid, reimbursed, or
refused.

This is the report *about* claims. How to submit one, decide it and get reimbursed
is on the Expense Claims screen itself, which has its own help.

## The figure the rest of the application does not have

**Owed to staff** is the total approved and not yet paid. That is an accrued
liability — money the company owes its employees — and **nothing posts it.** A
claim reaches the ledger only when a payslip reimburses it, and by then it is an
expense rather than something owed. Between approval and payday the amount exists
and appears nowhere else.

That figure is a balance rather than a period: a claim approved last December is
owed just as much as one approved yesterday, so it counts whenever it was claimed.

## Why the reimbursed total is not reconciled

The other reports in this group check themselves against a ledger account. This one
does not, and the reason is worth knowing rather than guessing at.

Reimbursements post through the payslip to whichever account
`expense_reimbursement` is mapped to — and the shipped mapping points that at the
**same account code as meal recovery**. One account holding two unrelated flows
cannot be attributed to either of them, so a comparison against it would prove
nothing while implying that it had.

If your company has remapped those two to separate accounts that would become
reconcilable; the report does not currently attempt it.

## Using it

The date sets the period, and it is the **financial year to date** — 1 July to your
date, not 1 January. A claims figure from January is six months of spending
reported as a year.

**Refused claims are shown**, not dropped. A person whose claims are routinely
refused is a conversation worth having, and a report that hid them would describe a
tidier company than the one that exists.

**A dash is not a nought.** An employee with nothing pending shows a dash in that
column, so it is possible to see at a glance who is actually waiting.

**Rows are ordered by what has been reimbursed**, most first.

## Where the figures come from

**Claims are dated by when they were claimed**, not decided or paid. That keeps a
claim in one period however long it takes to approve — otherwise a slow approval
would move spending from one year into the next.

The four states are the claim's own: pending, approved, settled and refused.
*Approved* means a decision has been made and the money has not moved; *settled*
means a payslip has reimbursed it.

## Roles and permissions

Requires `ReportView`, gated behind the Expenses module being enabled for the
company. Read-only — nothing here approves, refuses or reimburses a claim.

The report covers everybody's claims. If somebody should not see what colleagues
have spent, they should not have `ReportView`.
