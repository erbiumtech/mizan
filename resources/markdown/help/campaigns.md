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

## How it goes out <!-- requires: CampaignSend -->

**Send** hands the campaign to the queue, which sends it in batches at the pace set under
**Company Settings → Customers → Campaigns** — fifty a minute unless you change it. A campaign
nobody checked the audience of is checked first, so Send never sends to nobody.

Email goes through the company's mail settings with your subject and body; a blank line starts a
paragraph and `{name}` becomes each person's name. WhatsApp sends the approved template, with the
body's lines as its variables, `{{1}}` first — never free text. A WhatsApp provider that cannot
send templates fails each recipient with that reason rather than sending anything else.

The **Recipients** tab shows every person: sent, failed with the provider's reason, or skipped for
consent. If sending stops part-way the campaign shows **Paused**: **Resume sending** carries on
with whoever is still waiting, and nobody is sent the same message twice. **Retry failed** puts
the failures back in the queue once you have fixed what they failed on.

## Who it goes to <!-- requires: CampaignView -->

**Segments** are the lists a campaign picks from. Consent is recorded on the lead or the customer
themselves, on their **Consent** tab: record an agreement with where it came from, or a
withdrawal when they ask to stop.

## Roles and permissions

**View**: `CampaignView`. **Create / update**: `CampaignCreate`, `CampaignUpdate` — which
includes checking an audience. **Send**: `CampaignSend`, which is deliberately not the same
grant. **Delete**: `CampaignDelete`, and never a campaign already sent.

Consent records can never be edited or deleted by anybody. Evidence that can be rewritten is not
evidence.
