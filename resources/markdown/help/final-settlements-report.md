## What this shows

Every person who left during the financial year to date, what their settlement was
made of, and what it came to — encashed leave and gratuity owed to them, notice
recovery, unrecovered advance, unreturned kit and other deductions owed back.

It lists **leavers**, not settlements. That distinction is the point of the report:
somebody who left and was never settled appears nowhere else in the application,
because every other view of a settlement starts from a settlement that exists.

## Nothing here posts to the ledger

A settlement is a **proposal**. Approving one records that the figure was agreed; it
does not pay anything and writes no journal entries. Paying it goes through a payslip
or a payment as usual.

So unlike the year-end reports, there is no ledger balance for this one to reconcile
against — there is nothing posted to reconcile *to*. What the report offers instead
is three disagreements that nothing else will tell you about.

## The three things to look for

**Not built.** Somebody left and no settlement was ever prepared. These sort to the
top of the list and their figures are all dashes, because nothing was computed —
not because everything came to nought.

**Net differs.** The stored net is no longer the sum of its parts. The net is
written when a settlement is built and again when it is approved — but **not** when
it is edited, and every component on the form is editable. Type a notice recovery
into a draft and the stored net is left behind. The **Net** column on this report
always shows the sum of the parts on the row, so the row itself adds up; the status
cell is where the disagreement is reported.

**Kit moved.** A **draft** is still quoting an unreturned-kit figure that no longer
matches what the employee actually holds — either the laptop came back, or more was
issued since. Rebuilding the settlement will correct it.

That last check runs on drafts only. An approved or paid settlement's figures are
frozen by agreement — the builder refuses to rebuild one for exactly that reason —
and flagging those as stale would be arguing with the agreement rather than
reporting a problem. The *net differs* check does apply to them, because a stored
figure disagreeing with its own components is a defect either way.

## The two totals

**Net payable** is what the company owes leavers. **Owed back** is what leavers owe
the company. They are deliberately kept apart rather than summed: a negative
settlement is legitimate — an unrecovered advance and an unreturned laptop can leave
somebody owing — and adding the two gives a figure that is neither what you owe nor
what you are owed. Both are somebody's job.

## Using it

The period is the **financial year to date** — 1 July to your date — matched on the
leaving date. Somebody who leaves later in the year appears once that date arrives.

A **dash in a component column means nought**, not missing: ten columns of noughts
is unreadable. Notice recovery is never computed by anything, so a dash there means
nobody decided one was due. A row whose *every* column is a dash is a *Not built*
row, and the status says so.

The **footer totals each component**, so the arithmetic can be checked down the
columns as well as across the rows.

The table is wider than the pane and scrolls sideways.

## Roles and permissions

Requires `ReportView`, gated behind the Lifecycle module being enabled. Read-only —
building, editing, approving and paying a settlement all happen on the settlement
itself.
