## What a site sheet is

One person, one day, one cost code. The hours they worked and what it cost the
job.

Recording is site's job — the ganger is the only person who knows who turned up
and what they did. Approving is somebody else's, because approving is what turns
the sheet into money.

## Why minutes

Because a rate multiplied by a rounded decimal of hours drifts visibly across a
month of a hundred men. Seven and a half hours is **450 minutes** and stays 450.

The register shows hours, because that is what a sheet is discussed in. Only the
storage and the arithmetic are in minutes.

Common values: 480 = 8 hours, 450 = 7½, 540 = 9.

## Draft, then approved <!-- requires: ConstructionLabourApprove -->

A draft is time somebody worked that the job **is not yet carrying**. Use the
*Awaiting approval* filter: a queue nobody clears is a job that reports a
flattering cost for as long as the sheets sit there.

Approving does three things at once:

1. Resolves the rate **as at the day worked** — not today. A sheet for last
   Thursday approved on Monday is costed at Thursday's rate.
2. **Freezes** that rate, the overtime multiplier and the burden percentage onto
   the record. Nothing recomputes them afterwards.
3. Writes **two cost entries**: the labour, and the burden as its own entry
   against the same cost code.

That freeze is the second line of defence. The rate table is dated so a wage
revision cannot restate history; the snapshot means even a correction to the rate
row itself cannot reach cost that has already been booked.

## Why burden is a separate entry

So that "labour cost" stays one number and burden stays separable, from one sum
and a filter.

Roll the burden into the labour figure and you can report labour-plus-burden or
nothing; you cannot get back to labour. Two entries give you both, and the burden
one carries a flag saying which it is.

## No rate, no approval

If no rate applies to that worker on that date, approval is refused and the
message says so.

This is deliberate and it is the failure the whole section is arranged around: a
week of labour costing 0.00 looks like a healthy figure and is not. Set at least a
company default under **Labour rates** before entering sheets.

The form shows what today's ladder would produce as a preview, and says plainly
when there is nothing to work with.

## Guards you may hit

| Refusal | Why |
|---|---|
| "was not engaged on that date" | The worker's start or leaving date says they were not there. Usually the wrong person, occasionally the wrong dates. |
| "more than a day" | This worker would end up booked over twenty-four hours for that date. Almost always the same sheet entered twice — check what is already recorded before adding more. |
| "is a heading" | Cost codes with children cannot take cost; they would double-count in every rolled-up total. |
| "record of no time" | Zero minutes would book a cost of nothing against the job. |

Two records for one worker on one day are perfectly normal — morning on formwork,
afternoon on steel. That is why the guard is a ceiling on the total rather than a
one-per-day rule.

## Correcting a sheet

While it is a **draft**: edit it, or delete it.

Once **approved**: reverse it, with a reason. Both cost entries are backed out as
a pair — reversing the labour and leaving the burden would leave the job carrying
burden on work it is no longer charged for. The original rows stay on the ledger,
which is what lets somebody answer "what did we think in March, and when did we
change our mind".

Reversing needs `ConstructionCostReverse`, the same grant that governs backing any
posted cost entry out of the ledger.

## Where the cost goes next

The job cost report picks both entries up immediately.

The general ledger side is not written yet — the entries sit as *pending* until
the posting service runs. For anybody on the payroll, the payslip is already the
ledger's record of their time, so their labour entry is marked as mirroring it
instead. Burden is always pending, because burden charged to jobs has to be
credited to a Labour Burden Absorbed account: charge it and never absorb it and
job cost exceeds ledger cost by exactly the burden, growing every month, with
nothing reporting an error.
