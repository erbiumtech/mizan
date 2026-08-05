## What this tracks

A month's petty cash float: what it started with, what was spent out of it
(vouchers), any direct top-ups, and what's left. One month at a time.

## Recording spending

**Add Voucher** records an expense paid from the float: date, a description,
a **Category** (a transaction type), the amount, and an optional receipt
attachment (image or PDF, up to 5 MB). **View attachment** opens whatever was
uploaded for a voucher already on the book.

**Edit voucher** lets you correct a voucher's details, amount and attachment
— but not its date or category. Moving a voucher to a different month or
expense category is a re-book (delete and re-add), not an edit, since the
month and category are what group vouchers into the summary in the first
place.

## Topping up and replenishing — the two ways money enters the float

- **Top Up Float** — a direct deposit from the bank into the float, recorded
  immediately. Use this when cash is added to the float outside of the normal
  monthly cycle.
- **Replenish Month** — the end-of-month step: creates a payment to the
  custodian for the difference between the float amount and the closing
  balance, bringing the float back up to its target level. This payment
  doesn't pay out immediately — it queues onto the **Bank Payment File** like
  any other payment, to be released from there.

## The replenished lock

**Once a month has been replenished, its vouchers can no longer be added or
edited.** Replenishment closes the month's books against the figure that was
just paid out — changing a voucher afterward would make that payment wrong
after the fact. If you need to correct something in a month that's already
replenished, the correction belongs in the next month, not a retroactive edit
to a closed one.

## Roles and permissions

| Action | Permission |
|---|---|
| View the book | `PettyCashView` |
| Add / edit a voucher | `PettyCashCreate` |
| Top up or replenish | `PettyCashReplenish` |

Accountant holds `PettyCashView` and `PettyCashCreate` but not
`PettyCashReplenish` — recording spending and closing the month out are
different levels of trust here, the same segregation of duties as Journal
Entries. Manager and CEO hold all three. All of it is gated behind the
Accounting module being enabled for the company.
