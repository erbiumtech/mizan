![Invoices](/images/help/invoices.png)

## What an invoice is

An invoice is either a **Sale** (a customer invoice) or a **Purchase** (a
supplier bill) — set by **Kind** when it's created, and fixed after that.
It starts life as a **Draft**: editable, with no effect on the books at all
until it's **Issued**.

## Creating one <!-- requires: InvoiceCreate, InvoiceUpdate -->

From **Invoices**, click **New**. You'll fill in the Kind, the **Contact**,
the **Invoice Date**, and a **Currency** — the currency is what the contact is
billed in; the ledger is always posted in the company's own currency, at the
exchange rate on the invoice date unless you type an **Agreed rate** here.
Once the invoice is issued, currency and rate lock.

Save it, then open **Lines** and add one row per item:

- **Product** — optional. Linking one drives stock: a sale line consumes
  inventory and books its cost automatically; a purchase line receives stock
  into a new lot.
- **Description**, **Quantity**, **Unit Price**, **Line Total** — the total
  must equal quantity × unit price, checked when the invoice is issued.
- **Account Override** — optional. Without a product, a purchase line needs
  this to say what it was spent on.
- **Tax** — a rate from **Tax Rates**, or leave it empty for a line that
  carries none.

**Line amounts include tax** (on the invoice, not the line) decides how each
line's tax is read: on, the line amount is treated as already including tax;
off, tax is added on top. Whichever you pick, **Subtotal + Tax = Total** is
enforced at issue time — the app recalculates both from the lines' rates
whenever any line has one, overwriting whatever was last saved.

## Issuing <!-- requires: InvoiceIssue -->

Click **Issue** once the lines are ready. This is the one irreversible step:
it posts a balanced journal entry (a receivable or payable, revenue or
expense per line, tax split by whichever account each rate posts to, and — for
product lines — cost of goods sold moved out of inventory) and the invoice
becomes **Issued**. From here it can no longer be edited; only **Record
Payment** or **Void** act on it.

## Recording a payment <!-- requires: InvoicePay -->

