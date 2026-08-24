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

## Roles and permissions

There's no permission of its own: this page shows up for anyone who could
open at least one report behind it, and disappears entirely if none apply.
