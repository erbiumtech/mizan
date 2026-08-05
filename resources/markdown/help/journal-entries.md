![Journal Entries](/images/help/journal-entries.png)

## What a journal entry is

A journal entry is a set of lines against different accounts that must balance:
the total of every **Debit** must equal the total of every **Credit**. You cannot
save an entry that doesn't balance, and you cannot submit one for approval that
doesn't balance — the app checks both times.

## Creating one

From **Journal Entries**, click **New**. You'll fill in:

- **Entry Date** — the accounting date the entry belongs to.
- **Entry Type** — General, Adjusting, Closing, or Reversing. General covers
  almost everything; the others are for period-end and correction entries.
- **Reference** and **Memo** — free text, for your own records.
- **Fiscal Year** — which year this entry books into.

Save it, then add **Lines** — each one an Account, a Debit *or* a Credit (never
both on the same line), and an optional Description. An account only appears in
the list if it's active, allows manual entries, and has no sub-accounts of its
own (postings always go against the lowest-level account).

The entry starts life as **Draft**. Nothing else in the app sees it yet — not
the register, not any report — until it's Posted.

## Submitting for approval

Once the lines balance, click **Submit for Approval**. This is available to
whoever created the entry (or anyone else who can edit it) and moves it to
**Pending Approval**.

## Approval

Someone with approval rights reviews it and either:

- **Approve**s it, moving it to **Approved**, or
- **Reject**s it with a required reason, moving it to **Rejected**.

**You cannot approve your own entry.** This isn't a permission you can be
missing — it's checked on every entry, for every role, including
Administrator: whoever is listed as the creator is blocked from approving it,
full stop. If the Approve button is greyed out on an entry you'd expect to be
able to approve, this is almost always why.

A rejected entry isn't gone — it's still the same entry, still editable. Fix
whatever the rejection reason pointed at and **Submit for Approval** again.

## Posting

Once **Approved**, click **Post Entry**. This is the step that actually updates
account balances — before this, an approved entry has had no effect on the
books at all.

**Posting cannot be undone.** There is no "unpost," no edit, no delete once an
entry reaches this state. If you posted the wrong thing, the fix is below.

Posting will also fail if the entry's fiscal year has since been closed — close
out anything you need to post before closing the year.

## Where it shows up

**Only Posted entries ever appear** — on the Account Register, the Trial
Balance, the Profit & Loss, and the Balance Sheet. A Draft, a Pending Approval,
an Approved-but-not-yet-posted, or a Rejected entry shows up nowhere but the
Journal Entries list itself. If a number you expected to see is missing from a
report, check whether the entry behind it has actually been posted yet.

## Fixing a posted mistake

Click **Reverse Entry** on the posted entry. This does *not* undo it — it
creates a **new** entry, dated today, with every line flipped (debits become
credits and vice versa), which is approved and posted automatically. The
original stays on the books exactly as it was; the reversal is what brings the
balance back. Both entries remain visible forever, which is the point — the
correction is on the record, not a hidden edit.

## Deleting an entry

Only possible while an entry is still **Draft** or **Rejected** — the moment it
reaches Pending Approval, it can no longer be deleted, only carried through to
Rejected or Posted. Deleting requires Administrator access.

## Entries you didn't create yourself

A number of other actions in the app post their own journal entries
automatically, with no Draft, no approval step, and no way to reject them:

- Recording a **payment**
- A **bank transfer** between two of the company's own accounts
- Adding a transaction directly on the **Account Register**
- **Issuing or paying an invoice**
- **Stock** purchases, sales, adjustments and write-offs
- **Posting a payslip** (individually or in bulk)
- Monthly **depreciation**, and disposing of a **fixed asset**
- **Currency revaluation**
- **Closing a fiscal year**
- **Importing from GnuCash**

These appear on the register instantly, already Posted. That's expected — the
approval you'd expect from a manual entry has already happened at the source
(the payment was recorded, the invoice was issued). To correct one of these,
go back to the record that caused it — void the payment, correct the invoice —
rather than trying to edit the journal entry it produced.

## Roles at a glance

| Role | Create / edit / submit | Approve / reject / post / reverse | Delete |
|---|---|---|---|
| Accountant | ✅ | | |
| Manager / CEO | ✅ | ✅ | |
| Administrator | ✅ | ✅ | ✅ |
| Employee | | | |

## Quick answers

**Why can't I approve this entry?**
Either you don't hold the Approve permission, or you created the entry
yourself — the two are easy to confuse, since the button looks the same either
way.

**Why can't I edit or delete this entry?**
It's no longer Draft or Rejected. Once it's Pending Approval, Approved, or
Posted, the only way forward is through the approval flow, or Reverse if it's
already posted.

**I posted the wrong amount — what do I do?**
Reverse the entry, then create a new one with the correct figures. Don't try
to edit or delete a posted entry — you can't, and it wouldn't leave a record of
the correction even if you could.

**A payment/invoice created a journal entry with no approval step — is that a bug?**
No — anything posted automatically by another part of the app skips the manual
approval flow by design. Correct it at the source.
