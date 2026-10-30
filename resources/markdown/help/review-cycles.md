## What this is

An appraisal round: a period, its deadlines, and a review for everybody in it.

## Raising reviews <!-- requires: ReviewUpdate -->

**Raise reviews** creates one for every active employee, with their current manager as
reviewer. Running it again adds only what is missing, so a new joiner mid-cycle is picked
up by running it a second time.

The reviewer is **stamped**, not looked up later. Reporting lines move, and a review from
March should keep saying who was actually asked to write it.

## The self-review <!-- requires: ReviewView -->

**Ask each employee for a self-review** is on unless you switch it off. While the cycle is
**Open**, each employee rates themselves under My Review and submits; their reviewer is told and
sees the two sets of ratings side by side. Moving the cycle on to Calibrating closes the
employee's half. Switch it off for a cycle that is the manager's alone — a probation check, say.

## The period is not decoration <!-- requires: ReviewView -->

The monthly progress reports filed inside it are read as **evidence** on each review.

They are read, never copied. An MPR is the employee's own account of their month, and
duplicating it into the review would create a second version that could drift from what
they actually wrote. Nobody is asked to write the same thing twice.

Without the MPR module a cycle simply has no evidence attached, which is where every
company was before.

## Roles and permissions

**View**: `ReviewView`. **Create / update / delete**: `ReviewCreate`, `ReviewUpdate`,
`ReviewDelete`.
