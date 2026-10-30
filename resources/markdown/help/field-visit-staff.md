## What this is

Who does field visits.

A short list in most companies: eighty employees and six people on the road. Enrolling
somebody here puts them in the visitor picker; removing them takes them out.

## It does nothing until you switch it on <!-- requires: FieldVisitDelete -->

Enrolment is off by default, and while it is off **anybody active can be named on a
visit** — which is how this has always worked. The list is still there, and still has
no effect.

Switch on *Only enrolled staff may be sent on visits* under Company Settings → Field
visits and the picker narrows to the people here.

That order matters: a list that silently did nothing would be worse than no list, so
the screen says which state you are in.

## Removing somebody

Takes them out of the picker. It changes nothing about visits they have already done —
those are a record of what happened, and a rep who has left the road still visited the
client last March.

## Roles and permissions

`FieldVisitView` to read it. Enrolling and removing need `FieldVisitDelete`, which is
the narrowest right the module has that is held by the people who make staffing
decisions.
