## What this shows

What the company would have to pay if everybody took their unused encashable leave
as cash on the date you give it: days each person has left, the daily rate, and
what that comes to.

## This figure is in no account

That is the reason the report exists. Nothing in this application posts a leave
provision — there is no leave-liability account in the payroll mapping and no
journal entry creates one — so the amount on this screen appears nowhere in the
balance sheet.

The report says so under the total, every time. A provision nobody has posted is
exactly as real as one that has been; the only difference is that the accounts do
not know about it. What to do about that is a decision for whoever owns the
accounts, and this report is the number they need in order to make it.

Every other report in this group checks itself against a ledger balance. This one
cannot, because the balance it would check against does not exist.

## Only encashable leave counts

A leave type is either **encashable** — unused days are paid out — or it lapses at
the end of the leave year. Only the encashable types are a liability, because only
those become money.

Types are marked encashable individually, under Leave Types. If none of them are,
this report says that plainly rather than showing a nought: "no leave type is
encashable, so unused leave lapses" is a different fact from "nobody has any left".

## Using it

The date is "as at". Balances are computed in the leave year covering it, which
differs per employee where your leave years run from each person's joining
anniversary.

**Only people still in service** are on the report. Somebody who has left is not a
provision: either they have been settled, in which case the money is a payable, or
they have not, in which case it is a debt. Both are different reports.

**Only positive balances.** Somebody who has over-drawn their leave is not shown as
a negative and does not reduce the total. Recovering over-taken paid leave is a
decision somebody makes, not an offset a report should apply on their behalf.

**People with nothing owed are left off** rather than shown as rows of nought. The
headcount with a balance is on the note, so nothing is hidden.

## Where the figures come from

**The same calculation a final settlement uses** — the same encashable types, the
same positive-balance rule, and the same daily rate: basic wage divided by the
encashment divisor, which is `26` unless your company has changed it under
statutory settings.

That is deliberate and it is the most important thing about this report. If the
liability were computed here in its own way, it would drift from what actually
gets paid, and the first person to leave would be settled for an amount the accrual
never provided for.

**Basic wage only**, not gross. Allowances do not enter the encashment rate, which
follows the settlement rather than making a choice of its own.

**Somebody with no salary package recorded shows days and two dashes.** There is no
wage to compute a rate from, so the amount is unknown rather than nought — printing
a nought would say those days are worth nothing, when the truth is that nobody has
recorded what they are worth. The note says how many people that applies to and
that the total is therefore incomplete, so the figure is never read as final when
it is not.

## Roles and permissions

Requires `ReportView`, gated behind the Lifecycle module being enabled. If the
Leave module is not enabled there is no encashable leave and the report says so
rather than failing.

Read-only — nothing here posts a provision, adjusts a balance or settles anybody.
The report covers everybody's leave and pay; if somebody should not see that, they
should not have `ReportView`.
