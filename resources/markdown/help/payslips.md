![Payslips](/images/help/payslips.png)

## What a payslip is

One employee's pay for one month of one fiscal year — there can only ever be
one payslip per employee/month/fiscal-year combination; trying to create a
second is refused, with a pointer to edit the existing one instead.

## Creating one

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
