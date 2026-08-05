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

## Petty Cash

**Float Amount** is the imprest the petty cash box is restored to on
replenishment — see the Petty Cash Book help for how that plays out day to
day.

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

## Public Status Page

Publishes the up/down state and uptime of project environments marked "Show
on public status page" — never URLs, credentials, or error details. Off by
default. **Generate** a new access token to get a shareable link; regenerating
it immediately revokes every link already shared, since the token is part of
the URL itself.

## Roles and permissions

Administrator only.