**Record Payment** takes an amount (in the invoice's own currency) and a date,
moving the invoice to **Partially Paid** or **Paid** once the full total is
covered. For a foreign-currency invoice, the receivable was booked at the
invoice's rate but the money arrives at whatever rate applies on the payment
date — that gap is a real gain or loss and is posted automatically. If a bank
advice says what actually landed, type that rate in **Rate the bank gave**
rather than trusting the day's table.

## Voiding <!-- requires: InvoiceVoid -->

**Void** is only available on an **Issued** or **Partially Paid** invoice that
has had **no payments recorded against it yet** — once money has moved, the
invoice can't be voided at all; use **Credit** instead. Voiding reverses the
posting entry and, for product lines, undoes the stock movement — a sale's
consumed lot is restored, a purchase's lot must not have been partly used
elsewhere or the void is refused.

An invoice that has been credited can't also be voided. Both reverse the same
posting, so doing both would take the money out twice.

## Crediting <!-- requires: InvoiceVoid -->

**Credit** raises a credit note against a customer invoice: a separate document,
numbered `CN-`, that reverses the sale. Use it when Void isn't available or isn't
right — the invoice has been paid, or it's been reported to FBR and the 72-hour
amendment window has closed.

It's offered on any customer invoice that isn't a draft, isn't voided, and hasn't
already been credited in full. Purchase bills can't be credited here: a supplier
overcharging you is corrected by *their* credit note to you.

**What it does.** Creates a credit note **as a draft**, copying the invoice's
lines, tax and currency. Nothing posts until you issue it, exactly like an
invoice. Once issued it reverses the revenue, the sales tax and the receivable —
so the invoice and its credit note together come to nothing.

**Crediting only part of an invoice.** Credit it in full, then delete the lines
that were right before issuing. The total re-adds itself from whatever lines are
left. You can also credit the same invoice more than once, up to its total.

**A few things it deliberately doesn't do:**

- **It doesn't touch the original invoice.** That's the point — if the invoice
  was reported to FBR, FBR holds a record of it and it has to keep existing. The
  correction is the second document, not the disappearance of the first.
- **It doesn't return goods to stock.** Crediting a customer and taking the goods
  back are different things, and plenty of credits (billed twice, priced wrong,
  scrapped on site) return nothing. If stock genuinely comes back, record that as
  a stock movement.
- **It isn't paid.** A credit note reduces what the customer owes and shows as a
  negative on their balance and in Aged Receivables. If you're handing the money
  back, that's a payment out of the bank account it left.

The reason you type in is stored with the credit note and added to the invoice's
own history, so the invoice shows that it was corrected and why.

### The 180-day limit

If the company reports invoices to FBR, there's a deadline: a credit note only
adjusts your output tax if it's issued **within 180 days of the supply** — counted
from the invoice date, not from when it was reported.

Past that, the Credit form asks for a **Commissioner extension reference**. The
Commissioner Inland Revenue can extend the period once, by a further 180 days, on
written request with reasons. You obtain that outside this application; the field
records it, and it prints on the credit note, because it's the evidence that makes
a late adjustment stand up.

Past the extension, Credit is refused outright. There's no second extension to
get, so a credit note raised then wouldn't adjust the tax anyway — that needs your
tax advisor, not this screen.

None of this applies if the company doesn't report invoices to FBR. The rule
binds sales-tax-registered persons, so a company below the threshold can credit an
old invoice without being asked for anything.

## Deleting a draft <!-- requires: InvoiceVoid -->

A **Draft** invoice can be deleted outright — but the permission behind that
button is the same one that gates Void (`InvoiceVoid`), not a separate delete
permission. If someone can void an issued invoice, they can also delete an
unissued one.

## Recurring invoices

Some invoices carry a `recurring_invoice_id` and are raised automatically by a
scheduled job rather than by hand. There is no screen yet to create or edit a
recurring template — once raised, a recurring invoice is an ordinary Draft and
goes through Issue / Payment / Void exactly like any other.

## Billing against a project <!-- requires: InvoiceCreate, InvoiceUpdate -->

An invoice can name the **Project** it belongs to. Optional, and only offered
when the Projects module is licensed.

It answers a question ageing cannot: a client with four pieces of work running
has one balance owing and four different answers to "what has this one been
worth". Set the project on each invoice and the **Project** filter on the list
gives you everything billed against that engagement.

The project is a lens, not a change to the books — the client's total, the
ledger and the ageing buckets are all exactly as they were.

Deleting a project does **not** delete its invoices. The link is simply cleared:
an invoice is a document that went to a customer, and the file it was kept under
going away must not take the money with it.

## Roles and permissions

| Role | View | Create / edit drafts | Issue | Record payment | Void |
|---|---|---|---|---|---|
| Accountant | ✅ | ✅ | ✅ | ✅ | |
| Manager / CEO | ✅ | ✅ | ✅ | ✅ | ✅ |
| Administrator | ✅ | ✅ | ✅ | ✅ | ✅ |
| Employee | | | | | |

Invoices lives under the **Invoicing** module — disabling it removes Invoices,
Contacts and Tax Rates from the sidebar for the company.

## Quick answers

**Why can't I edit this invoice?**
It's no longer a Draft. Once issued, the only ways forward are Record Payment
or Void.

**Why is the Void button missing?**
Either the invoice has a payment recorded against it already (void is refused
once money has moved), or you don't hold `InvoiceVoid`.

**I raised the wrong amount and it's already issued — now what?**
If nothing has been paid against it, Void it and raise a corrected invoice. If a
payment has already landed, voiding is blocked — use **Credit** to raise a credit
note for the amount that was wrong, then invoice the right amount.

**Why is the Credit button missing?**
Either it's a draft (edit or delete it instead), it's already been voided, it's
already been credited in full, it's a purchase bill, or you don't hold
`InvoiceVoid` — the same permission gates both, because past the FBR reporting
window crediting *is* how you reverse a sale.

**Does a credit note need reporting to FBR?**
Yes, if the company reports invoices. It's a separate document to FBR, and it
shows in FBR Invoice Reporting under "Issued but never reported" until it's been
sent. Whether a credit note is a sufficient correction in law is a question for
the company's tax advisor.
