## What this shows

For each bank account, the four lines a year-end file asks for:

| | |
|---|---|
| **Per bank** | the closing balance on the bank's own statement |
| **Unpresented** | cheques and payments you have written that the bank has not paid yet |
| **In transit** | deposits you have banked that the bank has not credited yet |
| **Per books** | what the ledger says the account holds |

Adjust the bank's figure by the two middle lines and it should equal the books.
**Difference** is whatever is left over, and a dash there means the account
reconciles.

The matching screen shows you one statement's lines. Nothing showed the position,
and the position is what an auditor asks for.

## Which statement each row uses

**The latest statement at or before the date you give.** A reconciliation is a
position at a statement date, not at an arbitrary date — asking for it as at
September when the last statement is 31 August gives you the August reconciliation,
not an empty page. The date shown is the statement's own, and `(open)` marks one
that has not been completed.

An account that has never had a statement imported is **not listed**. Nothing has
ever been reconciled there, which is a different fact from reconciling to nil.

## Why the interesting rows are the open ones

A statement cannot be completed unless its closing balance equals the ledger balance
exactly. An unpresented cheque makes those two figures differ — that is what
unpresented *means* — so **a statement carrying one cannot be completed at all**, and
a statement that has been completed has, necessarily, nothing left to reconcile.

So this report is mostly about statements still open, and the note tells you when it
finds that situation. If your bank reconciliations routinely have unpresented
cheques, the completion rule is the thing to look at, not this report.

## Where the figures come from

**Unpresented and in transit both come from what has not been matched.** Matching a
statement line to a ledger line stamps that ledger line as reconciled, so a posted
ledger line *without* that stamp is by definition something the bank has not seen.
Excluding a statement line clears the stamp again, which is correct: an excluded line
is one nobody claims ties to the ledger.

A bank account is debit-normal, so a **credit** to it is money leaving — a cheque —
and a **debit** is money arriving.

**Only posted journal entries count**, the same rule the Trial Balance and Balance
Sheet follow, so all three agree.

**Statement lines matched to nothing are counted in the note, not valued.** They are
the usual explanation for a difference that survives both adjustments — a charge or
interest the bank applied and nobody booked. They are counted rather than added in,
because an unmatched line's amount is the bank's figure, and adding it would be
asserting the journal entry it should have produced.

## Roles and permissions

Requires `ReportView`, gated behind the Accounting module being enabled for the
company. Read-only — nothing here matches a line, completes a statement or posts an
entry.
