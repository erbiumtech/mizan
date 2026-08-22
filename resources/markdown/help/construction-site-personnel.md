## A name is all that is required

Everybody on a construction site belongs on this register, and **most of them are
somebody else's employees**. So a name is the only required field — a register that asked
for an employee record would list the people who happened to be on the payroll, which is
a small and unrepresentative slice of the people on site.

**One row per person per job.** Somebody inducted on the tower is not inducted on the
annexe, and a site that treated one induction as covering every job would have a gate
register that means nothing.

## An induction is not permanent

Record the date **and the date it runs out**. Somebody inducted fourteen months ago on a
site whose induction lasts a year is not inducted, and a register that could not say so
would report full coverage on a site with none.

The register shows **never inducted** and **induction lapsed** as different things,
because they are different conversations: one person has to be put through an induction
and the other has to be put through it again.

## Tickets, and the mandatory flag

Training, licences, certifications, medicals, authorisations. Each with an expiry, and
**leave the expiry blank only where it genuinely does not expire** — a date nobody has is
worse than an honest blank.

**Mandatory for the work they do** is the field that turns an expiry into a stoppage. A
first-aid certificate lapsing is a gap in the file; a confined-space ticket lapsing on
somebody who is in a chamber this morning is an emergency. Only the flag tells them
apart.

**Renew through the Renew action rather than by editing the date.** Renewing clears the
warning history so the ticket can warn again next year; editing the date alone silences
it for good.

## Cleared to work

The **Cleared** column is the gateman's question: inducted, and no mandatory ticket
lapsed.

**Left site** takes somebody off the active register. That is what makes a lapsed ticket
historical rather than urgent, and it stops their tickets appearing in the daily
warnings. The row stays.

## The daily warning

`construction:check-competency-expiry` runs every morning and warns at 60, 30, 14 and 7
days and on the day itself — **once per threshold, not once a day**. A warning that
arrives every morning is one somebody filters, and then the one that mattered is filtered
too.

It goes to whoever holds the register-keeping permission, because a lapsed ticket is
fixed by renewing it or moving somebody, and both are the register's own work.

## Toolbox talks

**The topic is required and the time is a datetime.** "Toolbox talk" against a date proves
a meeting happened and says nothing about what anybody was told; and a talk at seven in
the morning before the shift is a different fact from one at four in the afternoon.

**Attendance is what is counted, not the number of talks.** Forty talks to two people
each is not a briefed site. Use **Add everybody on the register** at the gate — it copies
the names onto the sheet, so the record keeps saying who was there even if the register
changes afterwards, and pressing it twice does not double the count.

A talk with **nobody recorded** is named as such rather than counted as zero attendance:
one that was given and not written up is a different problem from one nobody came to, and
only the first is worth chasing.

**What prompted it** is worth filling in. A talk given after a near miss is a *response*,
and a register that could not tell one from a routine could not show a site learning
anything.
