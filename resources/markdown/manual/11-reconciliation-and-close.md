## What this chapter covers

The end-of-period work: agreeing the bank, restating foreign balances, signing
off payroll, and finally closing the year. These steps have to happen in roughly
this order, and the reason is always the same — **closing a period freezes it, and
nothing can post into a frozen period afterwards.** Anything you leave until
after the freeze needs the period reopened to fix.

## Reconciling a bank account

Reconciliation compares what the bank says happened against what the ledger says
happened, and accounts for every difference. A statement moves through three
states: **Draft**, **In Progress** and **Completed**.

### 1. Create the statement

From **Bank Statements**, click **New**. You give it the account it belongs to,
the statement date, and the **closing balance** printed on the bank's statement.
That closing balance is the number the whole exercise has to arrive at.

### 2. Import the lines

Import the bank's rows into the statement — each needs at least a transaction
date and a signed amount, with description and reference if available. Every
imported line starts **Unmatched**, and the statement moves to In Progress.

A completed statement cannot be imported into. If rows are missing from a
statement you have already completed, that statement was completed too early.

### 3. Match

**Auto-match** does the obvious pairings for you, against posted ledger lines on
the same account that are not already reconciled. It works in two passes:

1. exact amount, with the entry dated within three days either side of the bank's
   date;
2. exact amount, where the bank's reference contains the journal entry number.

It is deliberately conservative. Matching is strictly one-to-one, and **ties are
left alone** — if two candidate entries have the same amount within the date
window, neither is matched and the line is left for a person to decide. A quiet
wrong guess would be far worse than an unmatched line.

Whatever is left you handle by hand:

- **Match** a line to a specific ledger line. It must be on the same account and
  not already reconciled.
- **Exclude** a line that has no ledger entry and should not have one — a bank
  fee you have decided not to book, say.
- **Unmatch** to undo either, which frees both sides again.

### 4. Complete

**Complete** locks the statement, and it will refuse unless both conditions hold:

- every line is matched or excluded; and
- the statement's closing balance equals the ledger balance for that account as of
  the statement date, to the paisa.

If it refuses on the second, it tells you both figures. The gap is the thing to
investigate — usually a transaction the bank has and the books do not, or a
duplicate.

Completing is a lock, not a mere status: no further importing, matching or
unmatching. And note the knock-on effect in the ledger — once a ledger line is
reconciled, the Account Register will no longer edit the entry in place, and
offers reversal instead.

| Action | Permission |
|---|---|
| See statements | `BankStatementView` |
| Create or edit a statement | `BankStatementCreate`, `BankStatementUpdate` |
| Import lines | `BankStatementImport` |
| Match, unmatch or exclude | `BankStatementMatch` |
| Complete the reconciliation | `BankStatementComplete` |
| Delete a statement | `BankStatementDelete` |

## Restating foreign balances

Skip this if the company trades only in its base currency.

A foreign-currency account holds real foreign money, and that amount does not
change when the exchange rate moves — but the base-currency figure reported on the
Balance Sheet does, because it is a translation. Every posting was translated at
the rate of its own day, so after a month the account's base balance is a mixture
of historical rates and does not mean much.

**Currency Revaluation** replaces that mixture with the balance translated at a
single rate on a single date. Open the page, review the preview of each foreign
account, and post the adjustment. The difference goes to an unrealised gain/loss
account, kept separate from realised gains because no money has moved and none
will until the balance is settled.

Two useful properties follow from the adjustment being calculated
**cumulatively** — as the gap between the current base balance and what the
foreign balance is worth on the date, including every previous adjustment:

- running it twice on the same date posts nothing the second time, because the gap
  is then zero. There is nothing to undo and no bookkeeping about what was already
  done;
- a foreign transaction backdated into a month you have already revalued is picked
  up by the next revaluation rather than being lost.

This page is gated on `JournalEntryCreate` rather than a reporting permission,
because it posts a real entry.

## Signing off the payroll month

Payroll months are agreed separately from the ledger. On **Payroll Runs**, a month
is **Open** or **Locked**, and **Lock** signs it off: nothing in it can change
afterwards without reopening.

Two things to know:

- a month with no payslips in it cannot be locked — there would be nothing to
  agree;
- reopening a locked month **requires a reason**, which is recorded. This is
  precisely the thing an auditor asks about: a month that was agreed, then
  changed, with nothing saying why.

Locking payroll is not the same as closing the fiscal year, and it does not
prevent ledger postings. Do it before the year close so that the payslips behind
your salary figures cannot move underneath a signed-off year. Locking requires
`PayrollRunLock`.

## Closing the fiscal year

This is the last step, and the most consequential: **once a year is closed,
nothing may post into it.**

Before it will close, the year has to stand up. **Fiscal Years → Close** reports
every reason at once rather than one at a time, so you can work through the list
in a single pass. The checks are:

1. **Opening Balance Equity must be clear.** Every opening balance credits that
   account, so a leftover balance means some accounts' opening figures were never
   entered. This check exists because a single opening entry is perfectly valid
   double-entry — the trial balance ties happily while the book is only half
   brought onto the system, so nothing else would catch it.
2. **The trial balance must balance** as of the year end.
3. **No unposted entries dated in the period.** Post them or reject them.
   Rejected entries do not count — they are deliberately dead, not outstanding
   work.

When it does close, one **closing entry** is posted, dated the last day of the
year: every income and expense account is zeroed, and the net profit or loss is
rolled into Retained Earnings. Income and expenses measure a single period, so
they start the next year at nothing while Retained Earnings carries forward. The
closing entry is posted *before* the freeze goes on, necessarily — it is dated
inside the period it is closing.

If there was no activity at all in the year, no closing entry is posted, and that
is correct rather than a failure.

### Reopening a closed year

**Reopen** unfreezes the period and reverses the closing entry, in that order. The
reversal has to be allowed to post into the period, hence the sequence.

Undoing the roll-forward matters: leaving it in place would keep income and
expenses at zero, so the reopened year would report no activity whatsoever. And
because reversal leaves the original posted — that is the point of reversal — a
close, reopen, close cycle correctly re-rolls the profit rather than finding the
dead entry and skipping the step.

Closing and reopening are gated on the permission to update a fiscal year, and
both are written to the activity log with who did it.

## Suggested order

1. Book everything for the period — payments, invoices, stock, petty cash.
2. Run depreciation for each month (see the chapter on fixed assets and stock).
3. Reconcile every bank account and complete each statement.
4. Revalue foreign balances, if the company holds any.
5. Lock the payroll month.
6. Check the Trial Balance ties and Opening Balance Equity is clear.
7. Close the fiscal year.

Steps 2 to 5 all post entries dated inside the period, which is exactly why step 7
comes last.

## Bringing in history from GnuCash

Not part of the periodic cycle, but it belongs to the same family of one-way
operations. **GnuCash Import** takes an exported CSV and posts the transactions it
contains as journal entries, already approved and posted.

Treat it as irreversible: there is no undo, and correcting a bad import means
reversing the entries it created. Import into a test company first if you are
unsure of the file, and note that entries dated in a closed year will be refused.
The page requires the `GnuCashImport` permission.
