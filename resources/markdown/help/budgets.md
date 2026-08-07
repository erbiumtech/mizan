A budget is what you intend to earn and spend in a fiscal year, account by
account. Once one exists, **Reports → Budget vs Actual** measures the ledger
against it.

## What a budget is made of

A budget belongs to **one fiscal year** and holds **one figure per account per
month**. You do not type twelve numbers: you give a figure for the year and it is
divided evenly across the year's months. The months come from the fiscal year's
own dates, so a short first year gets the months it actually has rather than a
notional twelve.

Only **income and expense** accounts can be planned. A balance-sheet account —
cash, a loan, equipment — is a position at a moment rather than an activity over
a period, so there is nothing for a monthly plan to compare against.

Only accounts that can **receive a posting** are offered. A group heading like
"5000 Expenses" never has entries of its own — they go to its children — so
budgeting against it would report the whole year as unspent.

## Creating one <!-- requires: BudgetCreate -->

1. **Accounting → Budgets → New budget.**
2. Name it. The year on its own is fine — "2026-2027" — and a revision wants a
   name that says so: "2026-2027 revised after Q1".
3. Pick the fiscal year. **This cannot be changed later**: the monthly rows are
   dated to that year's months, and moving the budget would leave every one of
   them pointing at a month the new year does not contain.
4. Add a line per account, with the figure for the whole year.
5. Save.

Nothing stops you planning some accounts and not others. Anything you leave out
still appears on the report, marked **unbudgeted** — which is usually the row
worth looking at.

## Adjusting a single month <!-- requires: BudgetUpdate -->

An even twelfth is wrong for plenty of real costs: school fees land in three
months, bonuses in one, heating in four. After saving, open the budget and use
the **Monthly Plan** tab. Filter to an account, edit the months that differ.

The yearly total on the first tab is always the sum of the months, so raising
December raises the year by the same amount. Nothing is hidden from you and
nothing is reconciled behind your back.

**Saving the budget form does not undo your monthly work.** A yearly figure is
only re-spread when you actually change it. Open a budget, change the name, save
— every month stays exactly where you put it.

## Superseding a plan <!-- requires: BudgetUpdate -->

Turn **Active** off rather than deleting. An inactive budget stops being offered
on the report but stays here, which is what lets you show what was originally
agreed alongside what replaced it. Several budgets may exist for one year.

## A closed year is fixed

Once a fiscal year is closed, its budgets can no longer be edited or deleted.
The ledger for that year is frozen, so the only thing changing the plan could
achieve is to alter what last year gets measured against — which is precisely the
number a variance report has to be unable to move.

## Roles at a glance

| Role | What they can do here |
|---|---|
| Administrator | Everything, including deleting a budget |
| Accountant | Create budgets, edit them, adjust months |
| Manager | Create budgets, edit them, adjust months |
| CEO | The same, plus deleting a budget |
| Employee | No access to company budgets |

## Troubleshooting

**An account is not in the list.** It is either not an income or expense
account, not active, has "allow manual entry" switched off, or has child
accounts — plan the children instead.

**"The name has already been taken."** Another budget for the same fiscal year
already has that name. Two plans for one year need names that tell them apart.

**Two rows for the same account.** Not allowed — one line per account, then
adjust its months on the Monthly Plan tab.

**The Monthly Plan tab is empty.** The plan is written when the budget is saved.
Add your accounts on the Budget tab and save first.
