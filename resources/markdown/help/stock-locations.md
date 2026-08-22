## What a stock location is

A place stock can be: a warehouse, a shop, a van, a construction site store.

**If you keep stock in one place, you need none of these.** Nothing is created for
you, because a location nobody chose is a row somebody later has to work out the
meaning of. On-hand figures without any locations behave exactly as they always
have — a company-wide total, which is the right answer when there is one place.

Add locations when stock is genuinely in more than one place, and the reports gain
a *where*.

## The kinds

| Kind | What it is |
|---|---|
| **Warehouse** | A central store. |
| **Shop** | A retail outlet. |
| **Site store** | A construction site's store, named on the job. |
| **Van** | Stock on a vehicle — a real place, and the commonest source of loss nobody can explain. |
| **In transit** | Between two locations during a transfer. |

**In transit** looks like a workaround and is not. A transfer is two movements —
out of one place and into another — and without somewhere to be in between, stock
that has left the warehouse and not reached the site is either in both places or
in neither.

## Site stores

A construction job points at one. Set it on the job, under its own screen, and
then a goods receipt can be marked *store* instead of *direct to site* — the
material is stocked at that location, and issuing it later takes it out at FIFO
cost.

Without a location on the job, a store-destined receipt is **refused** and the
message says so. That is deliberate: accepting it would cost the material as
though it had been stocked, and materials-on-site would be wrong with nothing
saying so.

## The inventory account

Only fill this in if you split your inventory account by location. Blank means the
product's own account applies, which is what happens without locations at all.

## There is no delete <!-- requires: ProductUpdate -->

A location with movements against it is where that stock is. Deleting it would
leave those movements at no location — the on-hand total would stay right while
every per-location figure went wrong, which is the hardest kind of error to
notice.

Switch it off with **In use** instead. It leaves the pickers and its history stays.

## Who maintains these

`ProductView` to read them, `ProductUpdate` to change them — the same grants as the
product register, because a location is part of the same answer as a product: what
stock, and where. `StockAdjust` is not involved; creating a location moves nothing.
