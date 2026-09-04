![Company Settings](/images/help/company-settings.png)

## What this is

The per-company settings that don't belong on any single resource: the base
currency, the petty cash float, payroll posting behaviour, the salary bank
file's header defaults, and the public status page.

## Currency

**Base currency** is what every amount in this company's ledger means. It can
still be changed freely until the first journal entry line is ever posted —
after that it's locked, because changing it would reinterpret every posted
amount rather than restate it.

## Letterhead

The company as somebody outside it reads it: the **registered name** (with its suffix, not the
short name the panel uses), the **registered office address**, the **NTN** and the
**incorporation number**, plus phone, email and website.

Nothing held these before the income certificate needed them — `companies.name` is what this
panel calls you, which is not what a bank checks you against. They are blank until you fill them
in, and there is no default, because a letterhead carrying another company's details would be
worse than a blank one.

**Who signs** is the name printed above the signature line, with their title. The certificate
refuses to print while the registered name, the address, the NTN or the signatory is empty.

## Notifications

The bell in the top right carries two kinds of thing.

**The ones that speak for themselves** — a payslip issued, a leave request waiting on you, an
environment down, a certificate about to expire — always arrive and are not affected by
anything on this page.

**"A record of yours changed"** is the switch here. On, somebody who owns a record hears when
anybody *else* changes or deletes it. Owning it means the record names them: it is theirs
(`user id`), they created it, they asked for it, or — where a screen says so — it is assigned
to them.

Three things it deliberately does not do:

- **It never tells you about your own edit.** Told that you did what you just did, people
  learn to ignore the bell, and then it is worth nothing when it carries something real.
- **It never emails.** A mail per edit is a mailbox nobody reads. This goes to the bell, where
  the cost of one you did not need is a glance.
- **It says nothing about a record nobody owns.** Reference data — a category, a rate, a code
  — names no person, so changing it puts nothing in front of anybody.

New records are not announced either: whoever created it already knows, and an import of five
thousand rows would otherwise be five thousand notifications.

## Petty Cash

**Float Amount** is the imprest the petty cash box is restored to on
replenishment — see the Petty Cash Book help for how that plays out day to
day.

## Approvals

**Require a second person to approve journal entries** — on by default, and it
should stay on wherever two people are available.

On, whoever writes an entry cannot be the one who waves it through. That is
segregation of duties, and it is the control most of this application's
accounting behaviour assumes.

**Turn it off only if one person runs the books alone.** The rule assumes a
second person exists; where none does, it stops being a control and becomes a
dead end — the entry sits waiting for an approver who will never come, while the
money it describes has already left the bank. That is how a month's payroll can
end up paid with its accrual unposted, showing a negative Salaries Payable.

With it off:

- Whoever writes an entry may approve it, and the audit trail records each of
  those as a **self-approval** — a waived control that left no trace would be
  worse than no control.
- **Scheduled entries** and **loan instalments** post themselves instead of
  queueing, for the same reason: nobody is coming to read them.

Payments, the Account Register and payroll (when auto-posting is on) already post
immediately and are unaffected either way.

The toggle starts wherever this installation was set up
(`ACCOUNTING_REQUIRE_SECOND_APPROVER` in `.env`, on unless changed). Saving it
here is this company answering the question for itself, and that answer stands
whatever the installation is later changed to.

## Payroll

**Auto-post payroll journal entries**: on, a payroll run's journal entry is
approved and posted the moment it's created; off, it waits for Manager/CEO
approval like a manual entry does.

**Payroll Account Codes** map each payroll journal line to a chart-of-accounts
code. Leave one blank to fall back to the shipped default. Saving checks that
every code you do enter actually exists in the chart of accounts — a typo
here would otherwise only surface when payroll posting fails, long after this
page was saved.

## iPayments (Salary Bank File)

Header defaults for the salary bank file upload — account and bank
identifiers, currency, payment type codes, and so on. Each field is validated
against the exact pattern the bank expects (an 8/11-character SWIFT code, a
2-letter country code, and so on) at save time rather than only at upload,
since a malformed header otherwise gets discovered only when the bank rejects
the whole file.

## Chasing overdue invoices

Whether this company emails customers whose invoices are past due, after how many
days, and how often to chase the same invoice again.

**Off by default, and it stays off until somebody switches it on here.** This is the
only thing in the application that writes to somebody outside the company, so it is
not a feature that starts working because a deploy happened.

The threshold is counted from the due date; the repeat interval is counted from the
last reminder actually sent, per invoice. Each reminder is recorded on the invoice's
own history. The wording of the letter is not here — it is an Email Template
(`invoice_overdue`), and the shipped text is sent until you write your own.

There is no interest and no late fee, deliberately: charging for lateness is a
posting rather than a message, and it needs a rate, a start date and an account
before it means anything.

Before switching it on, run `php artisan invoicing:send-overdue-reminders --dry-run`
to see exactly who would be chased.

## Public Status Page

Publishes the up/down state and uptime of project environments marked "Show
on public status page" — never URLs, credentials, or error details. Off by
default. **Generate** a new access token to get a shareable link; regenerating
it immediately revokes every link already shared, since the token is part of
the URL itself.

## Roles and permissions

Administrator only.
