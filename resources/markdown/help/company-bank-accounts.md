![Company Bank Accounts](/images/help/company-bank-accounts.png)

## What this is

The company's own bank accounts — the accounts payments are actually debited
from, as opposed to Banks (the institutions) or Beneficiaries (who gets paid).

## Creating one <!-- requires: CompanyBankAccountCreate -->

Click **New**. You'll set:

- **Title** — how the account appears in lists, e.g. "Main Operating Account."
- **Bank** — which institution it's held at.
- **Account No** and **IBAN** — the identifiers used in bank files.
- **Purpose (Transaction Type)** — earmarks this account for a category of
  payment, e.g. Salary, Rent. Not required, but a transaction type's default
  account is what payments of that type debit automatically.
- **Default for its type** — when on, this becomes *the* account used for its
  transaction type. **Only one account can be default per type** — turning
  this on for one account automatically turns it off for whichever account
  held it before.
- **Active**.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `CompanyBankAccountView` | See company bank accounts |
| `CompanyBankAccountCreate` | Add one |
| `CompanyBankAccountUpdate` | Edit one |
| `CompanyBankAccountDelete` | Delete one |

Accountant, Manager and CEO can all view/create/update. **Only CEO and
Administrator can delete.** This resource belongs to the **Accounting**
module.
