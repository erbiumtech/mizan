## What this is

What somebody is owed, or owes, when they leave.

## It is a proposal, not a payment <!-- requires: SettlementView -->

**Nothing on this screen pays anybody, and nothing posts to the accounts.**

It gathers what the system already knows and produces a figure for a person to check.
Approving records that the figure was agreed. **Paying it goes through a payslip or a
payment, exactly as any other money does.**

That separation is deliberate. A second path writing its own journal entries is how a
ledger stops reconciling, and a settlement is precisely the kind of one-off that would
be tempting to post directly.

## What it gathers <!-- requires: SettlementCreate -->

**Build a settlement** collects:

- **Encashable leave** — the current year's unused balance on leave types marked
  encashable, valued at a day's basic pay. Only on separation: leave that lapses at the
  year end pays nobody, which is a different thing entirely.
- **Gratuity** — one month's wage per completed year of service as shipped. A
  **convention, not a statement of law**: entitlement and formula vary by establishment
  and province, and both the multiplier and the qualifying period are settings. Confirm
  what applies where you operate.
- **Unrecovered advance** — what is still owed, added up from the recoveries rather than
  from a stored balance.
- **Unreturned kit** — what the asset register says is still out, at its recorded value.

## What it deliberately does not gather

**Notice not served** and **other deductions** are always blank and always yours to
enter. Whether notice was served, and what to do about it, is a judgement somebody makes
— a number this screen invented would look like a fact.

## A negative settlement is allowed <!-- requires: SettlementView -->

Somebody leaving with an unrecovered advance and an unreturned laptop may owe the
company. The figure is shown as it falls rather than clamped to zero, because clamping
quietly writes off a debt nobody decided to write off.

## Recalculating and approving <!-- requires: SettlementApprove -->

**Recalculate** gathers everything again, and only while the settlement is a draft. An
approved settlement is a figure somebody committed to; rebuilding it from today's data
would move what was agreed — an advance balance changes as recoveries post.

**Nobody may approve their own settlement.** This is the one place in the application
where approving is literally money leaving the company to the person pressing the
button.

## Roles and permissions

**View**: `SettlementView`. **Build and edit**: `SettlementCreate`, `SettlementUpdate` —
drafts only. **Approve**: `SettlementApprove`. **Delete**: `SettlementDelete`, drafts
only. The Employee role holds none of these.
