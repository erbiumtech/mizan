## What this is

Kit somebody was given — a laptop, a phone, a SIM, an access card — and whether it came
back.

## Value is worth filling in <!-- requires: IssuedAssetCreate -->

**What has not come back is what a final settlement charges for.** That is the whole
reason this register earns its place, and it is why the value matters even for a phone
that was never capitalised as a fixed asset.

## The link to the books <!-- requires: IssuedAssetCreate -->

**On the books as** points at the fixed-asset register when the laptop is capitalised.
It is optional, and absent entirely for a company that keeps its books elsewhere: a
phone bought out of petty cash has a description and no link.

## Returning <!-- requires: IssuedAssetUpdate -->

**Mark returned** records the date and the condition. From that moment it stops being
charged on a final settlement.

A returned asset **cannot be deleted**. The row is the evidence it came back, and
deleting it is exactly how somebody comes to be charged for a laptop sitting on a shelf.

## Roles and permissions

**View**: `IssuedAssetView` — employees hold this for their own kit, so they can see what
is outstanding against their name. **Create / update / delete**: `IssuedAssetCreate`,
`IssuedAssetUpdate`, `IssuedAssetDelete`, which they do not.
