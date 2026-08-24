## What this shows

Every active product: how much is on hand, what it cost on average, what the stock
is worth, the reorder level, and any flags — all as at the date you give it.

Before this report, the valuation engine was used when *posting* an invoice or a
stock movement and by nothing that answered the question a stocktake asks.

## The two totals

**Stock value** is the valuation added up. **Inventory accounts** is what the ledger
says those accounts hold. The line underneath says whether they agree, and when they
do not it says which of three things is happening:

- **Some products have no inventory account.** Then the two figures *cannot* agree
  and nothing is wrong — those products' stock is in the valuation and in no
  account. The report says how many and by how much, so the difference is
  explained rather than alarming.
- **Everything is mapped and they still differ.** Worth investigating: a movement
  posted to the account by hand, or a product moved from one inventory account to
  another after stock had already been booked to the first.
- **No product names an account at all.** Then there is nothing to reconcile to,
  and the report says that rather than comparing against a nought.

## The flags

Both flags share one column, deliberately. A product that is **both** below its
reorder level and has not moved in months is the case worth acting on, and two
separate reports would put those two facts on two screens.

- **Reorder** — on hand is at or below the reorder level. Products with no reorder
  level set never show it.
- **No movement** — nothing has moved in or out for 90 days.
- **Never moved** — there is no movement history at all. Usually a product somebody
  set up and forgot; treating it as fresh would hide exactly those.

## Where the figures come from

**On hand** is every movement's signed quantity added up — purchases positive, sales
negative.

**Value** is what entered at cost, less the cost of what has gone out. That is the
same arithmetic the costing engine uses when it posts, not a second version of it,
so this report and the journal entries behind stock cannot disagree about a product.

**Average cost** is value divided by quantity on hand. A product with nothing on
hand shows a dash rather than a nought — a nought there would claim the stock is
free, when the truth is that there is no stock to have a cost.

**Products with no movements still get a row.** A product with nothing on hand and
a reorder level is exactly what a reorder flag is for, and it has no movement
history to be found in.

**Only active products.** Deactivating a product takes it off this report, along
with whatever it was holding.

**Only posted journal entries** count towards the account figure — the same rule the
Trial Balance and Balance Sheet follow, so all three agree.

## Roles and permissions

Requires `ReportView`, gated behind the Inventory module being enabled for the
company. Read-only — nothing here adjusts stock, posts a movement or changes a
valuation.
