## What this is

Where your leads come from: referrals, the website, an expo, cold outreach.

## Why this is a list and not a text box <!-- requires: LeadSourceView -->

Because of what it is *for*. **Win rate by source** is the most useful thing a CRM
tells you — it is how you find out that referrals close and cold outreach does not,
and where the next hour is best spent.

A free-text field gives you "LinkedIn", "Linkedin" and "linked in" as three sources
with three win rates, none of them right. A list of rows gives you one.

## Adding your own <!-- requires: LeadSourceCreate -->

The ones shipped are the channels almost every business here has. Add your own as you
go — "Expo 2026", "Chamber of Commerce", a particular partner.

Names are unique. A second row meaning the same thing as an existing one splits that
channel's history in half, and nothing reports that it has.

**Web form** is reserved: a future public form will create leads with that source
without anybody typing them.

## Deleting <!-- requires: LeadSourceDelete -->

A source that any lead came from **cannot be deleted** — it would quietly detach that
history, and the report the source exists for would lose it without saying so.

Switch **Active** off instead. It disappears from the form for new leads, and every
existing lead keeps its source.

## Roles and permissions

**View**: `LeadSourceView` — needed by anybody entering a lead, because the form has to
offer the list. **Create**: `LeadSourceCreate`. **Update**: `LeadSourceUpdate`.
**Delete**: `LeadSourceDelete`.
