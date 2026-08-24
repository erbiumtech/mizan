## What this shows

Every advance still being recovered: what was lent, what has come back, what is
left, the monthly instalment and how many months that has to run.

It is a **receivable from staff** — money the company is owed — and it feeds final
settlement, so a wrong figure here leaves the company out of pocket when somebody
leaves.

## One row per advance, not per person

The instalment and the months remaining belong to an *advance*, not to an
employee. Somebody with two advances on different instalments has two different
answers to "when is this cleared", and averaging them would invent a third. The
employee is named on every row, and the totals are for the company.

## The two totals, and why they usually differ

**Outstanding** is the register: advanced less recovered. **Advances account** is
what the ledger holds.

These will often *not* agree, and the commonest reason is nobody's mistake:
**nothing posts an advance when it is entered.** Recording an advance here says
money was lent; the ledger only learns of it if the payment out was also booked
against the advances account. Meanwhile a payslip's recovery *credits* that
account. So a company that pays advances straight from the bank without booking
them has every advance on this report and only the recoveries in the account —
which shows as the account holding **less** than the register, by roughly what has
been lent.

The report says which way round the difference falls, because the two directions
mean opposite things:

- **The account holds less.** Advances lent without a payment booked against the
  account — the case above.
- **The account holds more.** Either a payment was booked to it that is not an
  advance, or an advance was settled without its recovery being recorded.

If no advances account is mapped at all, the report says so rather than comparing
against a nought.

## Using it

The date is "as at". An advance that starts after it is not yet lent and does not
appear.

**Only active advances.** Settled and cancelled ones are gone from the report, and
an advance settles automatically once nothing is left.

**Months left** is the outstanding amount divided by the instalment, rounded up —
a part month is still a month somebody is repaying in.

**An advance with no instalment shows a dash** in both that column and Months
left. Nothing will ever deduct it, which is a different problem from one that
deducts nought, and the note counts how many are in that state.

**Recovered includes manual recoveries**, not just payroll ones. A recovery
recorded by hand carries no payslip and counts the same.

## Roles and permissions

Requires `ReportView`, gated behind the Advances module being enabled for the
company. Read-only — nothing here records a recovery, settles an advance or posts
an entry.
