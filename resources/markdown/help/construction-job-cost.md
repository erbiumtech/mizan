## What the job-cost ledger is

Every cost that lands on a job: labour, material, plant, subcontract and
everything else. It's a **separate ledger from your accounts**, and that's
deliberate — it holds three things the general ledger can't:

- **Quantities and unit rates.** "We poured 90 m³ at 2,000 a metre" is the
  figure you price the next tender from, and it can't be recovered from money
  alone.
- **Costs that aren't accounting events.** Burden charged at a rate, internal
  plant at an hourly rate, a notional comparison. Some post to the accounts as
  recoveries; some deliberately never post at all.
- **Its own calendar.** A job runs three years and reports monthly against a
  valuation date. Your accounts close on a fiscal year. They're different clocks.

The price of that separation is that nothing forces the two to agree, which is
why the reconciliation report exists.

## Recording cost <!-- requires: ConstructionCostCreate -->

**New**, then pick the job and the cost code. Only **bookable** codes appear — a
heading with codes under it can't carry cost, because it would be counted twice
in every rolled-up total.

Record the **quantity** wherever there is one. The unit rate is the whole of cost
control, and the report can only show it if the quantity is there.

**Amount** is one signed figure. A credit is negative — there's no separate debit
and credit column, which avoids the "which column does a credit note go in"
question entirely.

### Incurred on, and what happens if its month is closed

Put the **real date**. If that month has already been closed, the cost lands in
the current open period instead, keeps its real date, and is flagged **late**.

That's not a workaround — it's the point. Reopening a signed-off month would
invalidate the certificate, the WIP figure and the accounts summary that were all
built on that month's total. The **Late costs** filter on the register shows
everything that arrived after its month was signed off.

### GL treatment

Which relationship this cost has to your accounts. It's recorded explicitly
rather than guessed at:

| | |
|---|---|
| **Pending** | should reach the accounts and hasn't yet |
| **Mirrored** | the accounts posting is the source and this copies it |
| **Posted** | this is the source and it has reached the accounts |
| **Memo** | deliberately never posts — burden at a rate, a notional figure |

Pending and Memo would look identical if this were left blank, and that's exactly
how a sub-ledger drifts for a year without anyone noticing.

## Correcting a mistake <!-- requires: ConstructionCostUpdate, ConstructionCostReverse -->

**While the period is open and the cost hasn't reached your accounts**, just edit
it. Forcing a reversal pair for a typo made ten seconds ago leaves three rows
where one is true, and a report full of ±5,000 pairs is unreadable.

**Once the period is closed or the cost has posted**, the row is fixed and the
only correction is **Reverse** — which adds a negative row pointing back at the
original. Both stay on the ledger, so anyone can see what was thought at the time
and when it changed. You'll be asked why; that note is read months later by
whoever asks how the figure moved.

A reversal can't itself be reversed, and nothing can be reversed twice.

## The job cost report

What the job has cost, per cost code, with the unit rate. It's computed live from
the ledger every time you open it — there's no snapshot to go stale.

It **rolls up sub-jobs**, so a development shows its towers included and a tower
on its own shows just itself. That's how you get the board's consolidated figure
and the per-lot certificate figure from the same place.

Pick a **period** for one month, or leave it blank for the whole job to date.

> A unit rate shows as **—** rather than 0.00 when no quantity was recorded. A
> rate of zero would read as free work.

Budget, committed and forecast — the other three columns — arrive with the budget
and procurement features.

## Materials on site

Delivered, costed, and not yet used — what the job's store is holding right now, at
what it cost.

It appears only for a job that keeps a **site store**. A job buying everything
direct to the work face has no store and no section, because there is no moment at
which its material is on site and unconsumed.

**It is the part of *actual* that has not been used yet**, which is why it belongs on
this page. A code showing 5,000,000 spent where 3,000,000 of it is still stacked by
the gate looks further through its budget than the work is, and nothing else here
would tell you.

It is grouped by **the code the material was received against**, because until it is
issued that is where the cost still sits — issuing is what moves it to the code the
material was used on. Stock that cannot be traced back to a delivery gets its own
row rather than being folded into a code: a figure that is right in total and wrong
in every breakdown is the hardest kind to notice.

This is what the material **cost**. What may be *claimed* for materials on site on a
payment certificate is a separate, contractual assessment at contract rates.
