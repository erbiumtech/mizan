## The fleet

Every machine you charge to a job — owned or hired. Excavators, cranes, dumpers,
compressors, the pickup that never leaves site.

## Owned or hired is the important field

It decides whether that machine's logs put cost on a job.

**Owned.** There is no invoice for your own excavator, so the log *is* the cost.
Approving a log charges the job **internal hire** at the machine's rate, and that
charge is credited to a Plant Internal Hire Recovery account — against which the
machine's depreciation, fuel, repairs and operator accumulate.

That credit is the whole point. Debit the job with no credit anywhere and the
fleet looks free while every job looks expensive, and nothing anywhere reports an
error.

**Hired**, and **hired with operator.** The supplier's invoice is the cost, and it
reaches the job through the ordinary chain — order, invoice, allocation. So a log
for a hired machine books **nothing**. It exists to be the check against that
invoice, which is the *Check against invoices* action on the row.

## Checking a hire invoice <!-- requires: ConstructionPlantView -->

Link the **hire order** on the machine's record, then use *Check against
invoices*. It compares:

- what the approved logs say the days were worth, at the agreed rates
- against everything invoiced and allocated to that order

The comparison is **cumulative over the life of the hire**, deliberately. A hire
invoice covers a month and is dated after it; the logs are dated within it.
Comparing a single month would show a difference on every machine every month, and
a report that always shows a difference is a report nobody reads.

> The failure this catches is the classic one: **plant billed after it was
> collected.** Nobody notices, because the invoice looks like last month's and the
> person approving it recognises the supplier's name.

A hired machine with no order linked is listed by the *Hired, no order linked*
filter — until you link one, that supplier's invoices can be paid with nothing
saying whether the days add up.

## Rates <!-- requires: ConstructionPlantUpdate -->

Three of them, because plant is charged three ways: **working**, **idle** and
**standby**. Every hire agreement in the industry distinguishes them, and one
blended rate loses the breakdown that answers "what did we pay for a crane to
stand still".

**A blank rate means those units are not charged at all.** That is a real choice
and the log says so on the row — it does not quietly fall back to the working
rate, because that would inflate every job that ever had a machine standing.

A machine with no rate at all cannot have its logs approved. That is deliberate:
a machine charged at nothing makes the job look cheap and the fleet look free.

> Unlike labour rates, plant rates are **not** dated and cannot vary by job. A
> revision re-prices outstanding drafts and leaves approved logs alone — the log
> freezes the rates it was approved at. If you need a rate to change on a future
> date or to differ per job, that is a change to the system rather than something
> to work around here.

## The fixed asset link

Optional, and worth filling in for owned plant: it is what lets the depreciation
your internal hire charges are recovering be measured against the recovery those
charges produce. Without it the recovery account has nothing to be compared with.

## There is no delete

A machine with cost against it is a row somebody will ask about. Switch it off
with **In the fleet** instead — it comes out of the pickers and the record and its
logs stay.
