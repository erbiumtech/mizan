## What this shows

Every asset the company held on the date you give it: what it cost, how much
depreciation has been booked against it, what it is worth now, and what will be
charged over the next twelve months.

The asset list answers "what do we own", one row at a time. This answers "what is
it all worth, and do the accounts agree" — which is the note a set of accounts
carries at a year end.

## The two totals

**Net book value** is cost less depreciation, added up from the rows. **Asset
accounts less depreciation** is the same figure as the ledger sees it: the asset
accounts these assets sit in, less account 1500.

The line underneath states the two sides **separately**, and that is deliberate.
Net book value is a subtraction, so a difference in it does not say where to look —
a cost posted by hand and a depreciation entry against an asset nobody registered
read exactly alike in the net and are found in completely different places.

- **Cost differs** — usually an asset bought and posted straight to the asset
  account without being entered in the register, so the ledger has it and this
  report does not. Or the reverse: nothing posts an asset's cost when you enter it,
  so a company that books purchases straight to the bank has every asset here and
  none of them in an asset account. The line says which way round it falls.
- **Depreciation differs** — an entry against 1500 for something that is not in the
  register, or a depreciation entry edited after it was posted.
- **The cached figure is out** — see below.

## "As at" means as at

Both figures on every row are as at the date, not as at today:

- An asset **bought after** the date is not listed.
- An asset **disposed of after** the date still is — it was on the books then.
- **Depreciation is summed from the posted entries**, not read from the register's
  stored total.

That last one matters. `fixed_assets.accumulated_depreciation` is a cached column,
so it only ever holds *today's* total. Reading it here would have compared a today
figure against an as-at ledger balance and reported the gap between two dates as a
discrepancy.

Because the entries are the source, the cached column becomes checkable — and when
the two disagree the note says so. That is worth acting on: the register screen, the
asset form and every future declining-balance charge all work from the cached
figure, so while it is wrong those are wrong too.

To fix it, run `php artisan accounting:rebuild-asset-depreciation`. It rewrites each
asset's stored total from its posted entries and only touches the ones that
disagree, so running it twice is safe; `--dry-run` shows the whole plan first. It
also puts the status back in step with the figure, which is the part worth knowing
about in advance: an asset whose stored total had overstated what was booked is
marked *fully depreciated* and will never be depreciated again, and the repair
returns it to *active* with life left in it.

## Charge to come

The next twelve months of depreciation, projected — **nothing is booked by opening
this report.**

The projection uses the same arithmetic that posting uses, so what it shows is what
will actually be charged:

- **Straight line** — cost less salvage, spread over the useful life.
- **Declining balance** — twice the straight-line rate applied to book value, so the
  charge falls each month.
- Either way it stops when the depreciable amount runs out. An asset with nothing
  left to charge shows a dash.

**It starts at the month of the report date, unless depreciation has already been
booked past it** — then it starts after the last month booked. Depreciation is run
by hand from the register, so a month gets missed; a missed month stays in "still to
come" instead of falling between a total that never included it and a forecast that
starts after it.

## Where the figures come from

**Cost** is the purchase cost on the register, unchanged by depreciation.

**Only posted journal entries** count towards the account figures — the same rule
the Trial Balance and Balance Sheet follow, so all three agree.

**Disposals net off.** A disposal debits 1500 to clear what had accumulated, so an
asset disposed of before the date drops out of both this report and the account
balance together.

## Roles and permissions

Requires `ReportView`, gated behind the Accounting module being enabled for the
company. Read-only — nothing here posts depreciation, disposes of an asset or
changes a register figure. Depreciation is still run from the asset register itself.
