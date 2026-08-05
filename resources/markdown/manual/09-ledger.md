## What this chapter covers

Everything in this application eventually becomes a journal entry. A payslip, an
invoice, a stock sale, a month of depreciation — each one ends up as a set of
balanced debits and credits against accounts in the Chart of Accounts. This
chapter is about that layer: what reaches it, how, and what you can do about it
afterwards.

If you only need the mechanics of the Journal Entries screen itself, the Help
button on that screen covers it in detail. This chapter is the bigger picture —
in particular, the fact that **most entries in a healthy set of books were never
typed in by anybody**.

## The two kinds of entry

There are exactly two ways an entry reaches the ledger.

**Entries somebody wrote.** Created on the Journal Entries screen, these go
through the full approval path: Draft → Pending Approval → Approved → Posted,
with Rejected as a side exit. Nothing affects account balances until the final
Post step, and posting is the point of no return.

**Entries the application wrote.** Recording a payment, issuing an invoice,
selling stock, running depreciation — each of these posts its own entry
immediately, already approved. There is no draft, no approval queue and no
reject button, because the approval that matters already happened at the source:
somebody approved the payment, somebody issued the invoice.

Both kinds sit side by side on the Account Register and in every report, and both
are equally real. The difference only matters when something is wrong, because it
determines where you go to fix it.

## Two rules that explain most surprises

**Only posted entries appear anywhere.** A Draft, a Pending Approval, an
Approved-but-not-yet-posted or a Rejected entry appears on the Journal Entries
list and nowhere else — not the Account Register, not the Trial Balance, not the
Profit & Loss, not the Balance Sheet. When a figure you expect is missing from a
report, the first thing to check is whether the entry behind it was ever posted.

**You cannot approve your own entry.** This is not a permission anyone can be
granted. It is checked on every entry for every role including Administrator: if
you are recorded as the creator, you cannot be the approver. When an Approve
button is unavailable on an entry you would expect to handle, this is usually
why.

## What posts automatically, and where to correct it

Every row below produces a balanced, already-posted entry without passing through
the approval queue. The right-hand column is the only reliable way to correct
one — reaching for the journal entry itself is the wrong end of the problem.

| What you do | Where | How to correct it |
|---|---|---|
| Record a payment | Payments | Void the payment batch, or revert its export |
| Transfer between own bank accounts | Account Register → Transfer | Reverse the entry |
| Add or edit a cash/bank transaction | Account Register | Edit or delete the row in place (see below) |
| Issue an invoice, or receive payment for one | Invoices | Void the invoice; voiding reverses its entry |
| Receive stock, record a sale, adjust stock | Products | Post a compensating adjustment |
| Post a payslip | Payslips / Payroll Runs | Unwind the payslip's entries and re-post |
| Run monthly depreciation | Fixed Assets → Run Depreciation | Reverse the entry |
| Dispose of a fixed asset | Fixed Assets → Dispose Asset | Reverse the entry |
| Revalue foreign balances | Currency Revaluation | Run it again — the calculation is cumulative |
| Close a fiscal year | Fiscal Years → Close | Reopen the year, which reverses the closing entry |
| Import from GnuCash | GnuCash Import | Reverse the imported entries |
| Import a CSV of transactions | CSV Import | Reverse the imported entries |
| Book or replenish a petty cash voucher | Petty Cash Book | Edit or delete the voucher |

## Correcting a posted entry

Posted entries are immutable. There is no unpost, no edit and no delete — the
correction is a **Reverse Entry**, which creates a *new* entry dated today with
every debit and credit flipped, approved and posted automatically. The original
stays exactly as it was; the reversal is what brings the balance back. Both
remain visible permanently, which is the entire point: the correction is on the
record rather than hidden in an edit.

So the fix for a wrong amount is two steps: reverse the entry, then create a new
one with the right figures.

## The one deliberate exception

The Account Register can edit and delete its own posted rows in place, without a
reversal. This is intentional: the register is a fast data-entry surface for cash
and bank accounts, and a mistyped description should not require a reversal pair
that doubles the row count of the ledger someone is trying to read. Every
restatement is written to the activity log with its before and after values.

The exception is deliberately narrow. A row cannot be edited from the register
when it is:

- a reversing entry — reversals are a permanent record;
- owned by another document — a payment, invoice, petty cash voucher, stock
  movement or fixed asset, each of which says so and points you where to go;
- a split entry of more than two lines;
- already reconciled against a bank statement.

Anything in that list has to be corrected at its source, or reversed.

Note that the register's edit, delete and transfer actions each require **two**
permissions: the matching Journal Entry one (`JournalEntryUpdate`,
`JournalEntryDelete`, `JournalEntryCreate`) *and* `RegisterPost`. Holding the
Journal Entry permission alone is not enough, which is the usual reason these
buttons are missing for somebody who can clearly edit entries elsewhere.

## Rules the ledger enforces on every entry

These are checked whatever created the entry, so they explain automatic failures
as well as manual ones:

1. **At least two lines.** A single-sided entry is refused.
2. **Each line is a debit or a credit, never both and never neither**, and never
   negative.
3. **Debits must equal credits**, to the paisa.
4. **The account must be able to accept entries** — active, allowing manual
   entry, and with no sub-accounts. Postings always go against the lowest level
   of the chart, so giving an account a child stops it accepting entries
   directly.
5. **The fiscal year must be open.** Posting into a closed year is refused,
   naming the year. This is checked at posting rather than creation, so a draft
   can be written and then re-dated.

The fiscal year is worked out from the entry date rather than being chosen by
whatever created the entry. An entry dated outside every defined year gets no
year at all, which is deliberate — better no year than the wrong one — but it
will be absent from any report that filters by year, so it is worth having years
defined before entering history.

## Foreign currency

Debits and credits are always stored in the company's base currency, because
every report reads those two columns and none of them know about currencies. A
line entered in a foreign currency is translated at that day's rate before
anything is validated — an amount in EUR and an amount in PKR would never balance
as written, but both in base always can.

Where a base amount is supplied alongside a foreign one — from a bank advice, say
— that figure is kept rather than recalculated. It is the settled fact, and
recomputing it would replace it with an estimate.

Because each posting is translated at the rate of its own day, a foreign
account's base balance drifts into a meaningless mixture of historical rates over
a month. Restating it is what the Currency Revaluation step does, covered in the
chapter on reconciling and closing the period.

## Entry numbers

Entries are numbered `JE-<year>-<six digits>`, taken from the entry date rather
than today's date, and allocated in sequence. A reversal gets its own number and
carries the original's number as its reference, which is how the two are tied
together on screen.

## Who can do what

| Action | Permission |
|---|---|
| See entries and the register | `JournalEntryView` |
| Create and edit a draft | `JournalEntryCreate`, `JournalEntryUpdate` |
| Approve or reject | `JournalEntryApprove` |
| Post an approved entry | `JournalEntryPost` |
| Reverse a posted entry | `JournalEntryReverse` |
| Delete a draft or rejected entry | `JournalEntryDelete` |
| Edit, delete or transfer on the register | `RegisterPost`, plus the matching Journal Entry permission |

Accountants create and submit; Managers and CEOs also approve, post and reverse;
only Administrators delete. Everything here belongs to the **Accounting** module,
so a company without it sees none of these screens.
