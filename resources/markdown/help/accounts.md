## What the Chart of Accounts is

Every account the company's books can post against — Asset, Liability, Equity,
Income, or Expense — lives here. Journal entries, payments, invoices, payroll
and everything else that touches the ledger ultimately posts against one of
these accounts.

## Creating an account

Click **New**. You'll fill in:

- **Code** — a unique reference, e.g. `1000`, `5100`. Cannot be reused.
- **Name** — e.g. "Cash in Hand", "Basic Salary Expense".
- **Type** — Asset, Liability, Equity, Income, or Expense. This decides the
  account's **normal balance** automatically: Asset and Expense accounts are
  debit-normal, everything else is credit-normal. You don't set this directly.
- **Parent Account** — optional. Groups accounts into a hierarchy (e.g. all
  bank accounts under one "Cash & Bank" parent). Only accounts with no journal
  lines of their own can be picked as a parent — see below for why.
- **Active** and **Allow Manual Entry** — both default on.
- **Description** — free text.

## Group accounts vs. postable accounts

An account can only receive journal lines if it's **active**, **allows manual
entry**, and has **no sub-accounts**. The moment an account is given a child,
it becomes a group header and can no longer accept entries directly — this
happens automatically and silently, so pick a leaf account (one with no
children) when posting, not a category-level one.

**Giving a posted-to account a child fails on save.** If an account already
has journal lines, the app refuses to let it become a parent — you'll see an
error naming the account and telling you to either pick a different parent or
move the existing entries off it first. This exists because a misfiled child
account under an already-active one has, in the past, silently taken out every
future posting to it.

## Balances

Each account shows its own **balance**, built up from posted journal lines.
The **calculated balance** shown in reports and hierarchy roll-ups adds every
descendant's balance to the account's own — so a parent's total always
reflects everything posted beneath it, even though it never receives postings
itself.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `AccountView` | See the Chart of Accounts |
| `AccountCreate` | Add new accounts |
| `AccountUpdate` | Edit existing accounts |
| `AccountDelete` | Delete an account |

Accountant, Manager and CEO can all view/create/update accounts. **Only CEO
and Administrator can delete one**, and only if it has no journal lines and no
sub-accounts — an account with any ledger history can never be deleted, by
anyone; the only option at that point is to make it inactive.

This resource belongs to the **Accounting** module — a company without it
licensed or enabled loses the Chart of Accounts entirely.
