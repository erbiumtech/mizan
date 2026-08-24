## What this shows

One row per review cycle: how many reviews exist, how many the person has actually
acknowledged, how many goals were set, how many were decided, and how many
one-to-ones were held inside the cycle's own dates.

It answers whether each cycle **finished** — not whether it was started.

## Complete means acknowledged

A review climbs five rungs: pending, self submitted, manager submitted, shared,
acknowledged. Only the last one is a review that finished.

The **Acknowledged** column counts that rung alone. Counting *shared* instead would
report a cycle as done while half the company had not opened their review.

## The findings

**Closed · N never shared.** The sharpest thing on this report. Somebody wrote a
review of a person, the cycle was closed, and the person never saw it. A review is a
draft about somebody until a manager shares it — deliberately, so that drafting can be
honest — and on every other screen in this system a review sitting at *manager
submitted* looks like completed work. This is the only place it does not.

Sharing is judged on the **timestamp**, not on the status label, because the timestamp
is what actually decides whether the person can read their own review. A review whose
status says *shared* with no timestamp is not shared.

**Closed · N goals undecided.** Goals left open past the end of their cycle. Note that
**missed** is a settled state: recording a goal as missed is a decision and counts as
decided. Leaving it open is not a kindness — it means nobody decided, so nothing can
be learned from it.

**No one-to-ones.** The cycle has reviews but no recorded conversations, so those
reviews were written with nothing behind them. Only raised where reviews exist: a
cycle nobody has written in yet has nothing to have talked about.

## The same fact at both ends of a cycle

An unshared review in an **open** or **calibrating** cycle is work in progress and is
not flagged. The identical row in a **closed** cycle is work abandoned. That is why
both closure findings appear only once a cycle is closed — the fact has not changed,
what it means has.

## Using it

The period is the **financial year to date**, and a cycle is included if it
**overlaps** it rather than fitting inside it. A cycle running July to December belongs
in this year's report from the day it starts; one that ended in June belongs to last
year's.

**One-to-ones are counted inside the cycle's own dates**, not the report's. There is no
cycle column on a one-to-one — the only thing tying a conversation to a cycle is the
date falling inside it. Which also means two overlapping cycles will each count the
same conversation, and that is right: it happened during both.

**A goal belonging to no cycle is not counted against any cycle.** Goals can be
standing objectives with no cycle attached, and charging those to whichever cycle
happens to be open would make a cycle answerable for goals nobody set in it.

**Draft cycles do not appear.** A cycle nobody opened has no progress, and a row of
noughts above the live cycles would only be in the way.

A dash in a count column means nought. The table is wider than the pane and scrolls
sideways.

## Roles and permissions

Requires `ReportView`, gated behind the Performance module being enabled. Read-only,
and it shows **counts only** — no ratings, no names, no review content. Sharing a
review, deciding a goal and recording a one-to-one all happen on those records, where
the usual visibility rules apply.
