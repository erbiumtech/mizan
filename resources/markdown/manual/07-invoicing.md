## What this chapter covers

Billing a customer and collecting the money: the standing records you need
before you can raise anything, building a draft, issuing it, recording what
arrives, and what to do when an invoice was wrong.

The same screen bills customers and records supplier bills. An invoice has a
**Kind**, chosen when it is created and fixed from then on:

- **Sale (customer invoice)** — money owed to you. Numbered `INV-YYYY-000001`.
- **Purchase (supplier bill)** — money you owe. Numbered `BILL-YYYY-000001`.

Everything below applies to both; where a purchase behaves differently it says
so. The word "invoice" means either.

## Before the first invoice

Three kinds of standing record have to exist. All three are one-off setup you
revisit rarely.

1. **A contact**, on the Contacts screen. Each one is marked as a **customer**,
   a **supplier**, or **both** — that is what makes it offerable on the right
   kind of invoice. Beyond the name you can hold NTN and CNIC, an address, a
   bank, and **payment terms**. Needs `ContactCreate`.

   Payment terms are worth setting properly: they fill the due date on every
   invoice for that contact, and the due date is what the aged reports bucket
   by. Note that **"none agreed" and "due on receipt" are different**. Due on
   receipt means overdue from day one. None agreed leaves the due date blank,
   and the ageing falls back to the invoice date without treating the contact
   as having broken a promise nobody made — which is what you want for a
   contact nobody has decided about yet.
2. **Named people on that contact**, optionally, on the contact's own screen.
   Correspondence goes to the primary named person if there is one, and to the
   contact's own address if there is not — so adding people changes who is
   written to, and adding none changes nothing.
3. **Tax rates**, on the Tax Rates screen, if you charge tax. A rate is a
   percentage (18 means 18%) and names the account its tax is booked to;
   leaving that blank books it to account **2150**. There is no separate tax
   rate permission — the screen reuses the invoice ones, so whoever can raise
   an invoice can add a rate.

   A rate that has been charged on any invoice line **cannot be deleted, by
   anyone** — not even an Administrator, because it is enforced on the record
   itself rather than by a permission. The rate is the record of why an issued
   invoice charged what it did. Switch it off instead, which stops it being
   offered on new lines and leaves history intact.

A currency other than your own also needs to exist and be active before it can
be billed in — see the chapter on the chart of accounts and currencies.

## Raising a draft

On the **Invoices** screen, click **New**. You will fill in:

1. **Kind** — sale or purchase. This cannot be changed after saving.
2. **Contact** — who is being billed, or who billed you.
3. **Invoice Date**, and optionally a **Due Date**. The due date is what the
   ageing report measures from; with none set it measures from the invoice date.
4. **Currency**, defaulting to the company's own. If it is a foreign currency
   you may also type an **Agreed rate**; leaving it blank means the rate in
   force on the invoice date is used. Both are locked once the invoice is
   issued.
5. **Line amounts include tax** — the inclusive/exclusive switch. It lives on
   the invoice rather than on the tax rate deliberately: the same 18% is quoted
   inclusive by one client and exclusive by another, so it is the document that
   says which.
6. **Subtotal**, **Tax** and **Total**.

Save it, then add **Lines** — a description, quantity, unit price and line
total, plus optionally a product, an account, and a tax rate. On a purchase, a
line with no product **must** name an account; that is where the cost is
booked. On a sale, a line with no account books to the product's revenue
account, or to **4200** for a product line and **4300** for a plain one.

The invoice is created as a **Draft** and gets its number immediately — the
number is assigned when the draft is saved, not when it is issued, so a
cancelled draft leaves a gap in the sequence.

Draft is the only state in which an invoice can be edited or deleted. Editing
needs `InvoiceUpdate`; deleting a draft is gated on `InvoiceVoid` rather than a
delete permission of its own, which is why an Accountant can build and correct
drafts but cannot throw one away.

## What tax gets worked out for you

If any line names a tax rate, the tax is computed from the rates and the
figures you typed are overwritten — on save and again on issue. Each line's
tax is taken from its own rate, and the invoice's subtotal is always net of
tax whichever way the inclusive switch is set:

- **Exclusive** — the line amount is net, and the tax is added on top.
- **Inclusive** — the line amount is gross, and the tax is what is already
  inside it. 118 at 18% is 18 of tax, not 21.24.

If **no** line names a rate, the **Tax** figure you typed is left exactly as
entered. That is how invoices raised before tax rates existed keep totalling
what they always did, and it is the only case where the Tax box is yours to
fill in.

## Issuing it

**Issue** is the step that turns a document into a receivable. It is available
on a draft to anyone holding `InvoiceIssue`, and can be run over several
invoices at once from the table's bulk actions.

Issuing, in order:

1. Recomputes the tax from the lines' rates.
2. Refuses an invoice with **no lines**.
3. Checks the arithmetic — every line's quantity × unit price must equal its
   line total, the subtotal must equal the sum of the lines net of tax, and the
   total must equal subtotal plus tax. A mismatch stops the issue and names
   what disagreed.
4. Fixes the exchange rate, if the invoice is in a foreign currency, and writes
   it onto the invoice. From then on that rate is what ties the document to the
   ledger; a rate recorded later for the same day will not restate it.
5. Posts **one balanced journal entry**. A sale debits receivables (**1250**)
   with the total, credits revenue per line, and credits each tax rate's
   account. A purchase debits each line's expense or inventory account, debits
   the input tax, and credits payables (**2400**).
6. Moves stock, for any line naming a product. A sale consumes lots and books
   cost of sales in the same entry; a purchase creates a lot.
