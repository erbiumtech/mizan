## What this is

Who the company is, counted in the dimensions the employee record holds: gender, age,
marital status, department and employment type.

Active employees only, as at the date on the report.

## Five flat counts, not a cross-tabulation

Deliberately. A matrix of gender against department against age band, on a company of
forty people, is mostly ones — and the question a report like this answers is the flat
one: how many of each.

If you need the cross-tabulation, the report builder under Custom reports will build
it from the employee dataset.

## "Not recorded" is a row

Not a blank, and not left out. A demographics report whose numbers do not add up to
the headcount has lost somebody, and the usual reason is a field nobody filled in —
which is itself the thing worth seeing. A company with thirty people and twelve
"Not recorded" genders has a data problem, not a diversity statistic.

## Age bands

Under 25, 25–34, 35–44, 45–54, 55 and over, measured from the date of birth as at the
report date. Somebody with no date of birth recorded is in "Not recorded" rather than
guessed into a band.

## Roles and permissions

`ReportView` and the Employees module. The figures are counts and shares only — no row
names anybody.
