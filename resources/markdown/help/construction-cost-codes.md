## What the cost-code library is

Your own list of **what kind of cost** something is: formwork, rebar fixing,
concrete supply, crane hire, a subcontract package. Every cost recorded against
a job names one of these, and every cost report groups by them.

It is **shared by every job in the company**, and that is the whole point. "What
did formwork to soffits cost us per square metre across the last six jobs" is
the question you price the next tender with, and it becomes unanswerable the
moment each job invents its own codes.

So a job **selects** codes rather than owning them. Opening a budget line
against a code is what puts it on that job, and a job's cost report only shows
the codes it has actually used.

## Getting your codes in

Nothing is pre-loaded, deliberately. MasterFormat belongs to CSI, Uniclass to
NBS, NRM to RICS and ICMS to the ICMS Coalition — we can't redistribute their
code lists, and ten thousand seeded rows you didn't choose is a list nobody ever
prunes.

Use **Settings → Import from CSV → Construction cost codes** and load your own.
The template has every column, and `parent_code` refers to another row by *its*
code — so one file can describe the whole tree and the rows can be in any order.

> A `parent_code` naming a row that isn't in the file and isn't already in the
> library leaves that code at the top level rather than refusing the import. One
> code at the wrong level is a two-second fix; a rejected import of four hundred
> is a morning.

## Cost type <!-- requires: ConstructionCostCodeUpdate -->

One of the standard five — **Labour, Material, Plant, Subcontract, Other**. Every
cost report groups by this before anything else, because "how much of this job is
labour" is the first question anyone asks.

## Headings and bookable codes

A code with children becomes a **heading** and stops being bookable: you can't
record cost against it directly. That's not a restriction for its own sake — a
heading that carried cost *as well as* children would be counted twice in every
rolled-up total, and nothing would tell you.

The **Bookable** column shows which is which.

## Classification — the same money, four ways

You'll be asked for the same money in different shapes in the same month: the
tender came in MasterFormat divisions, the QS measured under NRM, the design team
codes in Uniclass or OmniClass, and the client's cost report has to arrive in
ICMS categories.

Rather than keeping four separate trees — which means classifying every cost four
times and reconciling them monthly — you keep **one tree and map it**. Each code
carries the standard codes as fields, so every report is just a different
grouping and the mapping is maintained once per code instead of once per
transaction.

**All of them are optional.** A code you haven't mapped shows up as a single
countable "unmapped" row in that standard's report — visible and fixable —
never as a silently short total. Use the **Not mapped to…** filters to find the
gaps.

### ICMS

The one worth doing. ICMS 3 fixes six categories at Level 2 — **A**cquisition,
**C**onstruction, **R**enewal, **O**peration, **M**aintenance, **E**nd of life —
and standard cost groups beneath them, specifically so a client can compare this
job with one in another country. Level 4 below that is yours.

## Switching a code off <!-- requires: ConstructionCostCodeUpdate -->

For a genuine one-off — a provisional item that will never recur — clear
**Active**. It stays in the library and its history, and stops appearing in
pickers. This is the intended alternative to inventing a private code on one job.

## Deleting <!-- requires: ConstructionCostCodeDelete -->

A code with children cannot be deleted: removing a heading would take everything
under it. Switch it off instead.
