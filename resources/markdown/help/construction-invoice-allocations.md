## The failure this screen exists to prevent

A supplier invoice can be entered, posted and paid without ever being attributed to
a job. The accounts are then **perfectly correct** — the cost is in the ledger, the
supplier is owed, the VAT is right — and the job is **under-costed**.

Nothing else on any report disagrees. Every margin flatters. That is why this is a
queue you work from rather than a rule that blocks the invoice: blocking the invoice
would stop finance paying suppliers, and the cost of that is worse.

## What is on the queue

Every purchase invoice with anything still unallocated, **oldest first**, with a
total at the top.

The age matters more than it looks: an invoice unallocated for two months is a job
that has been reporting a better margin than it has for two months.

When the queue is empty it says so in words rather than showing a blank page —
because a screen that looks broken when it is finished is a screen people stop
opening.

## Allocating one <!-- requires: ConstructionInvoiceAllocate -->

**Allocate**, then add a row per job and cost code. As many rows as it takes.

That is the whole reason allocations are their own record rather than a job field on
the invoice: one line — *"rebar, 12 t"* — routinely lands on two jobs and three cost
codes. The alternative is splitting the invoice line, and then the invoice this
application prints no longer matches the one the supplier sent. That difference gets
found months later, in a dispute.

**Amounts are net of tax.** The supplier's sales tax is not job cost, and putting it
on a job would flatter nothing and distort the rate you price the next tender from.

**A credit note allocates negative**, and job cost falls by it.

### Against an order line

Optional, and worth doing where it applies. Naming the order line does two things:

- the invoice **relieves the order** — but only what the delivery did not. If the
  goods were already received, the receipt relieved it and the invoice relieves
  nothing, which is correct rather than a fault;
- it gives the three-way match its third leg: ordered, received, invoiced.

### Over-allocating is refused

Allocating 120 of a 100 invoice would put cost on a job that no supplier ever
charged — and the general ledger would not disagree, because it never sees the
allocation at all. So it is refused, naming what is left.

## Getting it wrong

**Withdraw** the allocation. The cost entry is **reversed**, not deleted: the job
carried that cost for as long as the mistake stood, and a reversal is what says so.
The commitment goes back onto the order at the same time.

The allocation row itself does go, because unlike a cost entry it is not a statement
about a day — it is an attribution, and the attribution was simply wrong.

## Why the delivery's accrual is still there

When goods were received, the delivery raised an **accrual** — cost the job had
incurred that no supplier document yet proved. Allocating the invoice adds the
**actual** cost and deliberately does *not* clear that accrual.

That looks like double counting, and inside one month it is. It is still the right
design: accruals reverse automatically at the **opening of the next period**, all
together, rather than being matched off line by line against invoices. Line-by-line
matching is the same guesswork that fails for commitment relief — one invoice
covering two deliveries, one delivery part-invoiced — and an accrual that fails to
match sits on the balance sheet forever with nobody able to say what it is for.

Reverse-and-re-accrue is self-correcting. Its own failure mode — the reversal not
running — is why it belongs to period *open* rather than period close.

## Who does what

| | Site | Surveyor / commercial | Manager | CEO |
|---|---|---|---|---|
| See the queue | | ✓ | ✓ | ✓ |
| Allocate an invoice | | ✓ | ✓ | ✓ |

Coding an invoice is a commercial act, not site's: the person who signed the
delivery note knows what arrived, and the person allocating knows which code the
company prices its next tender from.
