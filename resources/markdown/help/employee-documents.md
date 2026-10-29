## What this is

Documents with a date on them: visas, passports, licences, contracts.

## Expiry is the point <!-- requires: EmployeeDocumentView -->

A visa that lapsed last month is the kind of thing nobody notices until it matters. This
list exists to notice.

**Warnings are sent once per threshold, not once a day.** Crossing 60 days warns, 30
warns again, 7 warns again — and the days in between are silent. A job that mailed the
same warning every morning for a month would train everybody to filter it, and then the
one that mattered would be filtered too.

**Renewing a document lets it warn again.** Change the expiry date and the warning state
resets, so the new deadline is treated as a new deadline.

Leave the expiry blank for a document that never lapses — a degree certificate, a CNIC
copy. Nothing warns about those.

## Expired is not "expiring soon" <!-- requires: EmployeeDocumentView -->

The list distinguishes them and so does the notification. "Lapsed 40 days ago" is a
different problem from "lapses in 40 days", and reading one as the other is how the
first gets left alone.

## Verifying and refusing <!-- requires: EmployeeDocumentUpdate -->

A document sits in one of three states, shown in the **Review** column.

- **Awaiting review** — somebody sent it and nobody has looked. The *Awaiting review*
  filter is that queue.
- **Verified** — somebody looked and it is what it claims to be.
- **Refused** — somebody looked and sent it back. The reason is shown on the row and
  the employee is told it, so write what they have to do: "the back of the card is
  missing", "the scan is unreadable".

You cannot verify your own document. A document nobody but its owner has looked at has
not been verified by anybody.

Verifying a refused document clears the refusal, and refusing a verified one clears the
verification. A document is only ever in one state.

## What is accepted <!-- requires: EmployeeDocumentView -->

The file types and the size limit come from Company Settings → *Employee documents*, so
a company that only wants PDFs says so there rather than asking for a deployment. The
upload box tells you what it will take.

## Who can see these <!-- requires: EmployeeDocumentView -->

These are among the most sensitive records this application holds, so they have their
own permissions rather than sharing the checklist's. Somebody who can tick off an
onboarding task cannot thereby read everybody's passports, and **the Employee role holds
none of it** — not even for their own documents. Those go through HR.

Scans are stored per company and served only to somebody signed in with access to that
company.

## Roles and permissions

**View**: `EmployeeDocumentView`. **Create / update / delete**:
`EmployeeDocumentCreate`, `EmployeeDocumentUpdate`, `EmployeeDocumentDelete`. Whoever
holds Update is who the expiry warnings are sent to.
