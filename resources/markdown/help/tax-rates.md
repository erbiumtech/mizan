![Tax Rates](/images/help/tax-rates.png)

## What a tax rate is

A tax rate is a percentage and the account it posts to when it's charged —
"GST 18%" at 18.0000%, say. It's offered as the **Tax** option on an invoice
line; whether that rate is added on top of the line or already included in it
is decided per invoice, by that invoice's **Line amounts include tax** toggle,
not by the rate itself.

## Fields

- **Rate (%)** — 18 means 18 per cent.
- **Filing code** — the tax authority's own code for it, if it has one. Not
  used in any calculation, just carried through for filing.
- **Posts to** — the GL account this rate's tax lands on when charged. Leave
  it empty to use the default account (2150); give a rate its own account if
  it needs to be filed separately from the others.
- **Active** — switch off to stop offering it on new lines. Invoices that
  already used it are unaffected.
- **Offer first on a new line** — marks this rate as the default pre-selected
  on a new invoice line. Only one rate can be default at a time; turning this
  on for one stands the others down.

## Deleting

Only possible if the rate has never been charged on an invoice line. Once at
least one line has used it, delete is blocked with an explanation — switch
**Active** off instead. This is enforced on the record itself, not only in the
permission check, so it holds even for an Administrator.

## Roles and permissions

There's no separate Tax Rate permission group — access follows the Invoice
permissions, since a rate is part of how an invoice is priced:

- **View** — `InvoiceView`.
- **Create / Update** — `InvoiceCreate` / `InvoiceUpdate`.
- **Delete** — `InvoiceUpdate`, and only while unused (see above).

Whoever can raise or edit an invoice can manage the tax rates it uses.
