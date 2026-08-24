## Why there are two ledgers, and why they have to be proved equal

Job costing keeps its own ledger. It has to: a cost entry carries a quantity, a
unit of measure and a **unit rate**, and the unit rate is the whole of cost
control — you can't recover "we poured 90 m³ at 2,000 a metre" from money alone.
Not every job cost is even a general-ledger event: burden charged at a rate,
internal plant at an hourly rate, a notional comparison. And job cost runs on a
monthly valuation clock while your accounts close on a fiscal year.

**The price of that separation is that nothing forces the two to agree.** These
screens are how that price gets paid. If nobody proves the two ledgers equal,
the job-cost ledger is simply wrong and nothing will say so.

## Nothing double-posts

Every cost entry carries a *GL treatment*, and it is a column rather than
something inferred from whether a journal entry is attached — because "we owe
the general ledger a posting" and "this deliberately never posts" look identical
as a blank, and a blank meaning "we don't know which" is exactly how a
sub-ledger drifts for a year unnoticed.

| Treatment | What it means | Who posted the GL side |
|---|---|---|
| **Mirrored** | The source document already posted. Job cost is a *dimension* of a cost the books already have. | Invoicing, Inventory, Payroll |
| **Posted** | Construction posted it, in a summary journal. | This module |
| **Pending** | It should reach the accounts and hasn't yet. | Nobody, yet |
| **Memo** | Deliberately outside the accounts. | Nobody, ever |

The rule is one sentence: **if a general-ledger document already exists for a
cost, construction posts nothing and mirrors it.** A supplier invoice, a payment,
a stock movement, a payslip — posting those again would double your cost.

What has no document behind it is construction's to post: site labour paid
outside the payroll, labour burden, internal plant hire, overhead allocation, the
two accruals, and WIP.

## Control accounts <!-- requires: ConstructionGlPost -->

This is a table rather than a setting in a config file, so you can name your own
accounts and the reconciliation report names them back. A hard-coded account code
would read the wrong account in every company whose chart of accounts somebody
else built — and it would fail silently, with the difference just coming out
wrong.

Each row does one or both of two jobs:

- **Kind** puts the account in scope of the report. Every account of kind *Job
  cost* makes up the GL cost total the difference is computed from.
- **Posting rule** makes the account a posting target — the account credited when
  construction absorbs labour burden, recovers internal plant, accrues goods
  received, and so on.

**At most one account per rule**, and that's enforced by the database rather than
by convention. Two candidate accounts for one rule would mean half your burden
absorbed to one and half to the other depending on the order somebody typed them
in, and no report would ever say so.

Leave the rule blank on an account you only want in the report's scope.

### The rules, and what each is a credit for

| Rule | Why it exists |
|---|---|
| Labour burden absorbed | Burden charged to jobs at a rate has to credit somewhere, or your overheads look free and every job looks expensive |
| Plant internal hire recovery | An owned machine has no invoice, so its log *is* the cost — and this is what its depreciation, fuel and repairs accumulate against |
| Site wages payable | Labour paid outside the payroll has no GL document at all, so construction owes both sides. A gang paid next Friday is money you owe today |
| Goods received not invoiced | The delivery arrived, the invoice hasn't |
| Accrued subcontract costs | The work was done, the certificate hasn't been issued |
| Overhead absorbed | An overhead allocation is construction-only, so construction posts it |
| Work in progress movement | The monthly WIP position |

**If you don't set one of these up, the cost it should absorb stays pending.**
That is on purpose — it's better than crediting a suspense account and getting a
tidy-looking journal nobody can explain. But it does not fix itself: burden
charged and never absorbed makes job cost exceed GL cost by exactly that figure,
growing every month, with no error anywhere. Read the preview.

## Posting a period <!-- requires: ConstructionGlPost -->

From **Cost periods**, on any open month.

**Preview first.** It tells you what would post — the total, the number of
journal lines, the entries behind them — *and what wouldn't*, with the reason.
The second half is the one worth reading.

The journal is **one line-pair per (account × cost type)**, not one per entry.
Per-entry posting would make journal entry lines the largest table you have and
your general ledger unreadable. A hundred thousand entries a month is not a
general ledger anybody can use.

