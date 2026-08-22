## What a plant log is

One machine, one day. How many units it worked, stood idle, or sat on standby —
plus the meter, the fuel and who was operating.

Logging is site's job: the only people who know whether the excavator was digging
or waiting are the people who were there.

## Working, idle, standby

Three fields, not one, because plant is charged three ways and every hire
agreement distinguishes them.

A machine that dug for six hours and waited two is **six working and two idle** —
not eight of anything. Blend them and you lose the figure that answers "what did
we pay for a crane to stand still", which is one of the most recoverable costs on
a job: standing plant is very often somebody else's fault, and the *Why it stood*
field is what makes that arguable later.

The *Stood idle or on standby* filter totals it.

## Approving <!-- requires: ConstructionPlantApprove -->

Approving freezes the machine's current rates onto the log and prices it. What
happens next depends on the machine:

| Machine | What approving does |
|---|---|
| **Owned** | Charges the job internal hire, as a cost entry. The recovery account is credited with it. |
| **Hired** | Books **no cost**. The figure becomes what the supplier's invoice gets checked against. |

The *Books cost* column says which a row is. It matters: a hired machine's cost
already arrives through its supplier invoice, so booking the log as well would
charge the job twice for the same excavator.

A machine with no rate set cannot be approved. The form says so before you get
that far.

## The meter

**Evidence, not the basis of the charge.** A machine's engine hours legitimately
differ from its charged hours — warming up, travelling between faces, an operator
leaving it running through lunch — so a mismatch is never refused.

A closing reading **lower** than the opening one is refused, because a meter
cannot go backwards. Either a reading is mistyped, or the instrument was replaced,
and a replaced meter needs somebody to say so.

## Correcting a log

While it is a **draft**: edit it or delete it.

Once **approved**: reverse it with a reason. Any cost it booked is backed out and
the original rows stay on the ledger. Reversing needs
`ConstructionCostReverse`, the grant that governs backing any posted cost entry
out of the ledger.
