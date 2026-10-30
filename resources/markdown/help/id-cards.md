## What this is

Staff ID cards: who has one, which card it is, and whether it is still good.

## Issuing one <!-- requires: EmployeeUpdate -->

**Issue a card** picks the employee and a date it is valid to — three years by
default, or blank for a card that does not expire. The artwork downloads straight
away, at the size of a bank card, ready for a card printer.

**Whatever card that person was holding is cancelled in the same breath.** One live
card each, and that is the whole reason the card number is its own number rather than
the employee code: somebody who loses a card is issued another, and a guard holding a
found card has to be able to tell which one it is. Cancelling the lost one would
otherwise cancel both.

The card number reads `2026-0041` — the year and a running number — because it is read
aloud down a phone, and a bare sequence tells nobody when the card was made.

## The QR

The same QR every document here carries. Scanning it recomputes from the live record
and answers *matches*, *does not match*, or *no such record* — showing the name, the
code, the designation and the card's status, and nothing else.

A cancelled card verifies as cancelled, which is why a card is never deleted. Deleting
it would make a cancelled card answer "no such record", which reads as a forgery
rather than as a cancellation.

The QR is absent where document verification is switched off under Company Settings.
The card is still a card.

## Cancelling one <!-- requires: EmployeeUpdate -->

**Cancel** stops the card verifying immediately and asks why. The reason matters
because whoever is holding the card is still holding it, and the verify page shows
what happened.

## Printing a batch

Select several and use **Print a sheet** — cards laid out on A4 with dashed cut lines,
for an office printer and a pair of scissors. Crop marks that need a guillotine are
crop marks nobody uses.

## Roles and permissions

`EmployeeView` to see the list; `EmployeeUpdate` to issue and cancel. Everybody can
see their own card.
