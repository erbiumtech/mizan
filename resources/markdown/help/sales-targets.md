## What this is

What somebody is expected to bring in, and over what period.

## What it measures <!-- requires: SalesTargetView -->

- **Value of deals won** — the usual one, at the exchange rate each deal recorded.
- **New leads** — how many were brought in.
- **Calls and meetings logged** — activity.

**Read the activity figure as effort, not as performance.** Somebody with forty calls and no
wins may be working a harder patch than somebody with five and two wins. A target on activity
that gets treated as a performance score produces more logged calls, not more sales.

## Attainment is calculated. Commission is not paid. <!-- requires: SalesTargetView -->

The **Achieved** column works out attainment from won deals whenever you look at it — it is
never stored, so it cannot drift from the deals behind it.

**Nothing pays a commission.** That is deliberate: paying it means somebody enters an amount
as a pay component on a payslip, after approval. Computing commission straight into payroll
would mean the first disputed deal becomes a payroll incident — the same reason a performance
rating never changes a salary here.

The CRM produces the number. A person approves it.

## Roles and permissions

**View**: `SalesTargetView` — including the person the target is set for. **Set and change**:
`SalesTargetUpdate`. Deliberately not part of the Deals permissions: somebody who works deals
should not be able to edit the number they are measured against.
