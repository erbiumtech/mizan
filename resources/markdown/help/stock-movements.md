![Stock Movements](/images/help/stock-movements.png)

## What this is

The full history behind every product's on-hand quantity and stock value:
every purchase, sale, and adjustment, in order, each linked to the balanced
journal entry it posted. This list is **entirely read-only** — there is no
New, Edit, or Delete here, on purpose (parity with the same rule on the
Movements tab of an individual product).

## Where movements actually come from

Nothing is ever typed in on this screen. Every row is created by one of the
three stock actions on the **Products** list — Receive Stock, Record Sale, or
Adjust Stock — see Products for what each does and posts. If a movement looks
wrong, the fix is a new movement (an adjustment) against the product, never an
edit here.

## Reading a row

- **Type** — purchase (stock in), sale (stock out at the valuation cost),
  or adjustment (a count correction or write-off, either direction).
- **Quantity** — positive for stock in, negative for stock out.
- **Unit Cost** — what a purchase or a positive adjustment lot was booked in
  at.
- **Unit Price** — what a sale was billed at (independent of cost).
- **COGS** (`total_cost`) — what a sale or write-off actually cost, worked out
  by the product's valuation method (FIFO/LIFO/Average) at the moment it
  happened.
- **Lot Remaining** — for FIFO/LIFO purchase lots only: how much of that
  specific lot hasn't yet been consumed by a later sale or write-off.
- **Journal Entry** — the already-posted entry this movement produced;
  open it from there for the full debit/credit breakdown.

## Roles and permissions

Gated the same as Products, since the two are read together: **View**
requires `ProductView` (Accountant and above). There's no separate
create/update/delete permission for this resource itself — creating a
movement is really an action on a product, gated by `StockMove` or
`StockAdjust` there. Depends on the Inventory module being enabled.
