## What this is

The kinds of ticket your company takes — "Billing query", "Site down", "How do I…" — and what
you have committed to for each.

Every ticket is filed under one. It is also what the SLA report groups by, so the list is worth
keeping short and worth keeping spelled one way.

## Response and resolution times <!-- requires: TicketView -->

Each category carries two commitments in minutes: how fast a first reply goes out, and how fast
the thing is fixed.

**Both are measured, never enforced.** Nothing is blocked, escalated or reassigned because a
clock ran out — the elapsed time is a fact about the past, and refusing to record that a problem
was fixed would make the register wrong as well as late.

**Leave one blank when you have promised nothing.** Blank means no commitment and never counts
as a breach. A zero would read as instantly overdue, which is why blank rather than 0 is the way
to say it.

## The priority a ticket starts at <!-- requires: TicketUpdate -->

A new ticket takes its category's default priority, and whoever raises it can change it there
and then. Changing the default here does not touch tickets already raised.

## Adding and removing <!-- requires: TicketUpdate -->

Names are unique. Two categories meaning the same thing split their own SLA figures in half and
nothing reports that they did.

**Switch a category off rather than deleting it** once tickets have been filed under it: it stops
being offered on new tickets, and every ticket already in it keeps its category and its SLA
history. Deleting one leaves those tickets with no category at all — they are not removed, but
what they were about is.

## Roles and permissions

**View**: `TicketView`. **Add**: `TicketCreate`. **Edit**: `TicketUpdate` — a separate grant from
replying, because changing a category changes what the company has promised. **Delete**:
`TicketDelete`.
