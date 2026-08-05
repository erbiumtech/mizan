![Account Register](/images/help/account-register.png)

## What the register shows

One account at a time — a bank or cash account — with every transaction that
has ever touched it, from any source: a manual entry here, a payment, a
transfer, a payslip, an invoice, anything. It's the single place to see
everything that happened to that account's balance.

Only postable cash/bank accounts appear in the **Account** selector — active,
no sub-accounts of their own, coded 11xx. If none exist, there is nothing to
show a register for.

## Reading it

**From** and **To** narrow the date range. **To** defaults to today, which is
why the register agrees with the Profit & Loss and the Trial Balance — both
stop there too. A transaction dated ahead of today (a payment scheduled for
next week, say) is therefore invisible by default; a banner says so, and
**"Show later entries"** clears the end date to bring it back into view.

## Recording money directly on the register

- **Add Transaction** — books a balanced two-line journal entry immediately:
  enter the amount in **Debit** for money coming in, **Credit** for money
  going out (never both), pick the account on the other side of the entry,
  and it posts on the spot. There is no draft or approval step for this path.
- **Transfer** — moves money between two of the company's *own* accounts.
  Money leaving the company is a payment, not a transfer — use this only for
  internal moves.

## Editing a row

**Edit transaction** restates a posted entry in place rather than reversing
it — this is the one place in the app where a posted entry is edited directly.
It only reaches rows the register itself booked; a transaction that belongs to
another document (a payment, an invoice) is not editable here.

**Delete transaction** removes the entry and unwinds both account balances.
A full copy stays in the audit log, but the entry itself is gone from the
ledger — to keep it visible with a correction alongside it, use **Reverse**
instead.

**Reverse transaction** books a mirrored entry dated today (debits and
credits flipped) and leaves both the original and the reversal on the ledger.
It's the only option for a row the register can't edit directly — reconciled,
split across more lines, or owned by another document.

## Roles and permissions

Two permissions gate editing here, and both are required together:

| Action | Permissions needed |
|---|---|
| Add Transaction / Transfer | `JournalEntryCreate` **and** `RegisterPost` |
| Edit transaction | `JournalEntryUpdate` **and** `RegisterPost` |
| Delete transaction | `JournalEntryDelete` **and** `RegisterPost` |
| Reverse transaction | `JournalEntryReverse` |

Accountant, Manager and CEO all hold `RegisterPost`, so in practice the
difference between them here is the same as on Journal Entries: Accountant can
record and edit but not reverse; Manager and CEO can also reverse.
**`JournalEntryDelete` belongs to Administrator only** — nobody else can
delete a register row, even though they can add, edit or reverse one.

Seeing the register at all requires `JournalEntryView`, gated behind the
Accounting module being enabled for the company.
