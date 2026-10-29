## Two different choices

**The interface language** is a person's own. Company Settings → Language sets
what everybody sees by default; anybody can override it on their profile, so
an office can run in English while one person reads Urdu. Dates and month
names follow the same choice.

**The document language** is the company's, because a document is read by
somebody outside it: a payslip by a bank, a letter by a next employer, an
invoice by the FBR. It can differ per kind — Urdu payslips for the floor and
English bank letters is two settings, not a support call.

## Urdu and the PDF engine

Urdu is written right to left, and the fallback PDF engine cannot shape
Arabic-script text: it would print disjointed letters. A document set to Urdu
therefore renders on the Chromium engine only, and refuses with that
explanation if Chromium is not installed on the server rather than handing
you something a bank would bounce. A cheque in Urdu spells its amount in
Urdu words; the digits stay Western, which is how Pakistani business writes
them.

## What is not translated

Your own words: designations, departments, dropdown entries, pay component
names, the chart of accounts. A company that wants Urdu designations types
Urdu designations. Role names stay the identifiers they are.
