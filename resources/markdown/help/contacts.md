![Contacts](/images/help/contacts.png)

## What a contact is

A contact is a customer, a supplier, or both — set by **Kind**. The same record
is used everywhere a person or company outside the business needs naming: an
invoice's **Contact** field, a billing run's **Client**, and a bank payment's
payee all point back here.

## Creating one <!-- requires: ContactCreate, ContactUpdate -->

Fill in a **Name** and a **Kind** (Customer, Supplier, or Both). Everything
else is optional:

- **Email**, **Phone**, **Address Line 1/2** — for correspondence and printed
  documents.
- **NTN** / **CNIC** — tax identifiers, printed on invoices where required.
- **Bank** — only relevant for a supplier: it is what the bank payment file
  flow uses to pay them.
- **Payment terms** — how long they have to pay. See below.
- **Active** — switch off to stop a contact appearing in new invoice/billing
  pickers without deleting its history.

## Payment terms

Set once here and every invoice for this contact gets its due date filled in:
invoice date plus the agreed days.

**"None agreed" is not "due on receipt", and the difference matters.** Due on
receipt means the money is expected the same day, so the invoice is overdue from
day one. None agreed means nobody has decided — the due date is left blank, and
the aged reports fall back to the invoice date without treating the contact as
having broken a promise nobody made.

Changing the terms does not touch invoices already raised. It applies from the
next one.

On an invoice, the due date is filled from the terms **only while it is empty**.
A date somebody typed is left alone, because it may have been negotiated — the
**Apply terms** button beside the field puts it back when that is what you want.

## People <!-- requires: ContactUpdate -->

Open a contact and use the **People** tab to add named contacts at that
company — an Accounts Payable clerk, a Managing Director. Each has their own
email, phone and a **Main contact** toggle. Only one person can be the main
contact at a time; turning it on for one stands the others down.

Correspondence follows the main contact: if one is set, that person's email is
where documents notionally go; otherwise it falls back to the contact's own
**Email** field.

## Deleting a contact <!-- requires: ContactDelete -->

Only possible while the contact has never been invoiced. Once at least one
invoice exists against it, delete is blocked — switch **Active** off instead.

## Roles and permissions

- **View / Create / Update** — `ContactView`, `ContactCreate`, `ContactUpdate`.
  Accountant, Manager and CEO all hold these.
- **Delete** — `ContactDelete`. Only CEO and Administrator hold it (Accountant
  and Manager can create and edit contacts but not remove them).
- Lives under the **Invoicing** module — disabling it removes Contacts from
  the sidebar for the company.
