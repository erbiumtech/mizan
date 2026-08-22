## Retention is a ledger, not a balance

Every row here is something that **happened** to the money withheld: a
certificate held some, a taking-over released half of it, a bond replaced the
rest, an uncorrected defect forfeited a slice.

The balance is the **sum of the rows**. Filter to one contract and the total at
the foot of the amount column is that contract's retention — there is no separate
balance to maintain and none to go stale.

**Positive is held. Negative is released or forfeited.** One convention, and the
colours follow it.

### Why not just work it out from the certificates?

Because four ordinary things cannot be derived from them:

- a **bond** substitutes for cash;
- **sectional taking-over** releases early on part of the works — FIDIC 14.9
  expressly allows it;
- part is **forfeited** against defects nobody corrected;
- the parties agree a **one-off adjustment**.

Those are decisions, not arithmetic. A company that has done one of them and
derives its retention from certificates has a figure nobody can explain at final
account.

## Holding

You do not add a *held* row by hand. Issuing a payment certificate writes it,
carrying the gross it was calculated on, the rate applied, and whether the **limit
of retention** capped it.

That cap column matters: a movement smaller than rate × gross is a question
somebody asks once per job, usually in a meeting, and "the cap was reached" is the
answer.

## Releasing <!-- requires: ConstructionRetentionRelease -->

**Release** offers whatever the contract's release rule says is due:

| Release rule | First | Then |
|---|---|---|
| Two stage (FIDIC default) | the *first release* share at taking-over | the rest at the end of the defects period |
| At substantial completion (AIA default) | the balance, less a punch-list holdback | any remainder at final completion |
| Single stage | the whole balance at practical completion | — |

The rule is a **field on the contract**, not a consequence of the contract family:
a FIDIC contract with a negotiated single-stage release is ordinary, and reading
the family would overrule what the parties agreed.

The end of the defects period is **computed** — completion date plus the period in
days — so an extension of time that moves completion moves the release date with
it. Nothing to remember and nothing to correct.

### Releasing early

Allowed, and it needs a reason. Sectional taking-over is a real thing and so is a
commercial decision to pay early; both belong to somebody, and the row records
who and why.

Releasing more than is held is refused outright.

### The punch-list holdback

Under the AIA rule the first release is the balance **less** a holdback against
open punch items. That figure needs the site operations module.

Without it, the holdback is **zero and the movement says so in words** — "the open
punch value is unknown rather than nil". A silent zero would look exactly like a
job with nothing outstanding, and releasing the whole balance on a job with fifty
open items is money that does not come back.

## Forfeiting and adjusting <!-- requires: ConstructionRetentionRelease -->

**Forfeit or adjust** records the decisions: a forfeit against uncorrected
defects, a bond substituting for cash, an agreed adjustment, a reinstatement.

Each needs a reason, and the field is not a formality — this is the row a final
account argues about. Write the sentence that settles the argument.

## Nothing here is edited or deleted

A movement is an event. The way to correct one is another movement — an
**adjustment** or a **reinstatement** with a reason — which is the same discipline
the job-cost ledger keeps with reversals, and for the same reason: a balance whose
history depends on what somebody deleted is not a ledger.

## The nightly check

`construction:reconcile-retention` compares the *held* rows in this ledger with
what the latest certificate on each contract says is held, and reports where they
disagree.

It **warns and keeps going** rather than failing. A command that failed would stop
running, and a register nobody reconciles is precisely the state the check exists
to find. A difference is a hand-written movement, a voided certificate, or a bug —
and none of the three fixes itself.
