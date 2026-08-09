![Currencies](/images/help/currencies.png)

## What this is

The currencies the company deals in, beyond its own books. Which currency the
ledger itself is kept in is set once, in Company Settings — this page is for
the *other* currencies you invoice or get paid in, and the exchange rates
needed to convert them.

## Adding a currency <!-- requires: AccountCreate -->

Click **New** and enter its ISO 4217 **Code** (e.g. `USD`, `EUR`) — exactly 3
letters, unique. Add a display **Symbol**, how many **Decimals** it's shown
with (default 2), and whether it's **In use**. Turning "In use" off stops it
being offered on new records without touching anything already posted in it.

**The code is fixed once a rate has been recorded against it.** You can still
rename or deactivate the currency, but the code itself becomes read-only —
changing it after the fact would silently disconnect it from every rate and
posting already recorded under the old code.

## Recording rates <!-- requires: AccountCreate -->

Open a currency and go to its **Rates** tab. Each rate has an **effective
date**, the **rate** itself (expressed as the base currency per 1 unit of this
currency — e.g. 304 means 1 = 304 in the base currency), and an optional
**source** for your own reference.

Rates are looked up by date: converting an amount uses the most recent rate on
or before the date being posted. Recording today's rate never changes how a
transaction from last month was converted — it only takes effect going
forward.

## Roles and permissions

Currencies use the **same permissions as the Chart of Accounts** — there's no
separate `CurrencyView`/`CurrencyCreate` — because deciding which currencies
the company deals in is treated as the same kind of decision as the chart
itself:

| Permission | What it allows here |
|---|---|
| `AccountView` | See currencies and their rates |
| `AccountCreate` | Add a currency or record a rate |
| `AccountUpdate` | Edit a currency or delete it |

A currency can never be deleted if it's the company's **base currency**, or if
it has **any rate ever recorded against it** — both to protect postings that
already depend on it. This resource belongs to the **Accounting** module.
