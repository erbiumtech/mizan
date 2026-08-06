Petty cash here is a proper **imprest float**: the box is topped up to a fixed
amount, spending is recorded against it voucher by voucher, and at the end of the
month the box is restored to the float by paying the custodian back exactly what
was spent. That last step closes the month and locks it.

Everything happens on one screen — **Accounting → Petty Cash Book** — which needs
the **`PettyCashView`** permission. The book is shown a month at a time, with a
Received side and a Paid side, an opening and closing balance, and the float it
is being measured against.

## Setting it up

Three things have to be in place before the first voucher.

1. **The float amount.** Set it under **Company Settings** ("petty cash float
   amount"). It defaults to 4,000 unless a different default is configured for
   the installation. This is the figure the box is restored to each month, so it
   should be what you actually want sitting in the drawer.
2. **A custodian.** This is a flag on a **beneficiary**, not on a company bank
   account — which is the obvious place to look and the wrong one. Open
   **Accounting → Beneficiaries**, edit the person who physically holds the cash,
   and turn on **Petty cash custodian**. Only one beneficiary can hold the flag:
   turning it on for somebody else silently turns it off for the previous holder,
   which is deliberate, because replenishment pays the first active custodian it
   finds and with two of them the recipient would depend on row order. An
   **inactive** beneficiary does not count as custodian even with the flag on.
3. **Transaction types with accounts.** Every voucher is analysed by transaction
   type, and a type with no default account cannot be booked — the voucher is
   refused with a message naming the type. Set them up under **Accounting →
   Transaction Types**.

Account **1150 Petty Cash** must also exist in the chart of accounts. It comes
from the standard chart; if it is missing the screen will tell you so rather than
guess.

## Putting money in the box

Use **Top Up Float** (needs **`PettyCashCreate`**). Give it a date, an amount and
a description. It books immediately: **debit 1150 Petty Cash, credit 1100
Cash/Bank** — money moves from the bank into the box.

This is how the float is established in the first place, and how you add to it
mid-month if the box runs dry before month end. Top-ups appear on the **Received**
side of the book.

## Recording what was spent

Use **Add Voucher** (also **`PettyCashCreate`**). Each voucher takes:

- a **date**,
- **details** — what it was for,
- an **amount**,
- a **transaction type** — which decides the column it lands in on the Paid side
  and the expense account it is booked to,
- optionally a **receipt** — an image or a PDF, viewable later from the row.

Saving it books **debit the expense account, credit 1150 Petty Cash** and creates
a numbered voucher (`PCV-2026-0001`, and so on, numbered per calendar year).

Two things will stop a voucher:

- **The float would be overdrawn.** You cannot record spending the box did not
  have. The message tells you the current balance. Top up first.
- **The month is already replenished.** Once a month has been closed there is
  nothing left to record against it — see below.

## Correcting a voucher

Use **Edit voucher** on the row, while its month is still open. You can change the
details, the amount and the receipt.

The posted entry behind it is **adjusted in place** rather than reversed. That is
a deliberate choice: a reversal would be dated today and would surface as a
spurious Received row in the book, which is worse than a corrected figure.
Balances move by the difference only.

Two cases are refused instead:

- The entry has been **split** into more than two lines — edit it in the journal
  instead.
- Any line on it has been **reconciled** against a bank statement. A reconciled
  entry is settled history and can no longer be changed.

Increasing an amount is still subject to the overdraw check, on the difference.

## Reading the book

The month shows:

- **Opening balance** — what was in the box at the start.
- **Received** — top-ups and replenishments landing in the month.
- **Paid** — every voucher, laid out in columns by transaction type, with a total
  per column so you can see at a glance what the month went on.
- **Closing balance** — opening plus received, less paid.
- The **ledger balance** for the same date, which is what account 1150 actually
  says. These should agree; a difference means something touched 1150 outside the
  petty cash book.

## Closing the month

Use **Replenish Month** (needs the separate **`PettyCashReplenish`** permission).
It only appears when the month has not already been replenished and the closing
balance is genuinely below the float.

It does **not** move money. It creates a **draft payment** to the custodian for
`float − closing balance` — exactly what was spent — dated the last day of the
month. From there it is an ordinary payment: it rides in the bank payment file
with everything else, and the chapter on paying suppliers and other beneficiaries
covers the rest of its life.

The important consequence is the timing: **the ledger is not touched when you
replenish.** The debit to 1150 and credit to 1100 happen when that payment is
**approved**, which is either when somebody approves it explicitly or
automatically as the bank file is downloaded. Until then the book shows the month
closed while the box has not actually been refilled — which is correct, because it
has not.

Replenishment will refuse if:

- the month has **already been replenished**,
- the float is **already at or above** its target, so there is nothing to restore,
- there is **no active custodian** — the message tells you exactly where to set
  one,
- the **petty-cash-replenishment transaction type is missing**, so the payment
  could not be classified.

## What "closed" means

A month is treated as replenished when a payment exists whose details read
"Petty cash replenishment &lt;Month Year&gt;". That is the lock: once it is there,
that month accepts no new vouchers and no edits to existing ones.

It follows that deleting that draft payment re-opens the month. That is the way
back if you replenished too early — but do it before the payment is approved and
released, because after that the money has gone to the custodian and the month's
figures are what the payment was based on.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `PettyCashView` | Open the Petty Cash Book and read any month |
| `PettyCashCreate` | Add and edit vouchers, top up the float |
| `PettyCashReplenish` | Close a month by raising the replenishment payment |

Closing the month is its own permission on purpose: recording spending is
day-to-day work, while replenishing decides what the company reimburses and locks
a month of the ledger against further change.

Note that the replenishment payment it creates is then governed by the payment
permissions, not these — so the person who closes the petty cash month is not
necessarily the person who can approve or release the payment that refills it.
