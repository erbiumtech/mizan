## Where cost first touches the job

A goods receipt records **what actually arrived**. Posting one does two things:

1. **Relieves the order** — the committed figure falls by what was delivered.
2. **Puts accrued cost on the job**, at the order rate.

The second is the one worth understanding. Between the delivery and the supplier's
invoice, the job has genuinely incurred cost that no document yet proves. A cost
report that waited for the invoice would understate every single month end. So the
receipt books it as **accrued** — kept in its own column, separate from actual cost,
because an estimate and a proven figure are different things.

## Recording one <!-- requires: ConstructionReceiptRecord -->

**New**, pick the order it is against, put in the date it arrived and the supplier's
**delivery note number** — that last one is what an invoice gets matched against
later, so it is worth typing accurately.

Then add what arrived. Pick the **order line** and the job, the cost code, the
description and the rate all fill themselves in from the order. That is deliberate:
the accrual has to be at order rate for the invoice comparison to mean anything, and
a rate typed a second time is a rate that will differ from the first.

**Post** when the note is signed off. Until then it is a draft and has changed
nothing.

### Deliveries nobody ordered

Leave the order blank. It still gets costed against the job and code you pick — it
relieves nothing, because there is nothing to relieve.

This is allowed on purpose. Refusing would leave material standing on site with no
cost against it, and the only way round would be inventing an order after the fact.

### More arrived than was ordered

Record what arrived. The order will show as **over-relieved**, which is a real
condition worth chasing rather than an impossible one — a supplier sending a full
pack instead of the ordered part of one is ordinary.

## Getting it wrong

**Reverse**, with a reason. It puts the commitment back on the order and reverses the
accrued cost, both as rows you can read.

Nothing is deleted. A posted receipt has moved the committed figure and the cost
report, so the correction has to be visible — the same rule the job cost ledger keeps
about reversals.

A posted receipt is not editable either. Record a second delivery instead.

## Site stores are not here yet

If material is going into a **site store** rather than straight to the work face,
this application cannot yet record the stock movement — that needs stock to have a
location, which is coming with site stores.

So a line marked for a store is **refused**, with a message saying exactly that.

That is deliberate rather than unfinished. Accepting it would cost the material as
though it had been stocked, and *materials on site* would then be wrong with nothing
anywhere saying so. Receive it as **direct to site** in the meantime: the cost is
recorded correctly, and only the stock side is missing.

Direct to site is the normal path anyway, and it touches no stock at all — which is
how most contractors buy most things.

## Who does what

| | Site | Surveyor / buyer | Manager | CEO |
|---|---|---|---|---|
| Record and post a delivery | ✓ | ✓ | ✓ | ✓ |
| See deliveries | ✓ | ✓ | ✓ | ✓ |
| Reverse a posted one | | | ✓ | ✓ |

Site records deliveries because the person signing the note is the only one who knows
what turned up. Reversing is not site's: it takes cost off a job and commitment back
onto an order, and that belongs with whoever answers for the figures.
