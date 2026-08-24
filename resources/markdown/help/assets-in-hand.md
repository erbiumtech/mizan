## What this shows

Every laptop, phone, SIM, vehicle and access card the company has issued and not
had back — who has it, since when, what it is worth, and whether the books know
about it.

Before this report you could see one person's issued kit on their own record.
Nothing asked the company-wide question, so nothing noticed that somebody who
left in March still has a laptop.

## The value is the recovery, not an estimate of it

**Value out** at the top is exactly what a final settlement would charge. It is
the same figure the settlement builder computes, summed over the same items — not
a comparable number calculated a second way. If you deduct unreturned kit from
somebody's settlement, this is that deduction.

Which is why an item with **no value recorded shows a dash, not a nought**. A
settlement recovers nothing for an item nobody priced. The laptop is still gone;
the deduction is just zero. A nought in that cell would read as kit that is
genuinely worthless rather than kit nobody got round to valuing, so the report
prints a dash and counts them in the note.

## The three things to look for

The note at the bottom states each of these when it applies, in this order:

**Somebody who has left.** Marked `· LEFT` after their name, sorted to the top of
the list, and totalled separately as **Held by leavers**. This is the urgent
one — the company is not getting the item back by asking nicely, and if the
settlement has already been paid the recovery has been missed.

**An item with no value recorded.** Nothing will be recovered for it. Put a value
on the issued-asset record and the settlement will charge for it.

**An asset disposed on the register while still out.** The accounts say the
company no longer owns the thing somebody is holding. Either it came back and was
never marked returned, or it was written off while it was out of the building.

## The Register column

For anything linked to a fixed asset, this says what the asset register makes of it:

- **On the register** — capitalised and still owned. Nothing to do.
- **Not capitalised** — the item was never put on the books. Ordinary and expected:
  a company phone bought out of petty cash has a description and no link.
- **Disposed** — written off on the books while still out. See above.
- **Not on register** — the record points at a fixed asset that cannot be read.
  Either the Accounting module is switched off, or the asset row is gone. The
  report says so rather than guessing that the item is on the books.

Somebody's last day still counts as employed, so they are not marked `LEFT` until
the day after — the same reading Headcount Movement uses, so a person is never on
the payroll in one report and gone from another on the same date.

## Using it

The date is an **as-at**: it shows what was out on that date, so a laptop issued
after it does not appear and somebody who left after it is not yet a leaver.
Useful for asking what was outstanding at a year end.

**Days out** counts from the issue date to the date you are reading, so it grows
as long as the item stays out.

Within leavers and within current employees, the **longest-outstanding item is
listed first**.

## Roles and permissions

Requires `ReportView`, gated behind the Lifecycle module being enabled. The
Register column additionally needs Accounting; without it the column reads *Not on
register* and the rest of the report is unaffected.

Read-only. Marking an item returned, putting a value on it, or linking it to a
fixed asset all happen on the issued-asset record itself.
