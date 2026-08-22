## The number a site manager manages by

**Committed cost is money promised to somebody and not yet invoiced.** It is what
tells you a cost code is overspent *before* the invoice arrives — which is the
only point at which anybody can still do something about it.

This register holds purchase orders, subcontract orders and plant hire. One
register for all three, because the cost report has one **Committed** column and
it has to mean the same thing whichever kind of order filled it.

## One order, several jobs

The job is on each **line**, not on the order.

That is deliberate. One order of rebar split across three sites is completely
normal. Putting the job on the header would force one of two bad outcomes: three
separate orders for one delivery — which the supplier will not honour, and the
delivery note then matches nothing — or the whole load coded to one job, which is
invisible once it has happened.

Each line names the job, the cost code and, optionally, the WBS element. Only
**bookable** cost codes appear: committing against a heading would count the money
twice in every rolled-up total.

## Approved is not committed <!-- requires: ConstructionCommitmentApprove -->

There are two buttons, and the difference between them is the point:

| | What it means | On the cost report |
|---|---|---|
| **Approve** | this company has decided to spend the money | not yet |
| **Issue** | the supplier has been told | **committed** |

An approved order sitting in a drawer can still be withdrawn with a phone call and
no consequence. An issued one cannot — somebody else is relying on it. So the
committed column reads issued orders only, and an approved order shows *"approved,
not yet issued — not committed"* on its row so nobody has to guess which state it
is in.

## Open, and how it is worked out

**Open = ordered − everything that has relieved it.** Every relief is its own row,
naming what caused it: a goods receipt, a subcontract certificate, an invoice
allocation, a cancellation, a close-out.

The obvious shortcut — *committed = ordered − invoiced*, matched up by supplier and
code — is not used here, and it is worth knowing why: it breaks the first time one
invoice covers two orders, or one line is part-delivered. And it breaks by leaving
an over-commitment that nobody can point at and nobody can clear.

With rows, the figure is **provable**: you can open any line and see every event
that reduced it.

### Relief happens once

A goods receipt relieves the order. The invoice for those same goods must not
relieve it again, or the cost code looks like it has room in it that it does not.

The rule: relief happens at the **earlier** of receipt or certificate, and an
invoice relieves only the **unreceived** balance. So an invoice for goods already
received relieves nothing — that is correct, not a fault.

If more has been relieved than was ordered, the row says **over-relieved** in red.
That is a real condition worth chasing rather than an impossible one.

## Closing and cancelling <!-- requires: ConstructionCommitmentClose -->

Both take whatever is still open off the committed column, and both need a reason.

- **Close** — this is finished, and what is left will not be spent. The supplier
  delivered 9.6 tonnes against 10 and everyone agreed to leave it.
- **Cancel** — this should never have been placed.

The reason is not a formality. An order that quietly stops changing is an open
commitment nobody will ever clear, and *"delivered short and agreed to leave it"*
reads very differently from *"somebody forgot"* when the same number is being
explained six months later.

Nothing here is deleted. An issued order is a document a supplier holds; cancelling
writes the reliefs that take the money back and leaves the history standing.

## Who does what

| | Site | Surveyor / commercial | Manager | CEO |
|---|---|---|---|---|
| See what is on order | ✓ | ✓ | ✓ | ✓ |
| Raise and price an order | | ✓ | ✓ | ✓ |
| Approve it | | | ✓ | ✓ |
| Issue it to the supplier | | | ✓ | ✓ |
| Close or cancel it | | | | ✓ |

Site staff hold view because *"has the rebar been ordered"* is a site question, and
the answer being invisible is what produces a second order for it.
