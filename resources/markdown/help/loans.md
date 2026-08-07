Money borrowed and repaid in instalments, with the repayment schedule worked out
and each instalment bookable in one click.

## What this gets right that a spreadsheet does not

The instalment is the same every month. **The split inside it is not.** Interest
is charged on what is still owed, so it shrinks month by month while the
principal portion grows.

On a twenty-year loan the first payment is more than 90% interest and the last is
less than 10%. Booking a flat split — the usual shortcut — puts the wrong figure
in interest expense every single month, and leaves the loan account nowhere near
zero when the last payment is made.

## Setting one up <!-- requires: LoanCreate -->

1. **Accounting → Loans → New loan.**
2. Name it, and note the lender if you want it recorded.
3. Enter the **amount borrowed**, the **annual interest rate** and the **term in
   months**. The rate is nominal per year and is divided by twelve. Zero is fine
   — an interest-free arrangement is a real thing.
4. Set the date of the **first instalment**. A day too late for a short month
   uses that month's last day, so the 31st still falls due in February.
5. Choose the three accounts it posts to (below).
6. Save. The schedule appears on the **Schedule** tab immediately.

As you type, **What this works out at** shows the monthly figure, the total
repaid and the total interest. That is the number you are actually deciding on,
so it is shown before you commit rather than after.

## The three accounts

A repayment is a three-sided entry, and each side needs somewhere to go:

| Account | What it is | On the entry |
|---|---|---|
| **Loan account** | A liability holding what is still owed | Debited by the principal portion |
| **Interest account** | An expense | Debited by the interest portion |
| **Paid from** | The cash or bank the money leaves | Credited with the whole instalment |

Only accounts that can receive a posting are offered — a group heading cannot.

## Recording an instalment <!-- requires: LoanRecord -->

On the **Schedule** tab, press **Record** on the row. Confirm the date — the
instalment date unless the money actually left on another day — and it raises a
**draft** journal entry with the three lines above.

A draft, not a posted entry. It still goes through submission and approval like
anything else that reaches the books, for the same reason: an entry reaching the
ledger is a decision somebody makes after reading it.

Once recorded, the row shows a tick and links to its entry.

**An instalment can only be recorded once.** If the draft was wrong, correct or
delete the journal entry itself — the loan row stays linked to whatever entry it
raised.

## Reading the list

**Still owed** is what the *agreement* says is left after the instalments you
have recorded. It is deliberately not a restatement of the loan account balance —
it is the figure to reconcile that account **against**. If the two disagree,
something was posted to the loan account outside this screen.

**Next due** is the first instalment with no entry against it, or *finished*.

## Changing the terms <!-- requires: LoanUpdate -->

Editing a loan rebuilds its schedule from scratch — and that is only allowed
**while nothing has been recorded**. Once the first instalment is in the ledger
the form closes, because half the table is already posted: regenerating would
leave entries matching no row, and a liability that no longer reaches zero.

To restructure a loan that is part way through, mark it inactive and set up a new
one for the remaining balance.

## What this does not model

Said plainly, because a schedule that looks authoritative and is approximate is
worse than no schedule:

- **A variable rate.** The rate is fixed for the life of the schedule. A
  KIBOR-linked loan that reprices needs a new loan record at each reset.
- **Early settlement or an extra payment.** There is no recalculation. Record the
  extra payment as an ordinary journal entry against the loan account, and treat
  the schedule as the original agreement rather than a live balance.
- **Fees, insurance or penalties.** Only principal and interest. Anything else is
  its own entry.
- **A payment holiday.** No way to skip a month and re-amortise.

The last instalment always pays off exactly what is left rather than the level
amount, so the loan closes to zero to the paisa.

## Roles at a glance

| Role | What they can do here |
|---|---|
| Administrator | Everything, including deleting a loan |
| Accountant | Set loans up, edit them, read the schedule |
| Manager | The same, plus recording instalments |
| CEO | The same, plus deleting a loan |
| Employee | No access |

Recording an instalment is the step that writes to the ledger, so it sits with
the approval powers rather than with setting the loan up.

## Troubleshooting

**"At this rate the interest is more than the instalment."** The rate and term
together describe a loan whose balance would never fall. Check whether the rate
was entered per month instead of per year.

**Deleting is greyed out.** Something has been recorded. Mark the loan inactive
instead — deleting it would orphan journal entries already in the books.

**Still owed does not match the loan account.** Something reached that account
from outside this screen: an opening balance, a manual entry, or a fee. Find it
with Reports → Find Transactions, filtered to the loan account.

**An instalment is missing from the schedule.** The schedule has exactly the term
you entered. If a month looks skipped, check the due dates — a short month shifts
the day, it does not drop the instalment.
