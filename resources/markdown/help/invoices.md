![Invoices](/images/help/invoices.png)

## What an invoice is

An invoice is either a **Sale** (a customer invoice) or a **Purchase** (a
supplier bill) — set by **Kind** when it's created, and fixed after that.
It starts life as a **Draft**: editable, with no effect on the books at all
until it's **Issued**.

## Creating one

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

## Issuing

Click **Issue** once the lines are ready. This is the one irreversible step:
it posts a balanced journal entry (a receivable or payable, revenue or
expense per line, tax split by whichever account each rate posts to, and — for
product lines — cost of goods sold moved out of inventory) and the invoice
becomes **Issued**. From here it can no longer be edited; only **Record
Payment** or **Void** act on it.

## Recording a payment

**Record Payment** takes an amount (in the invoice's own currency) and a date,
moving the invoice to **Partially Paid** or **Paid** once the full total is
covered. For a foreign-currency invoice, the receivable was booked at the
invoice's rate but the money arrives at whatever rate applies on the payment
date — that gap is a real gain or loss and is posted automatically. If a bank
advice says what actually landed, type that rate in **Rate the bank gave**
rather than trusting the day's table.

## Voiding

**Void** is only available on an **Issued** or **Partially Paid** invoice that
has had **no payments recorded against it yet** — once money has moved, the
invoice can't be voided at all; correct it by other means (a credit or a new
invoice). Voiding reverses the posting entry and, for product lines, undoes
the stock movement — a sale's consumed lot is restored, a purchase's lot must
not have been partly used elsewhere or the void is refused.

## Deleting a draft

A **Draft** invoice can be deleted outright — but the permission behind that
button is the same one that gates Void (`InvoiceVoid`), not a separate delete
permission. If someone can void an issued invoice, they can also delete an
unissued one.

## Recurring invoices

Some invoices carry a `recurring_invoice_id` and are raised automatically by a
scheduled job rather than by hand. There is no screen yet to create or edit a
recurring template — once raised, a recurring invoice is an ordinary Draft and
goes through Issue / Payment / Void exactly like any other.

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
If nothing has been paid against it, Void it and raise a corrected invoice. If
a payment has already landed, voiding is blocked — this needs a manual
correction rather than the built-in flow.
