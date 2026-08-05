![Bank Statements](/images/help/bank-statements.png)

## What this is

Bank reconciliation: bringing the bank's own record of a company bank
account's activity onto the system, and matching each line against what the
ledger already shows, so the two are proven to agree.

## Creating a statement

Click **New** and set the **Bank Account** it's for (only active, postable
asset accounts are offered), the **Statement Date**, and the bank's own
**Opening Balance** and **Closing Balance** for the period. It starts as
**Draft**.

## Importing lines

Open the statement and use **Import Lines (CSV)** to bring in the bank's own
transaction rows: one per line, as `transaction_date,description,reference,amount`
— amount signed, negative for money out. Each imported row becomes a **Line**,
shown on the statement's **Lines** tab, starting as **Unmatched**. Lines are
only ever created this way or through matching — the Lines tab itself is
read-only, there's no manual line entry.

## Matching lines to the ledger

**Auto-Match** compares every unmatched line against the ledger, looking for
an exact amount with a date within 3 days, or an exact amount with a matching
reference. Anything it matches is marked **Auto-matched**; anything it can't
determine on its own stays **Unmatched** for a human to resolve by hand
(shown as **Manually matched** once resolved), or to mark **Excluded** if it
genuinely has no ledger counterpart.

## Completing a reconciliation

**Complete Reconciliation** is only available once **every line is either
matched or excluded** — nothing left Unmatched — and the statement's closing
balance equals the ledger's balance for that account. Completing it **locks
the statement**: no further editing, importing, or matching is possible past
this point, and it can no longer be deleted. This is deliberate — a completed
reconciliation is a signed-off record, not a draft that happens to be marked
done.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `BankStatementView` | See bank statements |
| `BankStatementCreate` | Add a statement |
| `BankStatementUpdate` | Edit one (not once completed) |
| `BankStatementDelete` | Delete one (not once completed) |
| `BankStatementImport` | Import CSV lines (not once completed) |
| `BankStatementMatch` | Run Auto-Match (not once completed) |
| `BankStatementComplete` | Complete the reconciliation |

Accountant, Manager and CEO can all view/create/update/import/match. **Only
Manager and CEO can complete a reconciliation.** **Only CEO and Administrator
can delete a statement**, and only before it's completed — a completed one
can never be deleted by anyone. This resource belongs to the **Accounting**
module.
