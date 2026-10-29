## What this is

Who is short of a document the company requires, and of which. One row per active
employee, one column per required kind.

It is the other half of *Documents Expiring*. That report answers "what is about to
lapse", which is a question about documents the company holds. This one answers
"what have we never had" — and those are invisible everywhere else, because a
document nobody uploaded has no row to appear on.

## Before it says anything

The report is empty until somebody names what is required, under Company Settings →
*Employee documents*. That emptiness is deliberate: a compliance report against no
policy is a page of green that means nothing.

## What each cell says

- **Verified** — held, checked by somebody, and in date.
- **Awaiting review** — held, but nobody has looked at it yet. That is HR's job, not
  the employee's.
- **Refused** — somebody looked and sent it back. The reason is on the document.
- **Lapsed** — held, checked, and expired.
- **Not sent** — nothing of that kind on file at all.

The five states matter more than a count. "They have not sent it" and "nobody has
looked at it" are different jobs in different queues, and a tick or a cross would
hide which.

## Acting on it

Open Employee Documents to verify or refuse what is waiting; the *Awaiting review*
filter there is the queue. Refusing one asks for a reason, and the employee is told
what it was, so write what they have to do rather than what is wrong.

## Roles and permissions

`EmployeeDocumentView` to read it, the same as the documents themselves. Verifying
and refusing need `EmployeeDocumentUpdate`, and never on your own document.
