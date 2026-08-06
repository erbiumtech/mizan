![Products](/images/help/products.png)

## What a product is

A stock item: its SKU, name, unit of measure, reorder level, and which
accounts its purchases, cost of sale, and revenue post against (defaulting to
the company's standard Inventory / COGS / Revenue accounts if left blank).
**On Hand** and **Stock Value** on the list are calculated from its movement
history, not stored — see Stock Movements for what actually produces them.

## Valuation method

Set once per product and used every time stock is sold or written off:

- **FIFO** — the oldest purchased lot is consumed first.
- **LIFO** — the newest purchased lot is consumed first.
- **Average Cost** — cost of sale is the running weighted average of
  everything currently on hand, not tied to any one lot.

Changing this on an existing product changes how *future* sales are costed; it
doesn't retroactively re-cost what's already been sold.

## Recording stock movements <!-- requires: StockMove, StockAdjust -->

The Product form itself never creates a movement — quantities and costs are
recorded from three actions on this list, against a specific product:

- **Receive Stock** — a purchase: quantity and unit cost, posted debit
  Inventory / credit Cash-Bank.
- **Record Sale** — quantity and unit price; cost of sale is worked out by the
  valuation engine at the moment of sale, not typed in, and posted alongside
  the revenue in one balanced entry.
- **Adjust Stock** — a count correction or write-off. A positive quantity
  needs a unit cost (it books like a newly found lot); a negative quantity is
  costed at the valuation method, same as a sale.

Every one of these posts its own balanced, already-Posted journal entry
automatically — there is no draft or approval step, the same as a payment or
an invoice. To correct one, don't edit the journal entry it produced; adjust
the stock again (see Stock Movements).

## Deleting <!-- requires: ProductDelete -->

Only possible for a product with **no movement history at all** — the moment
anything has been received, sold, or adjusted against it, the product itself
becomes part of that record and can no longer be removed.

## Roles and permissions

- **View / Create / Update** (`ProductView/Create/Update`) — Accountant,
  Manager, CEO, Administrator.
- **Delete** (`ProductDelete`) — Manager, CEO, Administrator only (not
  Accountant), and only with no movement history, per above.
- **Receive Stock / Record Sale** need `StockMove` (Accountant and above);
  **Adjust Stock** needs the separate `StockAdjust` (Manager and above) — an
  Accountant can move stock in the ordinary course of business but not
  override a count.
- Depends on the Inventory module being enabled.
