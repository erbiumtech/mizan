## What this is

Every email and WhatsApp message the application sent, with the address it went
to and whether it got there. It answers one question: *the employee says they
never received their payslip — did we send it, and where to?*

There is nothing to edit here. Rows are written by the application as messages
go out.

## What a row holds

- **Sent** — when it went
- **Channel** — email or WhatsApp
- **Recipient** — the address or number it was sent to
- **What** — the kind of message, where the application knows it
- **Subject** — the subject line, for email
- **Status** — sent, or failed with the reason underneath

**The message itself is not stored.** No payslip figures, no letter text, no
WhatsApp body beyond the first few words of a plain message. A log that kept
the contents would be a second copy of everything sensitive the company sends,
sitting in a screen with a search box on it.

A failed row is the useful one. Use the **Failed only** filter after a payroll
run to see whether anything bounced.

## What it does not prove

A row saying *sent* means the mail server accepted it, not that a person read
it. A message can still be refused further down the line, sit in a spam folder,
or reach a mailbox nobody opens. What this screen rules out is the other
possibility — that it was never sent at all.

WhatsApp messages only appear if WhatsApp is configured. With no provider set
up the application still records what it would have sent, so the log shows the
attempt rather than silence.

## Roles and permissions

`MessageLogView`, which by default only Administrator holds. The log is every
address and phone number the company has written to, on one screen.
