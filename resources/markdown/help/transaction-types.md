![Transaction Types](/images/help/transaction-types.png)

## What this is

A transaction type is a label for *why* money is moving — Salary, Rent, Food,
Utilities — used to categorize payments and to route them to the right
default account automatically.

## Creating one <!-- requires: TransactionTypeCreate -->

Click **New**. You'll set:

- **Name** and **Code** — the code is a stable slug (e.g. `salary`, `rent`)
  used internally; the name is what's shown on screen. Both must be unique.
- **Default Account** — the expense or liability account debited automatically
  when a payment of this type is approved. Optional, but leaving it empty
  means whoever records a payment of this type has to pick an account by hand
  every time.
- **Active** — inactive types stop being offered on new payments without
  affecting anything already recorded against them.

## Where it's used

Every **Payment** and every **Company Bank Account** can be tagged with a
transaction type — a bank account can be earmarked as the default source for
paying, say, Salary, so payroll payments pick the right account automatically.
Beneficiaries also carry a "usual transaction type," used as the fallback when
a payment doesn't specify one.

The transaction type named by `salary` in code (seeded as **Salary**) is
treated specially: a payment tagged with it is recognized as a payroll
transfer, which affects how its payment type (IBFT/RTGS/PAY) is resolved.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `TransactionTypeView` | See transaction types |
| `TransactionTypeCreate` | Add new ones |
| `TransactionTypeUpdate` | Edit existing ones |
| `TransactionTypeDelete` | Delete one |

Accountant, Manager and CEO can all view/create/update. **Only CEO and
Administrator can delete.** This resource belongs to the **Accounting**
module.
