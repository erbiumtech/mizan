## Asking for something

A requisition is site saying **what it needs and when**. It is the one construction
register site staff create in, and that is deliberate: the demand comes from the
people who need the material.

**Asking commits nothing.** No money appears anywhere on the cost report until an
order raised from this request is *issued* to a supplier. So ask freely — the
approval gate is there precisely so that asking is free.

## Raising one <!-- requires: ConstructionRequisitionCreate -->

**New**, pick the job, say when it is **needed on site**, then add what you need.

A date rather than a priority flag, because "urgent" means whatever the person
ticking it wants it to mean, and a date is something the buyer can plan against. A
request with no date shows as **Not stated** on the register — which is not "no
hurry", it is something the buyer has to come and ask you about.

For each line: what it is, how much, and the unit. In the words you would write on
a paper docket — the buyer reads this and puts it on an order.

Two fields are optional and worth understanding:

- **Cost code** — leave it blank if you do not know. The buyer sets it when the
  order is raised, and the order will not go out without one. Guessing here is
  worse than leaving it: a wrong code looks like data afterwards.
- **Estimated rate** — a guess helps whoever approves it decide. It is never a
  price. What gets committed is whatever the supplier actually quotes.

**Submit** when it is ready. It stays visible to you the whole way through.

## Approving <!-- requires: ConstructionRequisitionApprove -->

**Approve** says the need is real and may be ordered. It still commits nothing.

**Reject** needs a reason, and the request stays **editable** afterwards — because
the answer is usually "not like that, like this" rather than simply no, and a
request that vanished would be typed again from memory.

## Ordering from it <!-- requires: ConstructionCommitmentCreate -->

**Order** turns an approved request into order lines, either on a new draft order
or added to an existing one — several requests going to the same supplier belong on
one order.

The order lines are **linked back to the request lines**, which is what makes the
next part work:

| | |
|---|---|
| Asked for | 40 t |
| Ordered | 20 t |
| Still to order | **20 t** |

Order part of it now and the rest stays outstanding here. The request moves to
**partially ordered** and stays on the buyer's queue until nothing is left.

**A cancelled order leaves its line outstanding again.** That is on purpose: if the
order was cancelled, site still needs the material, and counting it as ordered would
leave a need nobody is chasing with nothing on any screen showing it.

Ordering more than is outstanding is refused. If the need has genuinely grown, raise
it as its own line, so what was asked for and what was ordered still add up.

## The buyer's queue

The **To order** filter on the register shows approved requests with something still
outstanding, soonest needed first.

That list is the reason this document exists. Without it, the first record of a need
is the order raised to satisfy it — so *what has been asked for and not yet ordered*
has no answer at all, and the queue lives in somebody's inbox.

## Who does what

| | Site | Surveyor / buyer | Manager | CEO |
|---|---|---|---|---|
| Raise a request | ✓ | ✓ | ✓ | ✓ |
| See all requests | ✓ | ✓ | ✓ | ✓ |
| Approve or reject one | | | ✓ | ✓ |
| Raise the order from it | | ✓ | ✓ | ✓ |
| Issue that order to the supplier | | | ✓ | ✓ |

Four steps, three different people, and money is committed only at the last one.
