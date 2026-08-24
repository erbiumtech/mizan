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

## Roles and permissions

There's no permission of its own: this page shows up for anyone who could
open at least one report behind it, and disappears entirely if none apply.
