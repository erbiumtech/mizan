## What this is

The letters your company writes, saved once in your own words so they can be
issued without anybody retyping them. A template is a subject and a body; the
body carries placeholders like `{employee}` and `{effective_from}`, and those
are filled in from the employee's record at the moment the letter is made.

Every letter prints on the same company letterhead the income certificate and
the experience letter use, with the same reference line and seal. You write the
words, not the paper.

## The two kinds of letter

Four of them **issue themselves**:

- Increment letter — when a salary change is approved
- Promotion letter — when a promotion is recorded
- Transfer letter — when a transfer is recorded
- Confirmation letter — when probation is confirmed

Write one of those and it goes to the employee by email, attached as a PDF, the
moment the thing it describes happens. Leave it unwritten and nothing is sent:
a letter the company has not worded is not a letter it wants going out in its
name.

The rest are **asked for**, through Letter Requests — a no-objection
certificate, an address verification, a warning, an appreciation. An employee
asks, somebody in HR issues or refuses.

## Writing one

**New**, then pick the **kind** and the **language**. One template per kind per
language; a letter falls back to the English one when the reader's language has
none, so an English template on its own covers everybody.

The **body** is plain text. A blank line starts a new paragraph. It is never
treated as markup — whatever you type prints as typed, which is deliberate: a
letter that leaves the building should contain nothing a template could have
smuggled into it.

The form lists every placeholder underneath. One that does not exist prints as
nothing rather than printing its own name, so a typo leaves a gap instead of
putting `{desgnation}` in front of a bank.

**Preview** shows the template filled from a real employee, and **PDF**
downloads that preview on the actual letterhead. Both are worth doing once
before the first real letter goes out, because the first real one goes out on
its own.

Switch **Active** off to stop a letter being issued without losing the wording.

## Roles and permissions

Writing templates needs `LetterTemplateUpdate`, which by default only
Administrator holds — whoever has it decides what every future increment letter
says. Issuing a requested letter is `LetterIssue`, which Manager holds.
