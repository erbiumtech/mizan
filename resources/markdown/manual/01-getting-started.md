## Before you start

A company is more than a row in a table here: each one gets **its own database**.
Users, companies and licences live centrally; everything a company owns —
employees, payslips, accounts, invoices, journal entries — lives in that
company's own database and is reached through the company's own URL
(`/admin/<slug>/…`).

That has one consequence worth knowing up front: nothing you do inside one
company can be seen from another, and "switching companies" in the sidebar is
genuinely switching databases.

Creating a company is a **super admin** job, not something a company
administrator can do for themselves. Everything after step 1 is ordinary
administration inside the new company.

## 1. Create the company

Two ways in, and they do the same work:

1. From the **Platform** panel (`/platform`) → **Companies** → **New**.
2. Or on the command line, which is the honest option when you are setting up a
   server for the first time:

   ```
   php artisan companies:create "Acme (Private) Limited" --owner=you@example.com
   ```

Either way the app does all of this in one go: writes the company record,
creates and migrates its database, seeds the baseline data described below, and
attaches whoever you named as **Administrator** of it.

If provisioning fails half-way — a database it cannot reach, a schema that
already has tables in it — it **undoes its own work** rather than leaving a
half-built company behind, and tells you what went wrong. Read the message; the
common case is a leftover database from a previous attempt, and it names the
command that clears it.

## 2. What arrives already filled in

A new company is not empty. It starts with:

- **A fiscal year** — 2025-2026, running 1 July 2025 to 30 June 2026, marked
  active.
- **A chart of accounts** — a payroll-oriented default. Group headers (Assets,
  Liabilities, …) with postable accounts beneath them: Cash / Bank, Petty Cash,
  Employee Advances, Accounts Receivable, and so on.
- **Currencies** — PKR as the base currency, plus a few others, inactive until
  you need them.
- **Transaction types** — Salary, Rent, Food, Utilities, Fuel and friends, each
  already pointing at the expense account it posts to.
- **Salary tax slabs** for the seeded fiscal year.
- **The Pakistani bank list**, with the bank codes the salary and payment files
  need.

So you are editing a working chart of accounts, not building one from nothing.

## 3. Switch on the modules the company has bought

A brand-new company starts with **Core only** — Users, Roles, fiscal years, the
audit trail and this settings area. Everything else (Employees, Payroll,
Accounting, Invoicing, Inventory, Projects and the rest) is a module that has to
be licensed and then switched on.

There are two separate gates, and they belong to different people:

1. **Licensing** is the super admin's, on the company record in the Platform
   panel. It says what the company has bought.
2. **Enabling** is the company Administrator's, on **Settings → Modules**. It
   says what the company is using right now.

Only licensed modules appear on the Modules page at all — an unlicensed module
is not shown as a locked toggle, because offering a control that can only fail is
worse than not offering one. If the page tells you nothing is licensed and you
are a super admin, it links you straight to where you grant them.

Modules have dependencies, and the page resolves them in front of you before you
save:

- Switching one **on** pulls in what it requires. Invoicing needs Accounting;
  Payroll needs Employees; Advances and Expense Claims need both Employees and
  Payroll.
- Switching one **off** takes everything that depends on it off too, rather than
  leaving a half-working module behind.
- The **On save** line spells out both, plus anything it cannot switch on because
  a requirement is not licensed.

Switching a module off **hides it; it does not delete anything**. The record
count next to each toggle tells you how much you are about to hide. Switching it
back on restores everything untouched.

Core has no toggle at all — it holds the Modules page, Users and Roles, so a
company able to switch it off could lock itself out of its own administration.

## 4. Set the company up

**Settings → Company Settings**, which is Administrator-only. The sections that
matter now:

- **Currency** — the **base currency** the books are kept in. It defaults to
  PKR. The base currency needs no exchange rate (it is the one everything else is
  quoted against) and cannot be deleted while it holds that job.
- **Petty Cash** — the float amount, if you will use the petty cash book.
- **Payroll** — whether payslips post their journal entries automatically, and
  which account codes payroll posts to. Leave this until you set payroll up
  properly; there is a check that tells you when those accounts are wrong.
- **iPayments** — the defaults stamped into the salary bank file.

## 5. Check the fiscal year

**Core → Fiscal Years.** One year is **active** at a time, and the app enforces
that: activating a year stands every other one down. This matters more than it
looks, because everything that asks "what year is it" asks for the active one —
with two active years you do not get an error, you get the wrong year.

The seeded year may not be the one you want. Create the right one, activate it,
and confirm exactly one row shows as active.

A year can also be **closed**, which freezes its ledger — nothing may post into
a closed year afterwards. Do not close anything yet; that is an end-of-year job
covered in *Closing a period*.

## 6. Review the chart of accounts

**Accounting → Chart Of Accounts.** Two rules govern the shape of it:

- An account can only receive postings if it is **active**, **allows manual
  entry**, and has **no sub-accounts**. Give an account a child and it becomes a
  group header that can no longer be posted to — silently, and immediately.
- An account that already has ledger history **cannot be given a child**. The
  save is refused, naming the account.

So decide the shape before the postings start. Adding a leaf account later is
easy; turning a used account into a group header is not possible.

## 7. Add the bank details the files need

Two different things, both needed before you can pay anyone:

- **Accounting → Banks** — the banks themselves. Already seeded with the
  Pakistani list and their bank codes; you rarely need to add one.
- **Accounting → Company Bank Accounts** — *your* accounts at those banks. These
  are what the salary bank file and the payment file are drawn on, so at least
  one has to exist and be right, down to the account identifier.

## You are now ready to

- Add people and decide what they can do — *People, roles and what they can do*.
- Run a month of payroll end to end — *Running payroll*.
- Record money going out — *Paying suppliers and beneficiaries*.
- Bill customers — *Invoicing a customer*.
