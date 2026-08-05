## What this is

The wording of the automatic emails this company sends — payslip notices,
expense claim decisions, and the like. Each template overrides one
notification; anything you leave empty keeps the wording the application
ships with, so if you only want to change a subject line, you only change the
subject line.

## Creating or editing one

Click **New**, then pick which **Email** it rewords from the dropdown — one of:

- Payslip issued
- Payslip rejected
- Expense claim submitted
- Expense claim decided
- Employee change request

Each of these can only have one template — the Email field is unique. Fill in
any of **Subject**, **Greeting**, **Body**, or **Closing** you want to
override; leave the rest blank to keep the built-in wording for that part.
**Body** replaces the whole message and takes one paragraph per line.

Every email supports **placeholders** — written `{like_this}` — that get
filled in automatically when the email is sent (the employee's name, the pay
period, the amount, and so on). The form shows you exactly which placeholders
are available once you've picked the Email, so you don't have to guess or
check this doc for the list.

Switch **In use** off to fall back to the shipped wording without losing what
you wrote — useful if you want to draft a replacement without it going out
yet.

## Roles and permissions

This is Administrator-only: viewing, creating, editing, and deleting a
template all require the Administrator role specifically (there's no separate
`EmailTemplateView`-style permission to grant someone else). Deleting a
template is safe — it just restores the shipped wording for that
notification.
