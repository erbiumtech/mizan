## What a contract is here

One contract on one job. The **head contract** you bill the employer under, and a
**subcontract** for each trade you pay — both in this register, separated by
*side*.

They share one screen because the arithmetic is the same in both directions: a
schedule of priced lines, variations against it, applications and certificates,
staged retention. The only real difference is which way the money goes, and the
question people actually ask — *what have we committed downward against what we
have secured upward* — cannot be answered across two screens.

## FIDIC, AIA or bespoke

The **contract family** decides the words and the paperwork, and nothing else:

| | FIDIC | AIA |
|---|---|---|
| The schedule | Bill of Quantities | Schedule of Values |
| A change | Variation (VO) | Change Order (CO) |
| What you submit | Statement | Application for Payment |
| What comes back | Interim Payment Certificate | Certificate for Payment |
| Who signs it | Engineer | Architect |
| Completion | Taking-Over | Substantial Completion |
| The defects clock | Defects Notification Period | Correction Period |

**The measurement basis is a separate question, and it matters.** Whether
quantities get remeasured is not a consequence of the family — AIA contracts are
routinely unit-price and FIDIC Yellow is lump sum. So the form asks both, and
gets both right.

Once anything has been certified, the family is **fixed**. Changing it would
re-label every certificate already issued, re-print them on a different form and
change how much retention is releasable today — with nothing recording that it
happened.

## The item schedule <!-- requires: ConstructionContractUpdate -->

The same rows whichever family you are in. AIA fills the item number, the
description and the scheduled value. FIDIC fills unit, quantity and rate and lets
the value follow. Fill in what your contract actually says.

**Put the cost code on the line.** It is optional and it is the field that pays
for itself: without it, what you certified and what it cost you cannot be
compared, which is the question the monthly commercial meeting is about.

Sections nest — use **Under section** for BoQ sections and continuation-sheet
subtotal groups. Only the lines under a section are claimed against; the section
itself just prints and subtotals.

## Executing the contract <!-- requires: ConstructionContractExecute -->

**Execute** freezes the scheduled values.

While a contract is a draft, the scheduled value follows quantity × rate as you
edit. From execution it is the figure the parties signed, and it stops moving —
even if somebody edits a quantity on a remeasured line later.

That matters more than it sounds. Every certificate reports *work completed to
date* against *scheduled value*. If the scheduled value could shrink after a
certificate was issued, the completed figure would exceed it, and the printed
form would be arithmetically impossible — not merely wrong.

After execution, a change to the schedule is a **variation**. It appends its own
line rather than editing yours, which is exactly how a change order prints:
listed after the original bill, with its own number.

## Who does what

| | Surveyor / commercial | Manager | CEO |
|---|---|---|---|
| Read a contract | ✓ | ✓ | ✓ |
| Raise and price one | ✓ | ✓ | ✓ |
| Execute it | | ✓ | ✓ |
| Delete a draft | | | ✓ |

Everyone with `ConstructionContractView` can read a contract, site team included
— people build to the specification, the dates and the damages, and a contract
they cannot open is one they cannot work to.

Deleting is refused on anything but an empty draft with no subcontracts under it,
whatever the permission says. A contract somebody outside this company holds a
copy of is ended by marking it **terminated**, which leaves the record standing.

## Things this screen deliberately does not show

- **The revised contract sum.** It is the original plus *agreed* variations, and
  it is computed rather than stored — see the variations screen, where the
  difference between agreed and provisionally priced is explained.
- **When the defects period expires.** Computed from the completion date plus the
  period in days, and shown on the register. Stored, it would stop agreeing with
  a completion date somebody corrected last week — and that disagreement is worth
  money.
