## What this shows

Who may be contacted, on which channel, and the evidence behind each permission: the
state, where it came from, when it was recorded and who recorded it.

**This is compliance evidence, not marketing statistics.** There is deliberately no
opt-in rate, no channel comparison and no trend on this report. A marketing figure
answers "how are we doing"; this answers "who agreed to this, when, and how" — which
is the only defensible answer when somebody complains.

## The state is derived, never stored

There is no subscribed checkbox anywhere in this system. Every grant and every
revocation is **its own row**, so somebody who opted in, then out, then in again has
three rows and one current state. The **Changes** column counts that trail, and the
trail is what makes the record defensible.

The register shows the **latest row** per subject per channel — resolved exactly the
way the sender resolves it when deciding whether to contact somebody, including the
tie-break on record id when two rows share a timestamp. So what this report says you
may do is what a campaign will actually do.

Each **channel is its own permission**. Agreeing to email is not agreeing to WhatsApp,
and a subject with both appears twice.

## Read as at a date

The date is an **as-at**, not a filter: it shows the latest record on or before that
date. So "what did we have permission for on 30 June" has an answer, and a later
revocation does not rewrite the past.

A subject whose only records come *after* your date is absent from the register rather
than shown as revoked — there was nothing recorded then, and **no record means no
permission**. An empty register is not "nothing to show": it means nobody may be
contacted on any channel, and the report says so.

## The finding: a grant with no source

**Source** is where the permission came from — a form, a phone call, an import. A
grant with nothing in it shows as **Not recorded**, and it is the report's headline
finding: *they agreed* is worth nothing without *and here is how*. On every other
screen such a permission is indistinguishable from a defensible one.

**Recorded by** works the same way. A grant with nobody against it usually came in
through an import or a console command rather than through somebody's hands.

Both are counted on **grants only**, and the asymmetry is deliberate: removing
somebody from a list needs no justification, and only a permission has to be defended.
A revocation with no source is not a finding.

## Order

Rows are ordered by what needs doing about them: grants with no source first, then
grants with no recorder, then sound grants, then revocations — the safe state, with
nothing at risk. Within each group the most recent record is first.

## Roles and permissions

Requires `ReportView`, gated behind the Campaigns module being enabled. Read-only —
recording a grant or a revocation happens through the consent record itself, and
neither one is ever an edit to an existing row.
