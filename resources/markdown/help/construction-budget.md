## What a job budget is here

A **version**, not a number. Every job carries a history of them: the tender, the
budget at contract award, then a revision for each batch of variations. Old
versions are kept, because "what did we think the budget was before VO-12" is a
question somebody asks in a meeting.

Two of those versions are special, and they are **not the same one**:

- The **current** budget is what the cost report compares actuals to. It moves
  every time a revision is approved.
- The **baseline** is what earned value is measured against. It does **not** move
  once work has been measured.

On a job with no variations they are the same version. The moment a variation is
approved they diverge, and that divergence is the point. If a revision moved the
baseline, every schedule variance ever reported on the job would be
retrospectively wrong and nothing would say so.

## Building one <!-- requires: ConstructionBudgetUpdate -->

**New**, pick the job, name the version. If the job already has a current budget,
the new version **starts as a copy of it** — a revision after variation twelve
differs from the budget before it by a handful of lines, and retyping four hundred
is how a revision comes to disagree with the budget it was meant to revise.

Then add lines against cost codes. Only **bookable** codes appear: a heading with
codes under it can't carry budget, because it would be counted twice in every
rolled-up total.

**Put the quantity and the rate in, not just the amount.** "We budgeted 90 m³ at
2,000 a metre" is what the next tender is priced from, and it cannot be recovered
from a lump sum. The amount fills itself in from quantity × rate and stays
editable, because some lines genuinely are lump sums.

### The month column, and what it costs to leave blank

Each line can say **which month** its budget belongs to. That is what makes
*planned value* computable, and planned value is what schedule variance and SPI
are made of.

Leave it blank and the cost report says **"schedule performance unavailable: the
budget is not time-phased"** rather than showing 0.00. That is deliberate. A
schedule variance of zero reads as *exactly on programme*, which would be the most
reassuring wrong answer this module could give. The **Phased** column on the
version list tells you which way a version is.

## Approving <!-- requires: ConstructionBudgetApprove -->

**Approve** makes the version the current budget. The version it replaces becomes
*superseded* and is kept.

A version with **no lines cannot be approved** — an empty budget approved as
current reads as a job with no budget at all, which makes every variance on the
cost report equal to the spend.

Once approved, a version is not edited. A change to the budget is a **Revise**,
which opens a new draft from it. This is not bureaucracy: a variance measured
against a budget somebody edited last Tuesday means nothing.

## Setting the baseline <!-- requires: ConstructionBudgetBaseline -->

**Set baseline** fixes what earned value on this job is measured against, for the
life of the job.

Once any progress has been measured, this is **refused**. Every earned-value
figure taken so far was frozen at the moment it was measured, against the budget
as it stood then, precisely so a later budget change could not restate it. Moving
the baseline underneath those figures would restate all of them.

If the budget has genuinely changed, approve the new version as **current** — the
cost report reads the current budget, so the variance column follows it — and
leave the baseline where it is so earned value stays comparable month to month.

## Who does what

| | Surveyor / commercial | Manager | CEO |
|---|---|---|---|
| Build and price a version | ✓ | ✓ | ✓ |
| Approve it as current | | ✓ | ✓ |
| Set the baseline | | | ✓ |

Three permissions rather than one because they are three decisions taken by three
people. `ConstructionBudgetUpdate` prices it, `ConstructionBudgetApprove` makes it
the budget the job reports against, and `ConstructionBudgetBaseline` decides what
every earned-value figure on the job is measured against.

Everyone with `ConstructionBudgetView` can read a budget, including the site team
— a figure people can't see is a figure they can't work to.
