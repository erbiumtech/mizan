![Email Templates](/images/help/email-templates.png)

## What this is

The wording of the automatic emails this company sends — payslip notices,
expense claim decisions, and the like. Each template overrides one
notification; anything you leave empty keeps the wording the application
ships with, so if you only want to change a subject line, you only change the
subject line.

## Creating or editing one

Click **New**, then pick which **Email** it rewords from the dropdown. Every
email the application sends is on that list — payslips, leave and expense
decisions, approvals waiting on somebody, invoices and statements, scheduled
reports, expiring documents, and the monitoring alerts about environments and
attendance devices.

Most of them go to somebody who works here. A handful go outside the building,
and those are the ones worth a second look before you change the wording:
invoice and quotation emails, the customer statement, the overdue reminders,
and the message asking a customer to confirm a sales visit. Overdue reminders
are also only ever sent if chasing is switched on in Company Settings →
*Chasing overdue invoices*.

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

## Checking it before it goes out

**Preview** shows the finished email with made-up names and figures filled into
the placeholders. The parts you have not overridden show as *(the wording this
email normally uses)* rather than as the shipped sentence, so you can tell at a
glance which parts of the message are yours and which are still ours.

**Send a test** emails that same preview to your own address. Worth doing once
for anything that goes to a customer.

A preview never uses a real employee's real figures: the thing being checked is
the sentence, not the number.

## Roles and permissions

This is Administrator-only: viewing, creating, editing, and deleting a
template all require the Administrator role specifically (there's no separate
`EmailTemplateView`-style permission to grant someone else). Deleting a
template is safe — it just restores the shipped wording for that
notification.
