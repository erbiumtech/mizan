## What this is

Prospects: people and companies you are trying to win, before they are customers.

A lead is **not** a customer record. Customers live under Contacts, in Invoicing, and
are what the ledger bills. Keeping the two apart is deliberate — a prospect is
somebody you cannot invoice yet, and mixing them would mean every invoice screen
having to filter out people who never bought anything.

## Adding a lead <!-- requires: LeadCreate -->

A lead is **an organisation with a person attached**. Either half may be blank — a
company name from an expo with no contact yet, or a name and a number with no company
— but not both, because a lead with neither is one nobody can follow up.

**WhatsApp** is asked for separately from the phone number on purpose: the number
people actually answer here is often not the one on the card.

**Source** is worth filling in every time. Win rate by source is the report this whole
thing exists to produce, and a lead with no source contributes nothing to it.

**Owner** is who is working it, and it is also what decides who can see it — you see
your own leads and, if people report to you, theirs.

**Rating** is your own read on how good the lead is. Nothing computes it, and nothing
should: an unlabelled score in a table becomes a decision the moment somebody sorts by
it.

## Estimated value

A guess, and treated as one. It is here so a list can be sorted by size, not so that
adding it up produces a forecast. Proper weighted forecasting needs deals and pipeline
stages, which come later.

## Converting to a customer <!-- requires: LeadConvert -->

**Convert to customer** creates the customer record — and does nothing else.

It does **not** raise an invoice, and it does not post anything to the accounts.
Winning work is a sales fact; an invoice is a legal document that somebody raises
deliberately. That matters more than it used to: once a sales-tax invoice has been
reported to FBR it cannot be freely cancelled after 72 hours, so an invoice created
automatically by a button press is not something that can simply be undone.

You are asked what the ledger should **call** them — usually the company; for a sole
trader, the person — and optionally their **NTN**, which is needed before a sales-tax
invoice can be reported.

**The lead stays.** It is marked converted and points at the customer it became,
because "where did this customer come from" is a question asked years later, and it is
the only reason win rate by source can be worked out at all. A converted lead can no
longer be edited: correct the customer record instead, since that is the one the
ledger uses.

**Without the Invoicing module this action is absent**, not broken. There is nothing
for a lead to become.

## Marking a lead lost <!-- requires: LeadUpdate -->

**Mark lost** requires a reason, and the reason is not optional politeness — win and
loss *by reason* is worth more than any forecast, and a blank reason contributes
nothing.

**Reopen** puts a lost lead back in play and clears the reason. Deals do come back.

Converted and lost are not options in the status dropdown, deliberately. Both are
actions, because both need to record *when* they happened and *why*, and a dropdown
would let somebody set "lost" with neither.

## What is not here yet

This is the first phase. Still to come: pipelines and deals with stages, logged calls
and meetings, **next actions** — the part that decides what happens tomorrow rather
than recording what happened yesterday — win/loss and forecast reports, and a web form
that creates leads without anybody typing them.

## Roles and permissions

**View**: `LeadView` — your own and your reports'. **Create**: `LeadCreate`.
**Update**: `LeadUpdate`, and not once a lead is converted. **Delete**: `LeadDelete`,
likewise. **Convert**: `LeadConvert` — its own permission, because working a lead is
not the same decision as creating a customer the ledger can bill.
