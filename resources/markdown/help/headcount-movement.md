## What this shows

Month by month across the financial year to date: who joined, who left, the
headcount at the end of the month, the turnover rate, and how long the people who
left had been there.

## Joiners and tenure read different columns, on purpose

**Joiners** come from the joining date. A month's joiners is a fact about that
month, and somebody re-employed after a break has joined again — they walked back
through the door.

**Average tenure** is *continuous* service, measured from the first job-history
record rather than the original joining date. That is the same rule a final
settlement uses, and for the same reason: somebody re-employed after a break has two
spans of service and only the current one counts. Measuring from the original
joining date would quietly credit the company for the gap.

Where somebody has no job history at all — an imported employee — tenure falls back
to their joining date. Where neither is known, they are left out of the average
rather than counted as nought years, which would drag the figure down for a missing
record rather than a short career.

## Turnover

Leavers as a proportion of the **average** headcount for the month — the average of
the opening and closing figures.

That is the conventional formula and the only one that behaves at both ends. Against
opening headcount, a company that halved would report a rate below its real one.
Against closing, it would report an absurdly high one — and a month that ended with
nobody left would divide by zero.

A dash means the average headcount was nought. That is a company with nobody in it,
not nought per cent turnover.

## Reading it

The period is the **financial year to date** — 1 July to your date, not 1 January.

**Headcount** is the figure at the end of each month: joined on or before it, and
either still there or left after it. Somebody who joined and left in the same month
appears in both the joiner and leaver columns, which is what keeps those two
consistent with the headcount between them.

A month where nothing happened shows dashes rather than noughts, so the months that
moved stand out.

The note gives the net change across the whole period, because a company that grew
by ten while losing eight has a story that neither figure tells alone.

## Roles and permissions

Requires `ReportView`, gated behind the Employees module being enabled. Read-only —
nothing here hires, terminates or amends a record.
