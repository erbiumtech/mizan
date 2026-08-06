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
- **Active** — switch off to stop a contact appearing in new invoice/billing
  pickers without deleting its history.

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