What makes a summary posting honest rather than a shortcut is that it explodes:
every contributing cost entry is stamped with the journal entry it reached, so a
line of 1,847,320 on account 5020 answers the question "which four hundred
entries is that?" in one query. A summary posting that couldn't answer it would
be a defect.

The journal is dated to the **period end**, not to today — a June run made on the
4th of July belongs in June, and dating it to the run date would push a month's
cost into the next one every time somebody was late.

**A closed period will not post.** A journal dated into a signed-off month
restates the total a certificate and a WIP snapshot were built on.

### Reversing a posting <!-- requires: ConstructionGlPost -->

A **reversing journal**, not an unposting, and it needs a reason. The accounts
are what somebody has already read; deleting a line they read is worse than
showing them the line that cancelled it.

The cost entries go back to *pending* — which is what they are. The cost is still
on the job and no longer in the accounts, and the report should say so. Marking
them memo would balance the report and lose the cost.

## Closing a month <!-- requires: ConstructionPeriodClose -->

**Check the close** first. It lists every gate and says which are shut and why —
a control that says "cannot close" without saying why is a control people learn
to force.

Two gates stop a close outright:

- **The two ledgers must agree.** A month nobody has reconciled blocks as well,
  and that's worse than an unbalanced one: a second ledger nobody proves is a
  second ledger that's wrong, so a gate catching only *proved* differences would
  wave through every company that never runs the report.
- **Nothing may be awaiting the general ledger.** Posting refuses to reach a
  closed month, so closing with pending entries orphans that cost from your
  accounts permanently. The blocker names the control account that's missing, if
  that's the reason.

One more blocks conditionally: an **unlocked work-in-progress position**. A
locked position doesn't move — that's the point of locking it. One left unlocked
against a closed month keeps recomputing from a forecast that has since changed,
so the figure the bank was shown and the figure on your screen drift apart with
nothing to say when they parted. A company that computes no positions isn't
stopped by this.

Everything else is a **warning**: late costs, a job that expected a position and
hasn't got one, accruals that were never rolled. Not fatal, and hidden warnings
are how a month gets closed on facts nobody was shown.

A month that closes balanced is marked **reconciled** rather than just *closed*.
The distinction is deliberate — see below.

## Closing over a difference <!-- requires: ConstructionPeriodForceClose -->

Sometimes the report has to go out. **Close it anyway** does that, and needs a
reason.

**It corrects nothing.** No plug entry, no balancing figure, no adjustment of any
kind. Both ledgers stay exactly as they were and anything unexplained stays
visible in every later month until its cause is fixed. What gets recorded is your
name, your reason, and **every check it overrode, in words** — because a reason
with no facts beside it ("closing anyway, the client needs the report") tells
whoever reads it in a year nothing about what was known at the time.

A forced close is marked **closed**, never **reconciled**. That's how the period
list tells a month that was proved from a month that was signed for.

## Cost periods, and the door that isn't there

A period is created by the first cost that lands in that month. There's no form
to create one, because a table of empty future months is a list of things that
look closable.

**Roll accruals into this month** is what opening a month means: it reverses
every accrual still standing and re-raises whatever is still outstanding from
today's facts. It belongs to *opening* rather than closing for a specific reason
— if the reversal doesn't run, the accrual and the real invoice both sit in your
ledger and the job costs double for a month. A step attached to opening runs
before anybody looks at the figures. A step attached to closing runs after
everybody has.

Safe to run twice: the second run finds nothing to reverse and recomputes rather
than adds.

**There is no reopen.** Reopening a signed-off period to slot one late invoice in
invalidates the WIP snapshot, the client certificate and the GL summary that all
depended on that month's total. A late cost lands in the earliest open period
with its original incurred date preserved and a *late* flag set — which is a fact
the Late Costs report can show you, rather than a silent restatement of a month
somebody signed.

## What to look at first

The **Awaiting the GL** column on Cost periods. A number there that never goes
down is the failure this whole section exists to catch, and it looks like nothing
at all until somebody compares the two ledgers.
