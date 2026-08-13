## What this is

Quotes: what you have offered somebody, and what became of it.

## A quote touches nothing <!-- requires: QuotationView -->

**No entry reaches the accounts** — not even a pending one. A quote is an offer; nothing has
happened yet, and nothing is owed. That matters more than it sounds: a quote that accrued
revenue would be an audit finding rather than a bug.

The accounts get involved when a quote becomes an invoice, and not before.

## Sending fixes the figures <!-- requires: QuotationUpdate -->

Once sent, a quote cannot be edited. **Revise** creates the next version instead, marks this
one superseded, and leaves it exactly as it was sent.

That is deliberate. The customer has version 1 in their inbox. Editing it in place would make
this system disagree with what they are looking at, and nothing could then say which version was
agreed. With versions, "what did we actually offer them in March" always has an answer.

## Validity <!-- requires: QuotationView -->

**An expired quote cannot be accepted.** Prices may no longer hold, and accepting one would
commit you to a figure you withdrew.

Expiring happens **once**, overnight — the quote moves to `expired` and is never reported again.
Acceptance is refused by the date regardless, so a quote that lapsed this morning cannot be
accepted this afternoon even before the sweep has run.

## Raising the invoice <!-- requires: QuotationConvert -->

**Creates a DRAFT invoice, and stops there.**

Lines, tax rates and currency are copied rather than recalculated, so the invoice charges what
the quote offered — a recalculation is how a quote and its invoice come to disagree about tax,
which is the disagreement a customer notices.

**It is not issued.** Issuing is what reports the invoice to FBR, and a reported sales-tax
invoice may only be cancelled or edited **within 72 hours**. After that the correction is a
credit note, and this application does not have one yet. So the invoice waits as a draft for
somebody to check and issue deliberately from Invoicing.

**A quote to a lead cannot be invoiced.** Convert the lead to a customer first — an invoice needs
a party the ledger can bill.

Converting twice is refused: it would bill the customer twice.

## Discounts

A discount lives on the quote line, where it is negotiated. The invoice carries the resulting
price rather than the argument that produced it.

## Roles and permissions

**View**: `QuotationView`. **Create / update**: `QuotationCreate`, `QuotationUpdate` — drafts
only. **Delete**: `QuotationDelete`, and never once a quote has become an invoice. **Raise the
invoice**: `QuotationConvert`, separate because of what issuing later means.
