![Payments](/images/help/payments.png)

## What a payment is

A single amount owed to either an **Employee** (a salary, advance or
reimbursement) or a **Beneficiary** (rent, a supplier, a contractor). Payments
are what eventually get grouped into a bank file — see **Bank Payment File**
for that step.

## Creating one <!-- requires: PaymentCreate -->

Click **New**. You'll pick who's being paid (**Payable**), a **Transaction
Type**, the **Debit Account** the payment draws from (falls back to a
configured default if left empty), the **Amount**, free-text **Details**
(what shows on the payment and in the bank file — keep it short, max 140
characters), an optional **Reference** and **Value Date**, and a **Payment
Type**.

**Leave Payment Type empty unless you have a specific reason to override it.**
The app resolves it automatically: RTGS above Rs. 1,000,000 regardless of what
else applies, PAY for an employee salary transfer, BT when payer and payee
bank with the same institution, otherwise the beneficiary's own default, or
IBFT as the last resort. Once a payment is no longer a draft, this field locks
— it can no longer be changed.

## Status: Draft → Approved → Exported → Paid

A new payment starts as **Draft**. Someone with `PaymentUpdate` clicks
**Approve Payment**, which books the payment's journal entry and moves it to
**Approved** — only a Draft payment can be approved. From there it's picked up
into a batch on the **Bank Payment File** page, which marks it **Exported**.
Once the bank confirms it went through, it's marked **Paid**.

**Deleting is only possible while a payment is still Draft.**

## Reverting an export <!-- requires: PaymentUpdate -->

If a specific payment in an exported batch bounced, or its payee details were
stale, use **Revert from exported** on that one row rather than voiding the
whole batch — it goes back to where it was before release and reappears in
the next batch. Nothing is deleted. (The batch-level void, for when the whole
file itself is rejected by the bank, lives on **Bank Payment File**.)

## Why a payment won't release into a batch

A payment held back from the next bank file shows a reason on the Bank Payment
File page. The common ones:

- **It's a salary tied to a payslip the employee hasn't accepted yet** — or
  has rejected. Payroll is only releasable once the employee has acknowledged
  the payslip behind it.
- **Wrong kind of account number on file** — the payee banks with us, which
  needs their account number; we only send an IBAN to other banks.
- It's already been released in an earlier batch, or it's no longer in a
  releasable status.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `PaymentView` | See payments |
| `PaymentCreate` | Add a payment |
| `PaymentUpdate` | Edit, approve, or revert-export a payment |
| `PaymentDelete` | Delete a **Draft** payment only |

Accountant, Manager and CEO can all view/create/update/approve/delete
payments — payment approval doesn't require the same manager-level permission
that Journal Entry approval does. This resource belongs to the **Accounting**
module.
