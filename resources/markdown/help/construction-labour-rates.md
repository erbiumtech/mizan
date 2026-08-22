## What this register is

What an hour of labour **costs you**, from a date. Every row has the dates it
applies between, and labour cost is worked out from whichever row applies on the
day the work was done.

This is cost, not charge-out. What time is *billed* at has its own ladder in
Timesheets and is a different number.

## Why rates are rows and not a field on the worker

Because a wage revision effective the first of April must not restate March's job
cost.

Put `cost rate per hour` as a field on the worker and the moment somebody edits
it every historical record recomputes: every closed period's cost changes, every
margin you reported moves, and there is no journal, no audit and nothing anywhere
saying what happened. A register of dated rows cannot do that.

There is a second line of defence as well — a labour record **freezes** the rate
and the burden it was approved at, so even a mistake here cannot reach cost that
has already been booked.

## Scope: what a rate applies to <!-- requires: ConstructionLabourRateSet -->

The four scope fields are all optional, and which ones you fill in is the whole
mechanism. Leave them all blank and you have set the company default.

The most specific row that fits the question wins:

1. **Job + trade** — that trade, on that job
2. **Job** — everybody on that job
3. **Worker or employee** — one person, anywhere
4. **Trade** — that trade, on any job
5. **Nothing filled in** — the company default

The *Applies to* column shows each row's scope, and the *In force* badge shows
which rows are the ones being used today.

> **A job rate beats a person's own rate**, which catches people out. That is
> deliberate: a site allowance applies to everybody working on that site,
> including the people who carry their own rate elsewhere. If you want one person
> paid differently on one job, set a job+trade rate and a person rate is not the
> tool.

## Overtime and burden fall through separately

Leave **Overtime multiplier** or **Burden %** blank and that field alone drops to
the next row down the ladder that does state it, and finally to the company
default (1.5 and 0%).

This is what lets a job carry a site allowance on the rate while still inheriting
the company's overtime terms. If every field had to be restated on every row, one
forgotten multiplier would silently price overtime at plain time.

The register shows *inherited* rather than a dash for these, because a blank here
means "whatever the next rate says" and not "no overtime".

## Two rates for the same scope may not overlap

Saving one is refused, and the message names the row in the way.

If two rates for the same scope were both in force there would be nothing saying
which one the cost report used — and next month the answer might be the other
one. To revise a rate, set the new one from its start date and the one it
supersedes is closed the day before.

## Deleting <!-- requires: ConstructionLabourRateSet -->

Only a rate that has not started yet.

Once a rate has been in force something has probably frozen a figure from it, and
a deleted row leaves that snapshot with nothing to explain it. A rate typed
against the wrong scope and caught the same afternoon is what this allows for;
anything older gets an end date instead.

## Who may set a rate

`ConstructionLabourRateSet`, held by Manager and above — separately from
`ConstructionLabourUpdate`, which maintains the worker and trade registers.

They are different decisions. Filing the six men who turned up on Monday is site
administration. Revising a company-default rate by ten per cent changes the
labour cost of everything booked from that date, on every job at once.

Reading this register rides on `ConstructionLabourView`, which site staff hold —
they are entitled to know what their job is being charged.
