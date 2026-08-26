## What this shows

Every credit note issued in the financial year to date: what it reversed, when,
for how much, how long after the original invoice — and whether it was allowed.

Before this report, a credit note was only visible on the invoice it credited.
There was no way to ask the question this report exists for.

## The tax question

A credit note may be issued against an invoice for a limited number of days — **180
by default**, set as `fbr.credit_note_days`. Beyond that it needs the Commissioner's
approval under rule 22, recorded against the credit note as an approval reference.

**Nothing in this application refuses a credit note outside that window.** The rule
is reported, not enforced — the same position the SLA clocks take. So this list is
the only place a reversal made without cover is visible.

The **Standing** column says which of four situations each credit note is in:

- **Within 180 days** — nothing to do.
- **Approved · <reference>** — outside the window, with an approval recorded. Somebody
  should be able to produce that reference if asked.
- **No approval recorded** — outside the window with nothing against it. This is the
  exposure: tax has been reversed on a document that, on the face of the record, was
  not permitted to reverse it.
- **No invoice named** — the credit note does not say which invoice it credits, so the
  window cannot be computed at all.

That last one is deliberately **not** treated as compliant. A credit note can
legitimately be raised standalone, but calling it "within the window" would be a
guess in the company's favour on a tax question, and this report has no business
making that guess. The note counts them so they can be looked at.

## The exposure figure

**Without approval** at the top is an **amount**, not a count. What matters is how
much tax was reversed without cover, not how many documents did the reversing — one
large credit note is a bigger problem than five small ones.

## Using it

The period is the **financial year to date** — 1 July to your date, not 1 January.

**Days after** is counted from the credited invoice's date to the credit note's
date. A dash means no invoice was named.

**Only issued credit notes** appear — issued, partially paid or paid. A draft has
reversed nothing yet and a void one never did.

The window is read from your company's setting, so if you have changed it from 180
days this report judges by your figure rather than the default.

## Roles and permissions

Requires `ReportView`, gated behind the Invoicing module being enabled. Read-only —
nothing here issues a credit note or records an approval. Both of those happen on
the credit note itself.
