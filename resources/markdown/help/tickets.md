## What this is

Things customers have asked for help with, and what was done about them.

## The SLA is measured, not enforced <!-- requires: TicketView -->

A category carries the response and resolution times your company has committed to. The list
shows when one has been missed.

**Nothing is ever blocked because time elapsed.** A ticket that broke its SLA can still be
replied to, resolved and closed — the elapsed time is a fact about the past, and refusing to
record that the problem was fixed would make the register wrong as well as late.

A ticket **nobody has answered yet** whose time is already up shows as breached too, not only
one that was answered late. The breach that has not finished happening is the one still worth
acting on.

## An internal note does not start the clock <!-- requires: TicketUpdate -->

Replies can be marked **internal** — staff talking to each other, never shown to a customer.

An internal note deliberately does **not** count as your first response. Otherwise a company
could meet its commitment by writing a note to itself, which is exactly the failure a measured
SLA exists to make visible.

That flag also matters for the future: if a customer portal is ever built, internal notes are
already separated, rather than requiring somebody to go back through every reply deciding which
were private.

## Reopening <!-- requires: TicketUpdate -->

A reopened ticket **keeps its original first-response time** — that is what the customer
actually experienced — and its reopen count goes up. "This has been reopened three times" is
usually the more useful fact than any single timestamp.

## What this is not

**Not a mail server.** Inbound email is not parsed into tickets. Doing that means an IMAP poller,
threading heuristics and bounce handling — a subsystem rather than a feature, and this
application has no inbound mail path at all.

Tickets are created here, and **channel** records how the customer actually asked: phone,
WhatsApp, email, in person.

## Roles and permissions

**View**: `TicketView`. **Create**: `TicketCreate`. **Reply, resolve, reopen and edit
categories**: `TicketUpdate`. **Delete**: `TicketDelete`.
