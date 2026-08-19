## Trades and workers

Two small registers that everything about labour cost hangs off. A **trade** is
what a pair of hands does — steel fixer, mason, carpenter, plant operator. A
**worker** is one of the hands.

Labour is booked against a worker, and what the hour costs comes from the
**Labour rates** register, not from either of these screens.

## Why workers are not employees

On a site, most hands are not on the payroll. No payslip, no login, paid weekly
through a gang leader. If this register required an employee record for each of
them you would have three hundred people who are not employed sitting in your HR
register, where Leave, Payroll and the joiners-and-leavers screens would all see
them.

So the **Engaged as** column exists, and it is the distinction the HR register
cannot make:

| Engaged as | What it means |
|---|---|
| **Employee** | On the payroll. Link the employee record so what a job was charged can be compared with what was actually paid. |
| **Direct** | Paid directly by you and not on the payroll — daily-wage, weekly, casual. The commonest case, and the default. |
| **Supplied** | Supplied by a gang leader or agency, who is the one that gets paid. Name them under *Supplied by*. |

All three work on the same site, and only the first belongs in Employees.

> **Supplied labour is not a subcontract.** A gang leader sending six masons on
> an hourly arrangement is labour you direct and cost by the hour. A subcontract
> is work somebody else prices, claims for and gets certified — that lives under
> Contracts. Recording the first as the second is how labour-only supply ends up
> outside the labour cost of the job it was working on.

## The employee link <!-- requires: ConstructionLabourUpdate -->

Only offered when the Employees module is switched on, and only for somebody
engaged as an employee. Without that module the whole register still works and
the column simply stays empty — this is cost control, not HR.

## Started and left

Fill these in. The **In use** switch only ever answers about today, and "was
this person on site in March" is asked at exactly the moment somebody disputes a
week's hours.

## There is no delete <!-- requires: ConstructionLabourUpdate -->

Not for a trade and not for a worker.

A worker with cost against their name is a row somebody will ask about later.
And a trade carries the history of what it cost, which is what you price the next
tender with — deleting the trade would take that with it. Switch either off with
**In use** instead: it comes out of the pickers and the record stays.

## Trades: the usual cost code

A convenience, not a rule. It fills the cost code in for you on a site sheet, and
you can change it there. One trade works to several codes on a job of any size,
so the labour record's own code is always the authority.

Leave it blank if you code labour by activity rather than by trade.

## What you need before labour can be costed

1. At least one **trade**.
2. The **workers**, with a trade each.
3. A **labour rate** — at minimum a company default. Nothing guesses a rate, and
   an hour with no rate cannot be costed at all.

That last one is deliberate: a week of labour costing 0.00 looks like a healthy
number and is not.
