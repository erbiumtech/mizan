## What this page is

Your work-in-progress position: for every job, what it has cost, what has been
earned on it, what has been billed for it, and the gap between the last two.
That gap is what a construction balance sheet is mostly made of.

Two positions, and each job has exactly one of them:

- **Contract asset** — costs and recognised profit in excess of billings. You've
  earned it and not billed it.
- **Contract liability** — billings in excess of costs and recognised profit.
  You've billed it and not earned it.

They are opposite sides of the balance sheet and **never netted against each
other**, at job level or in the totals. One job over-billed does not offset
another under-billed — those are two different conversations with the bank.

## Why these numbers are stored, when nothing else here is

Every other total in this module is computed on demand, because a stored total
goes stale silently. WIP is the exception, and it's a deliberate one.

A WIP position is a **judgement at a point in time** — the surveyor's forecast,
the surveyed percentage, the loss provision. Not a derivation from facts that
can't change. Recomputing last March's WIP with today's forecast would silently
restate a month that was signed off, reported to a bank, and used to work out
somebody's bonus. And the journal posted at the time would no longer be
explicable by any query you could run.

So: **an unlocked month recomputes every time you open it; a locked one is
frozen.** The page says which on every row, because a figure the bank has already
seen and a figure that will change by Friday look identical on paper.

## Percent complete, and why the job has to choose

Three methods, chosen **per job**, because one company legitimately runs more
than one:

- **Cost to cost** — cost to date over forecast final cost. Needs no surveyor,
  and is wrong in a specific direction: **a job running over reports more
  progress for spending more money.** Fine on a job whose costs track its output;
  dangerous on one that doesn't.
- **Surveyed** — measured progress against the budget, weighted by budget at
  completion so a 20,000 code doesn't count as much as a 20,000,000 one. Somebody
  walked the site to produce it.
- **Milestone** — the proportion of milestone value that has been **certified**.
  A milestone is achieved when the certifier says so, not when you say so.

**There is no default**, and a job without a method chosen gets no WIP position
at all — it appears at the bottom of the page instead, by name. Choosing for you
would mean choosing cost-to-cost, which flatters exactly the job that needs
watching.

The method is printed next to every percentage. 62% cost-to-cost is not the same
claim as 62% surveyed, and a bank asking which will not accept "the system said
so".

## Contract value, and the variations that aren't in it

Contract value is the original sum **plus approved variations only**.

Pending variations sit in their own column beside it, and are excluded. Both,
deliberately: a job whose contract value looks comfortable while eleven million
of variations sit unapproved is a job about to be in trouble, and one figure
cannot say that.

A variation approved at a **provisional price** counts as pending here, not
approved — the scope is agreed and the money isn't, which is exactly the state
worth seeing beside the contract value rather than inside it.

## Losses are taken whole, immediately

When the forecast final cost exceeds the contract value, **the entire expected
loss is recognised now** — at 4% complete or at 96%. It is never spread across
the months remaining.

Pro-rating a loss is the single most common way a loss-making contract reports as
profitable right up to the month it finishes. If your forecast says you'll finish
3,000,000 over, that 3,000,000 is on this page this month.

The provision reduces the contract asset, because a recognised loss is not an
asset — that's what recognising it means.

## Locking a month <!-- requires: ConstructionPeriodClose -->

Locking freezes the position. It recomputes once from today's facts and then
stops moving — locking a stale row would freeze figures that were true a
fortnight ago.

It's the same permission as closing the cost period, on purpose: freezing a WIP
position and closing a month are one decision made at one moment by one person.
Separate permissions would let a month be closed on figures nobody froze, or
frozen figures sit against a month still taking cost.

## Posting the movement <!-- requires: ConstructionGlPost -->

The journal posts the **movement** from the previous locked snapshot — not the
balance.

Reversing last month's whole position and re-posting this month's is a defensible
alternative, and it produces a profit and loss whose gross figures are enormous
and whose monthly movement has to be inferred. One approach, chosen once: two
code paths each choosing differently is the actual failure.

A month whose position hasn't moved posts nothing. A zero-value journal line is a
line in your accounts saying nothing happened, which is worse than the silence it
replaces.

The journal is dated to the **month end**, not to today. A June position posted
in July belongs in June.

You need a **work-in-progress** account and a **contract revenue** account
nominated under Control accounts. Without both, posting is refused rather than
crediting a suspense account — a figure in your books that nobody chose is worse
than a missing one.

## What to look at first

The bottom of the page: **live jobs with no position this month**. A WIP report
missing a job is a balance sheet missing a contract, and the usual reason is a
percent-complete method nobody has chosen — which takes two seconds to fix once
somebody knows.
