## What this is

The banks the company or its employees hold accounts with — a simple
reference list used elsewhere for bank codes and IBFT routing, not itself a
place where money is recorded.

## Creating one

Click **New**. You'll set:

- **Bank Code** — the IMD code used in IBFT bank files. Required, unique.
- **Bank Name** — full legal name.
- **Bank Short Code** — a common abbreviation (e.g. HBL, MCB), optional but
  useful for quick recognition in lists.
- **Active** — inactive banks stop being offered when adding a new account or
  employee, without touching records that already reference them.

## Where it's used

Banks are referenced by **Company Bank Accounts**, **Employees** (their salary
account's bank), and **Beneficiaries**. When a payment's payee banks with the
same institution as the paying account, the app can route it as a same-bank
transfer (BT) instead of IBFT automatically — this only works if both sides
name the same Bank record.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `BankView` | See the bank list |
| `BankCreate` | Add a bank |
| `BankUpdate` | Edit a bank |
| `BankDelete` | Delete a bank |

Accountant, Manager and CEO can all view/create/update. **Only CEO and
Administrator can delete.** This resource belongs to the **Accounting**
module.
