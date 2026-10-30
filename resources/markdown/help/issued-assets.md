## What this is

Kit somebody was given — a laptop, a phone, a SIM, an access card — and whether it came
back.

## Value is worth filling in <!-- requires: IssuedAssetCreate -->

**What has not come back is what a final settlement charges for.** That is the whole
reason this register earns its place, and it is why the value matters even for a phone
that was never capitalised as a fixed asset.

## Tag, vendor and warranty <!-- requires: IssuedAssetCreate -->

**Asset tag** is the sticker on it. It is not the serial number: a serial is the
maker's and a tag is yours, and an audit counts tags.

**Bought from** is the vendor, which is who you ring when it fails.

**Warranty until** is what decides whether a broken screen is a phone call or a
purchase. The list shows it in red once it has passed.

All three are optional. An asset with none of them is still an asset somebody is
holding, which is what this register is for.

## What kinds there are

The **Kind** dropdown is a list this company edits under Company Settings → Dropdown
Options, not a fixed set. One company issues vehicles and site radios; another issues
neither.

A kind somebody deactivates still shows on the assets that already carry it, so an old
record does not quietly blank itself.

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
