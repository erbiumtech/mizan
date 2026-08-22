## What this is

Sending something to a list of leads or customers.

## Read this part first <!-- requires: CampaignView -->

**This is the only thing in this application that can damage your company's reputation**, and
two rules exist because of it.

### Consent is a record, not a checkbox

Every send checks whether that person has agreed to be contacted on that channel. **No record
means no** — silence is not consent, and somebody nobody has asked has not agreed.

Consent is stored as a history: each time somebody opts in or out, a new row is written. Nothing
is overwritten. That is what lets you answer "who agreed to this, and when" if somebody
complains — and a checkbox never could.

People without consent are **skipped and counted**, not silently dropped. A campaign that
reached nobody looks different from one that was never sent.

### WhatsApp needs an approved template

Meta only permits pre-approved template messages outside a 24-hour customer-service window. A
campaign with free text on that channel does not just fail: **repeated attempts put your number
at risk.**

So a WhatsApp campaign asks for the template name, and refuses to be saved without one.

## Check the audience before you send <!-- requires: CampaignUpdate -->

**Check the audience** shows exactly who would be reached, and how many would be skipped for
consent — and sends nothing.

On a channel that can cost you your number, that is not a nicety. Use it.

A segment is resolved **when you send**, not when you save it, so a campaign reaches everybody
who matches then rather than last month's list.

## Sending <!-- requires: CampaignSend -->

**Consent is checked again as each message goes out**, not trusted from the audience check.
Somebody may unsubscribe in between, and that gap is exactly where a complaint comes from — the
message that went out after they asked you to stop.

Sending is a **separate permission** from creating a campaign. Drafting is a writing task;
sending reaches people outside the company and cannot be undone.

## Roles and permissions

**View**: `CampaignView`. **Create / update**: `CampaignCreate`, `CampaignUpdate` — which
includes checking an audience. **Send**: `CampaignSend`, which is deliberately not the same
grant. **Delete**: `CampaignDelete`, and never a campaign already sent.

Consent records can never be edited or deleted by anybody. Evidence that can be rewritten is not
evidence.
