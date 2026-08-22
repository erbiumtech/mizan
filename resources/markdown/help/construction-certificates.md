## Two documents, not one

A **progress claim** is what you submit. A **payment certificate** is what comes
back. They are separate screens because they are separate documents, by different
people, with different dates and different legal effect: the time bar for a claim
runs from the claim, the payment period runs from the certificate.

Under AIA the two print as one form (G702). That still works — the form is printed
by joining the two records. What does not work is the other direction: one record
cannot hold a claim for 10,000,000 and a certificate for 9,200,000 without
inventing shadow columns, and **applied versus certified** is the first figure any
commercial meeting asks for.

## Everything is cumulative

Every value on a claim or a certificate is **to date**, never "this period". The
period figure is worked out by subtraction and never stored.

That is not a preference. If you store the monthly figure and add up for the
total, then correcting certificate 6 silently breaks every total after it. Storing
the cumulative makes a correction self-healing: certificate 7's "this period"
absorbs the difference, which is exactly what happens on paper.

So when a field says **to date**, put the total to date in it.

## Making a claim <!-- requires: ConstructionCertificateCreate -->

**New**, pick the contract and the valuation date. The lines come from the
contract schedule automatically, already carrying last claim's figures — a claim
is cumulative, and starting from a blank sheet is how one comes to claim less than
the one before it.

For each line, say how you measured it:

- **Percent complete** — for lump-sum lines.
- **Quantity done** — for remeasured lines; the value comes from the contract rate.
- **Value** — when you are simply stating the money.
- **Milestone** — nothing until complete, then everything.

**Which one you typed is recorded**, and that matters later: a percent stored
against a line whose quantity was grown by a variation is measured on a stale
denominator, so the line would read 87% while the money is fully certified. The
value is authoritative; the input is what lets somebody reproduce your intent.

**Materials on site** is separate from work: delivered but not yet built in. Some
contracts retain it at a different rate, or not at all.

**Submit** resolves every line into a value and stamps the header from the lines.
After that the claim is the record of what was applied for, and it does not move.

## Certifying <!-- requires: ConstructionCertificateCertify -->

From a submitted claim, **Prepare certificate**. Or start a certificate directly —
clause 14.6 lets the Engineer certify without a conforming statement, so the claim
is optional.

A draft certificate:

- carries one line per claimable schedule line, with the previous certificate's
  figures **frozen into it** (column D) so voiding an earlier certificate cannot
  change what this one says;
- computes retention, advance recovery and "less previously certified" for you;
- keeps computing while it is a draft.

Edit the certified lines down where you disagree with the claim. That is the
ordinary case and the reason both documents exist.

### The deductions tab

Every deduction is a row, and **a negative amount reduces the payment**.

Three rows are computed and marked as such: **retention** (this period's
movement), **advance recovery**, and **less previously certified**. They are
rewritten on every recompute and cannot be added by hand — a second retention row
is a double deduction nobody notices until the other party does.

What you add by hand is a decision: an NCR deduction, liquidated damages, a
back-charge, tax withheld. Name the clause in the description; it prints.

### Certify

**Certify** freezes the figures, stamps the issue date, and computes the payment
due date from the contract's own terms. From that moment the certificate is a
statement of a moment that somebody outside this company relies on.

If the amount due is below the contract's **minimum certificate amount**, issuing
is refused — that is what the term means, and the value stays in the schedule and
rolls into the next certificate by itself.

## Correcting an issued certificate

Two ways, and neither is an edit:

1. **Let the next certificate absorb it.** Everything is cumulative, so certifying
   the right figure next month self-corrects. This is usually right.
2. **Void it**, with a reason. The number stays on the register — a gap in the
   series is a question at adjudication, and "voided on the 14th" is an answer. A
   voided certificate stops counting towards *previously certified*, so the next
   one's arithmetic closes itself with nothing to adjust.

## Printing it

**Print** on the register opens the certificate as a PDF: a summary page with the
numbered lines, the deductions listed one per row, and a **continuation sheet**
showing every schedule line — item, scheduled value, what was completed
previously, what was completed this period, materials stored, the total to date,
the balance to finish and the retainage.

Long sheets break into pages in a way worth knowing about, because it is
deliberate:

- each page carries its **own column headers**, so page seven of a forty-page
  sheet is readable on its own;
- each page after the first opens with a **brought forward** row and closes with a
  **carried forward** subtotal, which is what a printed bill of quantities has
  always looked like;
- the last page's total is labelled **Total** rather than carried forward;
- the footer says **page n of m**.

All of that is worked out before the document is rendered, which means the
certificate paginates identically every time it is printed — on any server, with
or without headless Chrome installed. That matters because reissuing a certificate
has to produce the same document, not merely the same figures.

A FIDIC certificate prints portrait with the measurement schedule annexed; an AIA
one prints landscape, because eleven columns do not fit any other way.

## Raising the invoice <!-- requires: ConstructionCertificateInvoice -->

**Raise invoice** on an issued certificate produces a **draft** invoice and stops
there. Issuing an invoice transmits it, and transmission is what cannot be undone —
so that stays a decision somebody makes in Invoicing after reading it.

What it produces is four lines, not four hundred:

| Line | Account |
|---|---|
| Work executed this period per the certificate | contract revenue |
| Less retention | **retention receivable — an asset** |
| Less advance payment recovery | advance received — a liability |
| Less any deduction (NCR, damages, back-charge) | contract revenue |

One line per deduction group rather than one per schedule item: a four-hundred-item
bill would otherwise make a four-hundred-line invoice with uniform tax treatment,
and the client reconciles against the certificate anyway.

**The invoice is for the period, not the total.** Certificates are cumulative; the
invoice bills what has become due since the last one. The certificate's *less
previously certified* row is what turns one into the other, so it is not an invoice
line — billing that as well would deduct the same money twice.

Withholding tax is also not a line here: it is the client's own deduction against
the invoice, and it belongs to whoever records the receipt.

Raising it twice is refused. The certificate remembers the invoice it became.

### Retention is not netted off the invoice

The work is invoiced **gross** and retention appears as its own line against a
retention asset account — not as a smaller invoice.

Invoicing net understates revenue for the whole life of the job by up to a tenth,
and then makes the release look like revenue earned in a period when no work
happened. That is precisely the misstatement an audit looks for.

Which accounts those lines land on is set under **Company Settings → Construction →
Construction Account Codes**, with shipped defaults behind every line. If one names
a code your chart does not have, the error says so and names the seeder that
creates them.

**Without the Invoicing module this action is simply absent**, and nothing is broken
by its absence: a payment certificate is a contractual instrument in its own right.
It starts the payment period, the other side countersigns it, an adjudicator reads
it — whether or not anybody raises a tax invoice.

## Who does what

| | Surveyor / commercial | Manager | CEO |
|---|---|---|---|
| Read claims and certificates | ✓ | ✓ | ✓ |
| Prepare a claim, prepare a draft certificate | ✓ | ✓ | ✓ |
| Certify and issue, or void | | ✓ | ✓ |
| Raise the tax invoice | | | ✓ |

Certifying is its own permission because it starts a payment clock and creates an
entitlement the other party will enforce. Preparing the certificate is arithmetic;
issuing it is a decision.