7. Stamps the active fiscal year on the invoice if it does not already carry
   one, and records "Issued and posted as ..." in the invoice's history.

**The journal entry is posted immediately, with no approval step.** It is
created, approved and posted in one action — the approval you would expect of a
manual entry has already happened, in the decision to issue. See the chapter on
journal entries for what "posted" means everywhere else.

**An issued invoice cannot be edited.** It is backed by a ledger entry and the
client is holding a copy. The way back is Void, below.

## Recording money against it

**Record Payment** is available on an issued or partly paid invoice to anyone
holding `InvoicePay`. You give:

- **Date** the money arrived.
- **Amount**, **in the invoice's currency** — what the client actually owes and
  actually paid, not its converted value.
- **Rate the bank gave**, only shown on a foreign-currency invoice. Leave it
  blank to use the rate in force on the payment date; type the rate off the
  bank advice when you have one, because that is a fact and the rate table is
  only an estimate of it.

A payment may not be zero or negative, and may not exceed what is still
outstanding. Partial payments are expected: pay less than the total and the
invoice becomes **Partially Paid** and stays collectable, with the balance
still showing on the ageing report. Pay the rest and it becomes **Paid**.

Each payment posts its own journal entry — debiting cash and crediting
receivables on a sale, the reverse on a purchase. Cash defaults to account
**1100**.

## Foreign currency, and the gain or loss on payment

A receivable is booked at the rate the invoice was issued at. The money arrives
at the rate on the day it arrives. The difference between those two is real
money — what the company gained or lost by being paid later than it billed —
and it is recognised in full when the payment is recorded, as a **realised**
gain or loss on its own account, separate from the unrealised kind that
currency revaluation produces.

Two details worth knowing, because both are easy to misread as bugs:

- **What is still owed never moves with today's rate.** The outstanding figure
  is the invoice's own rate applied to what has not been paid. It is not
  restated as rates move; the whole difference is taken at settlement.
- **The foreign amount is recorded on the cash line only when the account holds
  that currency.** Euros paid into a euro account are euros in that account.
  Euros paid into a rupee account arrived as rupees, because the bank converted
  them, and writing euros into it would claim it holds a currency it does not.

An invoice paid in instalments always clears to exactly its booked value — the
reliefs are computed cumulatively, so rounding cannot leave a stray cent
sitting against an invoice marked paid in full.

## Voiding an invoice that was wrong

**Void** reverses an issued invoice. It needs `InvoiceVoid`, which of the
seeded roles only Manager, CEO and Administrator hold — an Accountant raises
and issues, someone else cancels.

Voiding reverses the invoice's journal entry rather than deleting it, undoes
the stock it moved, and sets the invoice to **Void**. Both the original entry
and its reversal stay on the ledger, which is the point: the correction is on
the record.

Two things will refuse:

- **An invoice with any payment recorded against it cannot be voided.** Not a
  partial one either. Deal with the payment first, or issue a credit.
- **A purchase whose stock has been partly sold on cannot be voided**, because
  the lot it created is no longer intact. The error names the product.

To correct an invoice that has been issued: void it and raise a new one. There
is no edit.

## Seeing what is still owed

**Aged Receivables** (money in) and **Aged Payables** (money out) show every
issued and partly paid invoice bucketed by how far past due it is: current,
31–60, 61–90, and 90+ days. Both need `ReportView`.

The bucket totals are in the company's own currency, converted at each
invoice's own booked rate — adding up invoices in a mixture of currencies would
also produce a number, which is precisely how that goes wrong unnoticed. Each
line still shows what the client owes in the currency they were billed in.

Every invoice also carries its own **history**: raised, issued, printed, each
payment with any exchange gain or loss noted, and voided. A PDF being produced
is logged as "printed", which is the closest thing to "sent" the system can
honestly witness.

## Invoices that raise themselves

Recurring agreements — a client billed the same lines every month — are raised
automatically on the **1st of each month at 03:00**, once per company, skipping
companies without Invoicing. It needs `schedule:run` on cron to fire.

What it produces is **drafts, never issued invoices**, deliberately: an invoice
reaching the ledger and the client is a decision somebody makes after reading
it, and a scheduled job is not somebody. You have the rest of the month to
correct one before issuing it. A draft raised this way is an ordinary invoice —
same issuing, same posting, same ageing.

Running it twice is safe: an agreement already invoiced for a month is skipped,
with a database constraint behind that in case two runs overlap.

**There is no screen for managing recurring agreements.** They exist as records
and are administered from the console — `invoicing:raise-recurring` will also
list what is due, and `--dry-run` shows what would be raised without writing
anything.

## Who can do what

| Permission | What it allows |
|---|---|
| `InvoiceView` | See invoices |
| `InvoiceCreate` | Raise a draft |
| `InvoiceUpdate` | Edit a draft (never an issued invoice) |
| `InvoiceIssue` | Issue a draft, posting it to the ledger |
| `InvoicePay` | Record money against an issued invoice |
| `InvoiceVoid` | Void an issued invoice, and delete a draft |
| `ContactView` … `ContactDelete` | Manage contacts |
| `ReportView` | Aged Receivables and Aged Payables |

Of the seeded roles: **Accountant** raises, edits, issues and takes payment,
and manages contacts, but cannot void. **Manager** adds `InvoiceVoid`. **CEO**
adds `ContactDelete` on top of the Manager's permissions. **Administrator**
holds everything. **Employee** has no access to invoicing at all.

All of this belongs to the **Invoicing** module. A company without it licensed
sees none of these screens, and the monthly recurring job skips it.
