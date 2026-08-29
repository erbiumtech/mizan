# Recording a receipt

## What this is

A customer sends one transfer for five invoices. This is the screen that settles all five in one go.

Pick the customer, enter what arrived and the bank reference, then either press **Allocate oldest first**
or type an amount against each invoice. When the unallocated figure reaches nil, **Record receipt** settles
them.

## What it does behind the screen

Each line goes through exactly the same settlement the invoice screen uses — the same treatment of a
foreign-currency invoice, the same realised exchange difference, the same move to *Partly paid* or *Paid*.
Nothing here posts anything of its own, which is why a receipt recorded this way is indistinguishable from
five recorded one at a time, except that the bank reference appears on every one of them.

All five settle together or none of them do. Half a receipt recorded is worse than none: the customer's
balance is then wrong in a way that reconciles to nothing.

## Why it will not take money it cannot place

The allocations have to add up to the receipt exactly. If a customer pays more than they owe, or pays
before you have invoiced them, this screen refuses.

That is a real limitation and not an oversight: this application has nowhere to hold a balance that is not
against an invoice — no advances table, no on-account credit. Somewhere to put it is a bigger change than
this screen, and it should be built when somebody actually has that problem rather than because another
system has the feature.

Until then: raise the invoice first, or record what was actually owed and deal with the difference
separately.

## Related

- **Aged Receivables** — what is outstanding, and how late.
- **Control accounts** health check — proves the receivables in the ledger and the receivables in the
  invoices still agree. A journal entry posted straight at 1250 moves one and not the other, and that check
  is what makes the disagreement loud.

## Roles and permissions

`PaymentCreate`, the same permission that records a payment anywhere else.
