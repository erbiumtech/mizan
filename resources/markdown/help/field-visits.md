## What this is

A visit by a rep to a lead, a deal or a customer, with the phone's location at
check-in and check-out and the visited person's own confirmation that it
happened. It is a next action with a place and a witness.

## Planning one <!-- requires: FieldVisitCreate -->

Click **New**, choose what is being visited and who is going, and set the
time. Reps see their own visits; managers see their team's.

## On the day

Open the list on a phone. **Check in** reads the location once and records
the time. **Check out** reads it again, takes the outcome and notes, and sends
the person visited a link to confirm the visit — by email when there is an
address, otherwise by WhatsApp. The map link on the row is where the phone
was; nothing judges the distance, because a contact has an address rather
than a point.

## Pinning the client's place <!-- requires: FieldVisitUpdate -->

A contact carries an address, not a point, and a distance to an address needs a
geocoder. So the point comes from somebody standing there: **Pin this place** saves
where they are, once, and every visit to that client afterwards is judged against it.

It is prefilled from the check-in, because the person pressing it is usually the
person who just arrived. The radius is how close counts as "here" — a shop front is
twenty metres, a factory with its office at the back of the yard is two hundred.

**Nothing is judged until a place has a pin.** The Position column stays blank, which
is deliberately not the same as "away": a reader has to be able to tell "we looked and
they were not there" from "we never looked".

## The Position column

- **At the place** — inside the radius.
- **Away from the place** — outside it, with how far shown underneath.
- **Could not tell** — the phone's own margin of error was worse than the limit under
  Company Settings. Indoors that is most readings, and calling somebody absent for
  standing inside a shop is the fastest way to have the whole thing switched off.
- **Blank** — nothing judged it.

Under *Checking the position* you choose what happens: record only, flag, or refuse.
**Flag** is the default, because a company that has not thought about it wants to see
the problem rather than have its reps locked out of their own calls on a bad-signal
day. Refusals are listed under Refused Check-ins.

## A photo

Optional, on check-out. The shop front, the meeting, the delivery — whatever makes the
visit recognisable a month later. A visit without one is still a visit, and a rep in a
basement should not be stopped from checking out.

## Confirmation

The page behind the link shows only the date, the rep and the company, and
asks for a name and one tap. It proves presence to the rep's manager; it is
not a signature on anything, and the page says so. Links are off until
**Settings → Field visits** switches them on, and **Resend link** sends a
fresh one.

## What the client thought

The confirmation page also asks, optionally, how the visit went — one to five, and a
line of comment. It is optional and stays optional: the page is asking a favour of
somebody who was not expecting to be asked for anything, and a required rating is how
a confirmation rate falls.

What comes back shows on the visit and on the per-rep report.
