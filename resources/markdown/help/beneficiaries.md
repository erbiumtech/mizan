## What this is

Everyone the company pays who isn't an employee: landlords, caterers,
suppliers, contractors. Payments can be made out to either an Employee or a
Beneficiary — this is where the latter are set up.

## Creating one

Click **New**. You'll fill in their name, bank details (**Bank**, **Account
No**, **IBAN**), an optional identifier (**CNIC** or **NTN**), contact
details, and:

- **Usual Transaction Type** — what the company typically pays this
  beneficiary for. Used as the fallback on a payment or subscription that
  doesn't specify its own type.
- **Default Payment Type** — IBFT, BT, ACH, RTGS or LBC. The app will
  auto-resolve this at payment time if left on the default (IBFT), based on
  amount and whether the payee banks with the same bank the payment is drawn
  from — you only need to override it for a specific requirement.
- **Contractor** — a person paid for work rather than a landlord, utility or
  supplier. No tax is withheld from a contractor payment, and it appears on
  the **Contractor Payments** report. Turning this on reveals an **engagement**
  field describing what they do.
- **Petty cash custodian** — the one beneficiary who receives the month-end
  petty cash replenishment payment. **Only one beneficiary can hold this at a
  time** — switching it on for one turns it off for whoever held it before.

## Monthly subscriptions

Open a beneficiary and go to **Monthly subscriptions** for standing payments —
rent, a retainer — that recur every month rather than being entered by hand
each time. Each subscription has an amount, a due day of the month, its own
transaction type and paying account (or falls back to the beneficiary's own
defaults if left empty), and a start date with an optional end date for an
agreement that isn't open-ended.

**Raise this month** generates a draft payment for every active subscription
that hasn't been raised yet for the current month — safe to press more than
once, since it skips anything already raised. Raised payments land in
**Payments** as drafts, ready for the normal approval flow; nothing is paid
automatically.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `BeneficiaryView` | See beneficiaries |
| `BeneficiaryCreate` | Add a beneficiary, or raise/create a subscription |
| `BeneficiaryUpdate` | Edit a beneficiary |
| `BeneficiaryDelete` | Delete a beneficiary |
| `PaymentCreate` | Required (in addition to the above) to use "Raise this month" |

Accountant, Manager and CEO can all view/create/update beneficiaries. **Only
CEO and Administrator can delete.** This resource belongs to the
**Accounting** module.
