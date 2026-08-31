# P&L by Dimension

## What this is

The same profit and loss as the statement, split by **project** or by **department** — and, honestly, by
what could not be split.

Pick the dimension in the filter bar. The figures are the posted income and expense for the financial year
to date, exactly as *Profit & Loss* reports them: the two reports total to the same number, always. If they
ever disagree, one of them has a bug.

## Where the split comes from

This application has no cost-centre column on ledger lines and deliberately does not want one. Instead each
posting records **the document that produced it**, and the document knows its own dimensions:

- an **invoice** knows its project and its customer;
- a **payslip** knows its employee, and therefore their department;
- a **payment** knows who it was paid to.

So a project's profit is its invoices' income less the costs of the documents attached to it, read straight
off the ledger rather than reconstructed from the source documents.

## Unassigned is a real row

Some postings have no document behind them and never will:

- a **manual journal entry** — an accrual, a provision, a correction. A person typed it; nothing produced
  it;
- **stock movements** and **petty cash** — real documents that know no project and no department;
- anything posted **before** attribution existed in this application.

Those land in **Unassigned**, which is shown as its own row and is never folded into a project's total. An
incomplete split that looks complete is worse than no split at all.

The sentence under the figures tells you what proportion of the movement is unattributed. Under about 5% the
report is a management tool; over about half it is a report about your invoices with everything else in one
bucket.

## Attributing history

Postings made from now on are attributed automatically. For everything already in the ledger, run:

```
php artisan accounting:backfill-entry-sources --dry-run
```

It reports what it would attribute, and without `--dry-run` it attributes it. It only ever uses a document
that points at an entry by id — invoices, payments, petty cash vouchers, stock movements and fixed assets
all do. It never guesses from a memo or an amount, so what it leaves unassigned is genuinely unassignable.

## Roles and permissions

`ReportView`, like every other report.
