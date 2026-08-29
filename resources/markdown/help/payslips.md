![Payslips](/images/help/payslips.png)

## What a payslip is

One employee's pay for one month of one fiscal year — there can only ever be
one payslip per employee/month/fiscal-year combination; trying to create a
second is refused, with a pointer to edit the existing one instead.

## Creating one <!-- requires: PayslipCreate -->

Pick the **Employee**, **Month** and **Fiscal Year** first — everything else
derives from those three. Most figures (basic wage, medical allowance, and
several allowances/deductions) are pulled straight from the employee's salary
settings for that period and shown read-only or pre-filled.

A handful of fields can be typed over what the settings say: **Device
Allowance**, **Petrol Allowance**, **Bonus**, **Extra Work Hours**,
**Advances**, **Meal Deduction**, and **ESI / Health Insurance**. If what you
type differs from the settings figure, a hint appears naming what it's
overriding — that hint is the only place this disagreement is visible, so
correcting one month by hand is fine, but it's worth reading before saving.
**Withholding Tax**, **Total Earnings**, **Total Deductions** and **Net
Salary** are always calculated, never typed.

Saving recalculates everything from scratch using the current settings and
whatever overrides you entered — editing an existing payslip re-derives the
whole thing the same way a create does, it does not just patch the one field
you touched.

## What happens automatically on save

None of this needs a separate step — it all follows from saving the payslip:

- **Posts to the ledger.** A journal entry is created or updated for this
  payslip's figures with no approval step (see the Journal Entries help for
  why: anything posted by another part of the app skips the manual workflow).
  Deleting the payslip reverses it.
- **Advance recovery.** If the Advances module is enabled and this employee
  has an active advance, the instalment is recorded against it. Recalculating
  the payslip corrects the recovery rather than double-counting it; deleting
  the payslip gives the instalment back.
- **Expense claim settlement.** Same idea, if Expenses is enabled — approved
  claims this payslip reimburses are settled, and released again if the
  payslip is deleted.
- **Annual tax reconciliation.** Every save recalculates this employee's
  projected annual income and tax for the fiscal year (see Annual Taxes).

## The payroll month lock

Every payslip belongs to a **Payroll Month** (see Payroll Runs), created
automatically for its month if one doesn't exist yet. Once that month has
been **signed off**, none of its payslips can be edited, added, or deleted —
not even by an Administrator. The fix is to reopen the month first, which
requires a reason and is on the record.

**The one exception is the employee's own Accept/Reject** — acknowledging a
payslip is not a change to its figures, so it still works even after the
month is signed off.

## Sending and downloading

**Send to employee** emails the PDF and sends the same file on WhatsApp,
using whichever of the employee's email/phone are on file — the confirmation
dialog says exactly where it's about to go before you send it, and flags
either channel as unavailable if the employee record is missing it. This is a
deliberate manual step, not automatic on save: a payslip recalculates every
time it's corrected, so nothing goes out until payroll says the figures are
final. Already-sent payslips show **Resend** instead — safe to use again if
a figure was wrong the first time.

**Download** produces the same PDF on demand — always current, never a stored
file — and is available both to payroll staff and to the employee the
payslip belongs to.

## Accepting or rejecting

The employee (or whoever manages them) can **Accept** or **Reject** their own
payslip while it's still pending — the option disappears once a review is
recorded. Rejecting requires a reason and notifies everyone holding
`PayslipUpdate`; it's advisory only and blocks nothing, so payroll follows up
directly rather than the system stopping anything automatically. When an
administrator does this on the employee's behalf (impersonating them), the
payslip records that explicitly rather than presenting it as the employee's
own acknowledgement.

**The reason is shown in the list**, under the red *rejected* badge, shortened to
fit the row — hover it for the whole sentence. It is also at the top of the
payslip's own edit screen, above the figures somebody is about to correct, and in
the payslip's Comments tab, where it opens the conversation. And it travels with
the money: the salary payment carries it, and the Salary Bank File names it as the
reason that row is held back.

## The conversation about an objection

A rejection is advisory for the payslip and **not** for the salary: the payment it
pays is held back until somebody deals with the objection.

**The objection starts a conversation.** The reason the employee types is written
into the payslip's **Comments** tab as its first comment, and both sides can reply
there. That thread is the record of what was discussed; the Comments tab shows a
red *Needs a reply* flag while an objection is still waiting for one.

**Where an employee finds it.** Open the payslip with **View** on its row in the
list — Edit is the payroll team's screen and needs `PayslipUpdate`, which staff
outside payroll do not have. The View page shows the month, the pay, the objection
if there is one, and the Comments tab underneath, where they can reply. It is their
own payslip and their reporting downline's; somebody else's is not offered.

There are then two ways to deal with it, and both are on the payslip's row, its
**View** page and its **Edit** page:

- **Send back for review** — you agreed, and changed the payslip. Say what changed;
  the note is emailed to the employee and added to the thread, the review goes back
  to *pending*, and they accept the corrected figures. The salary stays held until
  they do, because it is a new figure awaiting a fresh acknowledgement.
- **Close objection** — the payslip was right. See below.

## Closing an objection <!-- requires: PayslipUpdate -->

**Close objection** is the second one, and it is in three places — on the payslip's
row in the list, on its **View** page above the conversation, and on its **Edit**
page, where the figures were just corrected. It marks the objection resolved,
records who closed it and when, emails the employee the last reply beside their own
words, and releases the salary. The payslip then reads *Rejected, answered* rather than
*Accepted*, because nobody accepted it: the objection and the answer both stay on
the record, and the list and the edit screen show them together.

**Or mark it solved in the thread.** The Comments tab has **Mark solved** on each
comment, for anybody with `CommentResolve`. On an ordinary comment it means what it
says; on the objection itself it is the same act as *Close objection* — it releases
the salary and emails the employee — because a resolved objection that left the
payslip rejected would look finished and change nothing.

**It cannot be the first thing that happens.** Until somebody has replied in the
Comments tab the button reads *Close objection (reply first)* and does nothing —
releasing a salary over a complaint nobody responded to is exactly what this step
exists to prevent. *Send back for review* has no such condition: there is nothing
left to argue about once the payslip has been corrected.

**Marking a comment solved is not the same as closing the objection** — unless the
comment *is* the objection, which is the first one in the thread. Marking a reply
solved tidies the thread and leaves the review where it was.

Only somebody with `PayslipUpdate` sees it — Administrator, Accountant, Manager and
CEO — so an employee cannot overrule their own objection. It can be done once; a
second closure would be a running commentary rather than a decision.

Filter the list by *Rejected* for the objections still waiting on somebody, and by
*Rejected, answered* for the ones already dealt with.

## Roles and permissions

| Action | Permission required |
|---|---|
| View | `PayslipView` (Employees see only their own and their reporting downline's; Administrator and Accountant/Manager/CEO see all) |
| Create / Edit | `PayslipCreate` / `PayslipUpdate` |
| Delete | `PayslipDelete` |
| Send / Resend | `PayslipUpdate` |
| Download, Accept, Reject | `PayslipUpdate`, **or** being the payslip's own employee (or their reporting manager) |

Of the seeded roles, **Employee** holds only `PayslipView` (scoped to
themselves and their downline) — enough to see, download and respond to
their own payslip, never to create or edit one. **Accountant**, **Manager**
and **CEO** hold the full set. This resource lives in the `payroll` module.
