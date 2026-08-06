## What this chapter covers

Two kinds of thing the company owns that lose or change value over time, and both
of which keep the ledger updated by themselves: **fixed assets**, which
depreciate month by month until they are written off, and **stock**, which moves
in and out at cost.

Neither is a place you write journal entries. In both cases you record a physical
event — a vehicle was bought, twenty units were sold — and the accounting follows
automatically.

## Fixed assets: the lifecycle

An asset goes through three states, and the screen shows which one it is in:

1. **Active** — in service and depreciating.
2. **Fully depreciated** — the asset has been written down as far as it goes.
   Reached automatically, not chosen.
3. **Disposed** — gone from the books.

### Recording an asset

From **Fixed Assets**, click **New**. What matters:

- **Asset Code** and **Name** — how it is identified everywhere else.
- **Asset Account** — which account in the chart holds its cost. This is the
  account credited when the asset is eventually disposed of.
- **Purchase Date** and **Purchase Cost**.
- **Useful Life (months)** — how long it depreciates over.
- **Salvage Value** — what it is expected to still be worth at the end. Defaults
  to zero.
- **Depreciation Method** — straight line (the default) or declining balance.

The figure that gets depreciated is the **depreciable base**: purchase cost less
salvage value. Straight line divides that evenly across the useful life;
declining balance takes twice the straight-line rate against the remaining book
value each month, which front-loads the charge.

### Running depreciation

Depreciation is not automatic in the sense of happening on a timer — somebody
runs it. On **Fixed Assets**, either **Run Depreciation** on a single asset, or
select several and use the bulk action, choosing the month.

For each asset it books one month's charge: debit depreciation expense, credit
accumulated depreciation, dated the last day of that month. The entry is
approved and posted immediately.

The run is safe to repeat. An asset is skipped when:

- it is not depreciable (already fully depreciated, or disposed);
- it had not been purchased yet in that month;
- a depreciation entry for that month already exists;
- there is nothing left to depreciate.

That last point matters at the end of an asset's life: the final month's charge
is capped at whatever is actually left, so the asset lands exactly on its salvage
value rather than overshooting. When it gets there, the status changes to Fully
Depreciated on its own.

Because each month is a separate entry dated to that month end, running
depreciation for a month inside a **closed** fiscal year will be refused —
posting into a closed year is not allowed. Run depreciation before closing, not
after.

### Disposing of an asset

**Dispose Asset** writes it off in one entry: accumulated depreciation is
cleared, the remaining book value is booked as a loss on disposal, and the
original cost is credited out of the asset account. The status becomes Disposed
and the disposal date is recorded.

An asset already disposed of cannot be disposed of again. If a disposal was
wrong, reverse its journal entry — see the ledger chapter.

### Who can do what

| Action | Permission |
|---|---|
| See the asset register | `FixedAssetView` |
| Add or edit an asset | `FixedAssetCreate`, `FixedAssetUpdate` |
| Run depreciation | `FixedAssetDepreciate` |
| Dispose of an asset | `FixedAssetDispose` |
| Delete an asset record | `FixedAssetDelete` |

## Stock: where the writing happens

**Stock Movements** is a read-only history. You cannot add a movement there, and
that is deliberate — every movement is the accounting record of a physical event,
and it is created by acting on the product.

All three actions live on the **Products** screen:

- **Receive Stock** — quantity and unit cost. Debits inventory, credits cash or
  bank. Creates a purchase lot.
- **Record Sale** — quantity and unit price. Posts two pairs of lines at once:
  the revenue leg at the price you sold for, and the cost-of-goods-sold leg at
  the cost the valuation engine works out.
- **Adjust Stock** — a count correction or write-off. A positive adjustment needs
  a unit cost and books like a lot that was found; a negative one writes stock off
  at valuation cost, charged to cost of goods sold.

Each posts a single balanced entry, already approved, and stores it against the
movement. Selling more than is on hand is refused, naming what is available.

Each product can name its own inventory, cost-of-goods-sold and revenue accounts.
Where it does not, the standard chart accounts are used, so products work without
configuration and can be specialised later.

## How stock is valued

Each product carries a **valuation method**, and it decides what a sale costs:

- **FIFO** — consumes the oldest purchase lots first.
- **LIFO** — consumes the newest first.
- **Average** — uses the weighted average cost of everything on hand.

FIFO and LIFO genuinely consume lots: each purchase remembers how much of it is
left, and a sale draws that down in date order. This is why the same product sold
at the same price on two different days can produce two different cost figures,
and why the method should be chosen once and left alone.

Two derived numbers appear on the Products list:

- **On Hand** — the sum of every movement's quantity. Sales and write-offs are
  stored negative, so this is simply the running total.
- **Stock Value** — everything that came in at cost, less the cost taken back out
  by sales and write-offs.

Average cost is stock value divided by quantity on hand, which is why it moves
when you receive stock at a new price.

### Who can do what

| Action | Permission |
|---|---|
| See products and stock history | `ProductView` |
| Add or edit a product | `ProductCreate`, `ProductUpdate` |
| Receive stock or record a sale | `StockMove` |
| Adjust or write off stock | `StockAdjust` |
| Delete a product | `ProductDelete` |

Fixed assets belong to the **Accounting** module; products and stock movements
belong to **Inventory**. A company without the Inventory module has no Products
screen, and nothing in this half of the chapter applies to it.
