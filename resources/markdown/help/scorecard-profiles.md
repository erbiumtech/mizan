## What this is

How a designation's score is weighted.

One profile per designation, not per person. A company setting it per person would be
setting it forty times and arguing about it forty times.

## The weights <!-- requires: ReviewUpdate -->

One means "as the ledger recorded it". Two counts it double. Nought ignores it.

Negative points are weighted too, which is the half people forget: a job where
lateness matters more is a job where a late mark costs more, and setting the late-mark
weight to two is how you say so.

Each weight says underneath whether it counts as **quality** or **volume** on the
scorecard. That grouping is fixed — daily reports and badges are quality, tasks and
late marks are volume — because it is what makes the two columns mean anything.

## A designation with no profile

Counts everything once. That is not a broken state: it is the merit ledger as it was
before any of this, and the Scorecards report counts those people on its own tile so
you can see who is in it.

## Switching one off

**In use** off leaves the profile and stops it applying. The designation falls back to
unweighted, and what you wrote is still there when you switch it on again.

## Roles and permissions

`ReviewView` to read, `ReviewUpdate` to change — whoever decides how somebody is
appraised is whoever decides what their week is worth.
