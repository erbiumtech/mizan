## What a material issue is

A docket taking material out of a site store to the work face. One store, one date,
two signatures — and a line for each product that went out.

You only need these if a job keeps a **site store**. Most contractors buy
everything direct to the work face, and that path costs the material when it is
delivered and touches no stock at all.

## Issuing does not add cost

This is the part worth understanding, because it looks wrong at first.

The material was **already costed when it was delivered** — a store receipt puts
the cost on the job against whatever code the delivery was received under. So
posting a docket does not charge the job again. It **reclassifies**: it takes the
value out of the code the material was received against and puts it on the code it
was actually used on.

The job's total cost does not change. That is why the register column is called
*value moved* rather than cost.

> Charging on issue as well would charge every stocked delivery twice, and both
> figures would look like material cost on the same job — nothing anywhere would
> disagree with either.

The value comes from the **FIFO lots the store actually holds**, and it is frozen
onto the line at posting. It cannot be recalculated later, because the lots it came
from have been consumed.

## Cost types have to match

The code you issue to must be the same **cost type** as the code the material was
received against — material to material, and so on.

Moving cost between types would move it between ledger accounts, which an issue is
not allowed to do. If the refusal fires, either issue it to a code of the right
type or correct the code the delivery was received against.

## Wastage <!-- requires: ConstructionMaterialIssue -->

**Wastage is part of the quantity, not on top of it.** Twelve tonnes out with one
wasted is `quantity 12, wasted 1` — eleven tonnes were built in.

It leaves the store as its own **waste** movement, separate from the issue, so "what
did we waste this month" is a query rather than a column somebody remembers to
subtract. It stays on the job either way: the company paid for it, and it was wasted
on that activity.

Fill in the reason. Wasted material with no reason is the figure nobody can do
anything about next month.

## Returns

Material comes back **against the line it went out on** — the *Return* action on the
line, not a new docket. The docket the material left on is still in the file, and a
second numbering series for its reversal is a series nobody reconciles.

It goes back at **the cost it left at**. Revaluing it at today's FIFO would make a
return a way of changing the value of stock without buying anything. The
reclassified cost unwinds in the same proportion.

You cannot return more than is still out on the line — that is either the wrong
line, or a delivery somebody is recording as a return.

## Draft and posted

A **draft** docket has not moved anything: the stock still shows the material in the
store. Use the *Not posted* filter — a draft that sits there is material that has
physically gone with the records saying otherwise.

**Posting** takes the stock, values it, and moves the cost. After that, the way back
is *Reverse* with a reason, which needs `ConstructionCostReverse` — the same grant as
backing any posted cost entry out of the ledger.

## Without the Inventory module

There are no issues at all, and the register is not in the menu. An issue *is* a
stock movement, so there is nothing smaller to fall back to — deliveries go direct
to site and are costed on receipt, which is what most contractors do for everything.
