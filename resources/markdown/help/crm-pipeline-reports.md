## What these show

Five reports off one pipeline, and they are deliberately five rather than one
screen with five tabs — each answers a different question and gets read by a
different person on a different day.

**Pipeline by Stage** — what is open right now, grouped by the stage it sits in,
with a count, a value and a weighted value per stage. The report a sales meeting
opens with.

**Sales Forecast** — what is expected to close in the month you are looking at,
weighted at each deal's own probability. Open deals only.

**Win / Loss** — what closed in the financial year to date, won against lost, by
source, by owner, and by the reason each loss was recorded under.

**Rotting Deals** — open deals that have stopped moving, or that have nothing
planned against them. A list to act on rather than a figure to read.

**Target Attainment** — every sales target in force on the date, against what has
actually been achieved inside that target's own period.

## Using it

Each report takes one date. On *Pipeline by Stage* and *Rotting Deals* it makes
almost no difference — both are snapshots of what is open now. On the other three
it decides the whole period:

- **Sales Forecast** looks *forward* to the end of the month the date falls in. A
  forecast of a period that has already ended is a Win/Loss report.
- **Win / Loss** looks *back* to the start of the financial year the date falls
  in. That is 1 July here, not 1 January — a win rate measured from January is a
  different half of the year's trading under the same name.
- **Target Attainment** shows the targets that *cover* the date, whatever length
  they are. A quarterly target and a monthly one both appear if both are in
  force.

Every report opens in the Reports explorer as well as on its own page, and shows
the same figures either way — the page and the pane run the same calculation.

## Where the figures come from

**One pipeline.** *Pipeline by Stage* and the stage rows in the forecast read the
pipeline marked as default, and its name is printed on the report so you can see
which. If you run more than one pipeline, this is not the other one.

**Weighted means the deal's stage probability**, multiplied by its value. Both
columns are shown side by side on purpose: the weighted figure is what a forecast
is built from, and the plain one is what is actually on the table.

**A stored exchange rate, never today's.** A deal in another currency is
converted at the rate recorded when it was entered. A report that re-read the
rate each morning would restate last quarter every day.

**Won deals are not forecast.** A won deal is an invoice waiting to be raised, so
counting it as expected revenue would state the same money twice — once here and
once in the invoice.

**A deal is rotting for one of two reasons**, and the report says which: it has
not changed stage for some time, or it has no open next action. The second is
usually the more serious — a deal nobody has planned anything for is not slow, it
is unowned.

**Attainment against a target of nought shows a dash, not 0%.** An unset target
reported as nought per cent reads as somebody who missed, which is the opposite
of what it means.

**A win rate where nothing closed shows a dash too.** No deals closed in a bucket
is not a nought per cent win rate.

## Roles and permissions

Requires `ReportView`, gated behind the CRM module being enabled for the company.
Read-only — nothing on these screens changes a deal, a stage or a target.

These reports cover **every deal in the company**, not only your own. There is no
per-owner filter and no per-owner scoping on them: Win/Loss and Target Attainment
exist precisely to be read across a team, and a report that quietly showed one
person's slice of the pipeline would be a different report with the same title.
If somebody should not see the whole pipeline, they should not have `ReportView`.
