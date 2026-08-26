![Reports](/images/help/reports.png)

## What this is

One door to every report in the app, grouped into five sections — Financial
statements, Receivables & payables, Payroll & tax, Ledgers & books, and Bank
files. Each link only appears if the underlying report's own module is
licensed and enabled, and the signed-in role has permission to open it — a
report you can't use is left off the hub entirely rather than shown and
refused.

Open any report's own help (the "Help" action on that report's page) for how
that specific report works — this hub is just the directory.

## Exporting what you are looking at

**Export CSV** and **Export PDF** apply to whichever report is open in the pane, with
whatever date and filters you have set. Both buttons also appear on each report's own
page, so it does not matter which door you came through.

The **CSV is for computing with**, and it deliberately undoes the formatting the screen
applies: thousands separators come off the number columns and a dash becomes an empty
cell, so a column you exported in order to total arrives as numbers rather than as
fifty pieces of text. Percentages keep their `%` and stay text — dropping the symbol
would quietly turn 75% into 75.

The **PDF keeps the formatting**, because a PDF is for reading. Anything the screen has
to scroll sideways is printed landscape.

Both files are named for the report and the date — `aged-receivables-2027-06-30.csv` —
because two exports of one report at two dates is the commonest pair of files anybody
has in a Downloads folder.

Text that begins with `=`, `+`, `-` or `@` is prefixed with an apostrophe in the CSV, so
a spreadsheet shows it instead of running it. Numbers are left alone, so a negative
figure still reads as a negative figure.

The three **bank file** reports have no export: their screen describes a file rather
than containing one, and the file itself is the download button already on that page.
A report with no rows for the period has nothing to export either, so the buttons are
hidden rather than producing an empty file.

## Saved views

Set the filters you use every month, type a name in **Save these filters as…** and press
Enter. The name appears as a chip you can click to put those filters back, with a × to
forget it. Saving over a name replaces it, so adjusting last month's view and pressing
save again is an edit rather than a duplicate.

**A saved view does not remember the date, and that is deliberate.** What you use every
month is the *filters*; the date is the thing that changes every month. A view holding
30 June would keep opening on 30 June and you would not notice for a while.

For a fixed date there is a better tool and it is already there: **the URL carries the
whole state**, so "the balance sheet at 30 June" is a link you can send or bookmark. A
link for a moment, a saved view for a habit.

Saved views are **yours** — nobody else sees them — and belong to one report each.

They only appear on reports that have something to save: the three statements (which have
a comparison basis) and the five with a picker — Account Register, Find Transactions,
Budget vs Actual, Tax Summary and Petty Cash Book. The other forty-three have a date and
nothing else, and offering to remember nothing would be a button that does nothing.

## Keyboard

With 51 reports, the list is quicker from the keyboard:

- **↑ ↓** move through the visible reports.
- **Enter** or **Space** opens the highlighted one.
- **Home** / **End** jump to the first and last.
- **Typing anything** goes into the search box, wherever you are in the list — so *type
  a few letters, arrow down, Enter* is the fast path.
- **↓ from the search box** steps into the list; **↑ off the first row** goes back to it.
- **Escape** in the search box clears it.

Section chips and the report itself are reachable by **Tab**, as normal.

## Comparing against another period

The three statements — Balance Sheet, Profit & Loss, Cash Flow — carry a **comparison
picker**: previous year, previous quarter, previous month, or none.

**On a Profit & Loss or a Cash Flow, choosing a month or a quarter narrows what you are
looking at.** Pick *vs previous month* on the P&L and you get February against January,
not the year to date against a year to date shifted back thirty days. That second thing
would be two overlapping eight-month spans whose difference is mostly the same trading
counted twice — a figure that looks plausible and means nothing. The subtitle always
states the period actually being shown.

The comparison is against the **whole** previous month or quarter, even when the current
one is only part-way through. What a month is worth is what the month came to.

**A Balance Sheet is different, and simpler**: it is a balance on a date rather than a
period, so the current column never changes and only the comparison date moves.

**There is no "vs budget"**, on purpose. *Budget vs Actual* is already that report, per
account, with Planned, Actual and the variance and its own budget picker — and two
places computing one comparison is how they come to disagree.

Old links still work: a saved `?comparison=0` still means no comparison.

## Negatives in parentheses

Company Settings → **Reports** has one switch: *Show negatives in parentheses*.
Accountants read `(1,250)` rather than `-1,250`, and turning it on rewrites every
negative figure on every report, on screen and in the PDF.

**Off by default**, so nothing changes for anybody who has not asked. And it never
applies to the **CSV** — a spreadsheet reads `(1,250)` as text, so the one file you
open in order to do arithmetic keeps the minus sign whatever the setting says.

Only figures are rewritten. A dash still means "does not apply", a date is still a
date, and the sentence under each report keeps its wording.

## Building a report of your own

**New report** in the header opens a form at the top of the pane, and what you assemble
is drawn underneath it as you go — that preview *is* the report, by the same renderer
that draws every built-in one, so there is nothing to check afterwards.

You choose:

- **a subject** — invoices, payslips, journal lines, employees, tickets, and so on. The
  list is what this company has licensed and what your role may open;
- **columns**, in the order you pick them. The ↑ and ↓ on each chosen column move it;
- **a period**, as a *relative* span: "last month", "this quarter", "financial year to
  date". Never two fixed dates — a report filed for a habit has to resolve its own dates
  each time it is read, and a fixed range would answer last quarter's question for ever.
  A few subjects have no date to bound (an employee is a state, not an event) and say so;
- **filters** the subject offers, and a **group by** with **totals per group**.

A saved report appears in the hub under **Custom**, beside the coded ones, and exports to
CSV and PDF like anything else here.

**One subject per report.** A question that needs two joined — invoices *and* payslips,
tickets *and* timesheets — is a coded report: ask for it, and it arrives with tests and a
total that reconciles. The builder refuses rather than guessing at a join.

**At most 1,000 rows.** Past that the report says so and draws nothing, rather than
showing you the first thousand with a total underneath that belongs to all of them.
Narrow the period, or add a filter.

Some columns can be listed but not totalled — an invoice's *outstanding* is worked out
per row rather than stored, so the database cannot add it up. The rows are there; for the
total, the coded ageing reports are the answer.

## Roles and permissions

There's no permission of its own for the hub: this page shows up for anyone who could
open at least one report behind it, and disappears entirely if none apply.

Building has two:

- **`ReportBuild`** — create and edit your own reports. Accountant and upward.
- **`ReportShare`** — tick *Share with everybody in this company*. Administrator only.
  What it protects is the company's own list of reports rather than the figures: a shared
  report resolves its subject through the *reader's* licence and permissions, so it can
  never show somebody rows they could not already open.

You edit and delete your own reports. A report somebody shared with you opens like any
other and is theirs to change — build your own if you want it slightly different.
