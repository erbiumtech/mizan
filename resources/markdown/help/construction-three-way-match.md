## Ordered, received, invoiced

Three documents describe the same money, and they should agree:

- what the **order** said you would buy;
- what the **delivery note** said arrived;
- what the **invoice** says you owe.

This screen lists the order lines where they do not, and lets somebody say why that
is acceptable.

Every figure on it is **worked out from the documents each time you open it**. There
is no stored match status to go stale — the only thing this screen writes is the
decision.

## Two different variances, two different conversations

**Quantity variance** — what arrived against what was ordered. 39.6 tonnes against
40 is a short delivery somebody accepted at the gate. That is a conversation with the
supplier's driver, or with the storeman.

**Price variance** — what was invoiced against what the goods that arrived were
ordered at. That is a different conversation with a different person: the buyer
agreed a rate and the supplier has billed another.

The price is compared against what was **received**, not against the whole order. An
invoice for half an order is not a price variance, it is a part invoice — comparing
it with the whole order would put every staged delivery on this report.

## What counts as a variance

Two thresholds, both shown at the top of the screen, and a difference has to clear
**both** to appear:

| | Default | Why |
|---|---|---|
| Quantity | 2% | a lorry-load rounds; a contract that tolerates nothing puts every weight ticket in front of a human |
| Price | 1% | tighter, because a price difference is a fact about an agreement rather than about a lorry |
| Ignored below | 1,000 | a control that fires on a 10 difference trains people to accept without reading |

Your company can set its own, and setting a percentage to **zero** genuinely means
zero — the fallback only applies to a line nobody has filled in.

## Where a figure says "—" instead of a number

Two cases, and neither is a zero:

- **The line was ordered as a lump sum**, so there is no quantity to compare. The
  screen says so.
- **The invoice stated money but no quantity**, which is how most supplier invoices
  are written. Only the price is compared, and the screen says that too.

A dash means *unknown*. Showing zero there would read as *agrees*, which is the
reassuring wrong answer — the same reason schedule variance says "unavailable"
rather than 0.00 on an unphased budget.

## Accepting one <!-- requires: ConstructionVarianceAccept -->

**Accept**, with a reason. The line leaves the report and keeps your name, the date
and what you wrote.

The reason is not a formality. It is the answer when somebody asks in six months why
the job carries 40,000 more than it was ordered at. *"Supplier substituted 20mm for
16mm at our request, agreed with the QS on the 14th"* is that answer. *"ok"* is not.

A line already inside tolerance cannot be accepted — there is nothing to decide, and
putting a decision on the record about a difference nobody was asked to make would
only make the register harder to read later.

## Nothing here is blocked

No invoice is held up and no payment is stopped by a variance on this screen.

That is deliberate. A match variance that blocked payment is how a site ends up with
a supplier refusing the next delivery over four thousand nobody could authorise. The
control is the **decision**, recorded with a name against it — which is a control,
where a blocked payment queue is just a queue.

## Who does what

| | Site | Surveyor / buyer | Manager | CEO |
|---|---|---|---|---|
| Read the report | ✓ | ✓ | ✓ | ✓ |
| Accept a variance | | | ✓ | ✓ |

Site can see it, because the person who took the delivery often knows exactly why
39.6 tonnes arrived. Accepting is a manager's: it puts money on a job that nobody
ordered at that figure.
