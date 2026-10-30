## What this is

Check-ins that were turned away, and why. Read-only.

Until now a refusal threw an error on somebody's phone and vanished, which is the
wrong half of the story to keep.

## The two problems, which look nothing alike here

**A rep standing in the right place whose phone put them somewhere else** appears
once, with a vague reading — and the *Reading* column says "vaguer than the distance"
when the phone's own margin of error is larger than how far away it claims they were.
That is not somebody in the wrong place; that is a phone that cannot tell.

**A rep trying from home every morning** appears every morning, at the same distance,
with a confident reading.

In an exception log neither appears at all.

## Why something was refused

- **Too far from the place** — outside the radius on the client's pin.
- **The reading was too vague** — the phone's accuracy was worse than the limit under
  Company Settings, so nothing could be judged either way.
- **Not enrolled for field visits** — the person is not on the staff list, and
  enrolment is switched on.
- **No position sent** — the client sent no coordinates at all.

## Nothing is refused until you ask for it

Check-ins are only turned away when *Checking the position* is set to **refuse**. On
**flag**, which is the default, the verdict is recorded on the visit and the check-in
goes through — so this list stays empty and the Position column on Field Visits is
where to look.

## Roles and permissions

`FieldVisitView`. Nobody can edit or delete a row: a log of refusals somebody can tidy
is not a log.
