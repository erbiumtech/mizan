## What this is

Deals: what you are trying to win, what it is worth, and where it has got to.

## A deal is with a lead OR a customer <!-- requires: OpportunityView -->

Never both — except in one case.

- **New business** starts against a **lead**. That is somebody you are pitching to who is
  not yet a customer.
- **A repeat deal** goes against a **customer**, somebody who already buys from you.
- Once a lead **converts**, the deal holds both: the customer it became, and the lead it
  came from. The lead is kept on purpose — "where did this customer come from" is a question
  asked years later, and it is the only reason win rate by source can be worked out.

Anything else is refused, because two answers to "who is this deal with" means the pipeline
and the invoice will eventually disagree.

A company without the Invoicing module works entirely in leads, and the customer picker
simply is not there.

## Moving a deal <!-- requires: OpportunityUpdate -->

**Move** records the change *and* how long the deal sat where it was. Moves **backwards are
recorded too**, not overwritten — a deal that went to Proposal, back to Qualification and
forward again spent real time in each, and a system that kept only its latest position would
report the round trip as one fast passage.

Dragging a deal into a stage marked *won* or *lost* closes it, because that is obviously what
it means.

**Likely to close** comes from the stage. Override it on a deal you know better about, and
the override survives later moves — the stage's figure only applies while the deal was still
following it.

## Winning a deal changes nothing else <!-- requires: OpportunityClose -->

**It raises no invoice and posts nothing to the accounts.**

That is deliberate and worth understanding. A won deal is a sales fact; an invoice is a legal
document. Since FBR digital invoicing, a reported sales-tax invoice cannot be freely
cancelled after 72 hours — so an invoice raised automatically by a button press would be
something nobody can take back.

Raise the invoice, or open the project, from the deal when you are ready. Each is a separate,
deliberate step.

**Losing a deal needs a reason from the list.** Win rate *by reason* is the most useful thing
this module produces, and free text would give you "price", "Price" and "too expensive" as
three reasons with three rates, none of them right.

## The Next column <!-- requires: OpportunityView -->

The one column that tells you to do something rather than describing what happened.

**An open deal with nothing planned shows in red.** That is the single most useful signal
here: it is a deal somebody is thinking about and has not decided a next step for, and it is
usually how deals are lost.

Use **Plan the next step** on any deal to fix it.

## Currency

A deal in another currency stores **the rate used**, with the deal. Deliberately not today's
rate: a forecast that read the live rate would rewrite last quarter's numbers every morning.

## Roles and permissions

**View**: `OpportunityView` — your own deals and your reports'. **Create / update**:
`OpportunityCreate`, `OpportunityUpdate` — which also covers logging calls and planning next
steps. **Win or lose**: `OpportunityClose`, separate because it is what targets are measured
on. **Delete**: `OpportunityDelete`, and not once a deal is closed — win/loss has already
counted it.
