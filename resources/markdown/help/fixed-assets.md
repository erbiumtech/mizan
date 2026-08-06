![Fixed Assets](/images/help/fixed-assets.png)

## What this is

Company-owned assets that lose value over time — equipment, vehicles,
furniture — tracked here so their depreciation posts to the ledger
automatically instead of being calculated by hand.

## Creating one <!-- requires: FixedAssetCreate -->

Click **New**. You'll set:

- **Asset Account** — the balance-sheet asset account this asset's cost sits
  in. Only active, postable accounts of type Asset are offered.
- **Purchase Date** and **Purchase Cost**.
- **Depreciation Method** — **Straight Line** spreads the depreciable amount
  evenly over the useful life; **Declining Balance** applies a fixed rate
  (double the straight-line rate) to the current book value each month, so the
  charge shrinks over time.
- **Useful Life (months)**.
- **Salvage Value** — the residual value the asset is never depreciated below.

An **Asset Code** (e.g. `FA-0001`) is generated automatically — you don't set
it.

## Running depreciation <!-- requires: FixedAssetDepreciate -->

**Run Depreciation** books one month's charge for the selected asset(s) and
posts the journal entry immediately — there's no draft or approval step for
it. Pick the month to depreciate (defaults to last month); the amount booked
is capped at whatever remains of the depreciable base, so running it past an
asset's useful life simply books nothing more. An asset that's already fully
depreciated, or already disposed, is not eligible.

## Disposing an asset <!-- requires: FixedAssetDispose -->

**Dispose Asset** writes the asset off the books — its remaining book value
becomes a loss — and **cannot be undone**. Once disposed, the asset can no
longer be edited or depreciated further; only the record itself remains, for
history.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `FixedAssetView` | See fixed assets |
| `FixedAssetCreate` | Add one |
| `FixedAssetUpdate` | Edit one (not once disposed — disposed assets are frozen) |
| `FixedAssetDelete` | Delete one, only if it has no ledger history at all |
| `FixedAssetDepreciate` | Run depreciation |
| `FixedAssetDispose` | Dispose an asset |

Accountant, Manager and CEO can all view/create/update. **Depreciating and
disposing require Manager or CEO** — the Accountant records assets but does
not run these. **Only CEO and Administrator can delete**, and only an asset
that has never had a journal entry posted against it (buying, depreciating or
disposing all count). This resource belongs to the **Accounting** module.
