## What this does

Builds the bank file that actually pays a month's salaries — a Standard
Chartered iPayments-format CSV, one row per employee, ready to hand to the
bank. This is reached from the Reports hub, not the sidebar.

## Using it

Pick the **Fiscal Year**, **Month**, and a **Payment/value date** (defaults to
today). The page lists every payslip for that month with its release status:

- **Releasable** — the payslip is accepted and ready to pay.
- **Held back** — with the reason shown against the row. The most common
  reason is that the employee hasn't accepted their payslip yet; the row also
  flags a missing or unusable bank account (IBAN/account number) before it
  would otherwise cause a rejected bank transaction.

Click **Download CSV** to release the batch. This is a real action, not a
preview:

- Every releasable payment gets stamped with a **batch reference**
  (`SAL-2026-07-B1`, `-B2`, …) and marked released — they will not appear in
  the next batch, even if you generate one for the same month again.
- The CSV downloaded is exactly the set of payments just released, so the
  file the bank receives and what the app now considers "paid" can never
  disagree.
- Held-back payments are simply skipped and stay available for a later batch
  once whatever is blocking them is resolved.

**This is the same underlying Payment record the Bank Payment File page
manages** — a salary is one row shared between the two, not two independent
copies, so releasing it here means it will not also appear over there.

## Voiding a batch

A **Void batch** action is available for a batch already released, for when
it needs to be pulled back before the bank actually processes it. This
reverses the release rather than deleting anything, so the payments return to
being releasable again.

## Roles and permissions

Gated on `ReportView` plus the `payroll` module being enabled — it's grouped
with the other reports here rather than under its own resource permission.
Of the seeded roles, **Accountant**, **Manager**, **CEO** and
**Administrator** hold `ReportView`; **Employee** does not and never sees the
Reports hub this is reached from.
