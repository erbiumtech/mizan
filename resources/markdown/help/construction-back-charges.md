## Notice before deduction

A back-charge is work this company did that the subcontract says the
subcontractor should have done: the cleanup nobody came back for, the rework
after an NCR, the crane hours attending somebody else's delivery.

**Almost every subcontract requires notice before it may be deducted.** So a
charge sitting in **Draft** cannot come off a payment, and that is the state worth
watching: an incurred-but-unnotified back-charge is money the company will not
get and does not yet know it has lost.

The *No notice served* filter is that list, and the total at the foot of the
Notified column is what it is worth. The number beside **Back-charges** in the
sidebar is the same count.

## The states

| State | What it means | May it be deducted? |
|---|---|---|
| Draft | Priced, nobody told | **No** |
| Notified | Notice served, on the date on the row | Yes |
| Disputed | The subcontractor contests it | Yes |
| Agreed | Settled, possibly for less | Yes |
| Applied | Deducted on a named certificate | Already has been |
| Withdrawn | Dropped, with a reason | No |

**Disputed is still deductible.** Most subcontracts let the main contractor
deduct a notified charge and send the argument where the contract says arguments
go. Recording the dispute keeps the grounds on the row; it does not stop the
recovery.

## Cost and markup are separate

Because the markup is the part that gets argued about. A subcontractor will accept
an invoice for a skip and contest fifteen per cent on top of it, and a single
total figure gives the argument nowhere to land.

Most subcontracts allow a stated percentage for supervision and overhead. Set it
where yours does.

## Serving notice <!-- requires: ConstructionBackChargeUpdate -->

**Serve notice** asks for one thing: the date the notice went to the
subcontractor. Not the date you are typing it.

Notice served on the 3rd and recorded on the 11th is notice served on the 3rd, and
a system that stamped today's date would quietly move every notice inside the
contractual window it is being measured against.

Notice cannot predate the work, and a charge with no value cannot be notified —
a contractual clock over an amount nobody can respond to is worse than no notice.

## Draft computes, notice freezes

While a charge is a draft, saving it re-derives the total from the cost and the
markup. Once notice is served the total is **what the subcontractor was told**, and
it stops moving — a figure that drifted afterwards would make every notice a
document nobody could rely on.

After notice the only way the number changes is **Agree**, and the settled figure
sits *beside* the notified one rather than over it. "Notified 240,000, settled at
180,000" is the fact somebody needs at final account; one column loses the first
half of it, and with it the reason the account does not add up to the notices.

Agreeing **above** the notified figure is refused. That would be a new charge, and
it needs its own notice — otherwise the subcontractor is deducted for something
they were never told about.

## Deducting it <!-- requires: ConstructionBackChargeApply -->

**Deduct on certificate** writes one line on a draft certificate for that
subcontract, negative by the convention stated once for every deduction: a
negative amount reduces the payment.

**Nothing does this automatically.** The charge *proposes* and somebody applies it.
A deduction appearing on a certificate that nobody decided on is the fastest
available route to a dispute, and it will be this company's dispute, because the
other party's copy has already left the building.

Refused where it would be wrong:

- a **draft** charge — no notice, so nothing may be deducted;
- a charge **already applied** — deducting it twice takes money the subcontractor
  has already lost once;
- a charge on **another subcontract** — that deducts from the wrong party;
- an **issued** certificate — its figures are frozen, so the charge belongs on the
  next one.

**Take off certificate** reverses it while the certificate is still a draft. The
charge returns to notified or agreed, never to draft: notice cannot be unserved,
and a charge sent back to draft would be re-notified with a later date and moved
inside a window it had already left.

## Withdrawing <!-- requires: ConstructionBackChargeApply -->

**Withdraw** writes off the recovery and needs a reason. The row stays on the
register — it is what explains why the final account does not add up to the
notices.

There is no delete. A charge somebody was told about is a fact about the account
whether or not the company still pursues it, which is the same discipline the
retention ledger keeps.

## What this does not do

It does not credit the job's cost report. The cost being charged back was recorded
when the company incurred it — a labour record, a plant log, a material issue —
and the recovery reaches the books through the certificate's invoice.

Whether the job's own cost report should also show the recovery against the code
that carried the cost is a reconciliation question, and it is answered in the
phase that builds the reconciliation. Writing a cost credit from here as well
would post the same money twice with nothing anywhere disagreeing.
