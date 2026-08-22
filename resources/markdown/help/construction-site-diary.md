## What the diary is for

A record of what happened on site, one entry per job per day, written by somebody
who was there.

It is not a formality. Three things on it are what claims are actually built from,
and none of them is the prose:

- **Hours lost and working conditions** — a weather-based extension of time is made
  of these, not of a paragraph about rain.
- **Plant idle against working** — this is a standing-time claim.
- **Events with a responsibility against them** — the diary is the only document
  written while anybody still remembers whose fault something was.

## One diary per day

A second entry for the same job and date is refused. That constraint is the feature:
two site diaries for one day is how a dispute starts, and the version somebody
produces later is the one nobody can rebut.

If a day already has an entry, open it and add to it.

## Approval locks the day <!-- requires: ConstructionDailyLogApprove -->

**An editable site diary is not evidence.** A diary anybody can revise afterwards
proves nothing about what happened, so signing off locks the day *and everything on
it* — manpower, plant, events. Your name and the time go on the record, because "who
signed this day off" is the first question asked when a diary is produced.

Writing the diary and signing it off are separate permissions on purpose.

**Reopening** exists, and needs a reason. Refusing outright would leave a wrong
signed diary wrong for ever, and a company in that position keeps its real diary in
a notebook — which is worse than a recorded correction by a wide distance. Reopening
clears the approval, so the day has to be signed off again rather than carrying an
approval that predates the change.

## Weather and conditions

Fill in **Conditions** and **Hours lost to weather** even on a normal day — zero on a
workable day is a fact, and a blank is not.

The default is *workable*, deliberately: a default of *stopped* would make every
unfilled diary read as a claim.

## Manpower

Headcount and hours by trade and by company. Where you run the cost-control module
the trade and company come from their registers; where you do not, type them. A
diary must never be unfillable because of a licence.

These hours are the **denominator of every safety rate**. An incident rate is
incidents per so many hours worked, and without a manpower return there is no hours
figure — so the safety page has to refuse to print a rate rather than print a
flattering one. They are also what dayworks are priced from.

## Plant: three columns, not one

**Working**, **idle** and **broken down** are recorded separately because they are
three different arguments. Idle against working is a standing-time claim; breakdown
is usually your own risk. A claim that mixes them invites the whole thing to be
refused.

Fill in **Why it stood**. Idle hours with no reason are the ones nobody recovers
next month, and the table flags them.

## Events, and the notice clock

Delays, instructions, visitors, stoppages, inspections, incidents — with times, hours
lost, and **whose responsibility** it was. Write the responsibility down on the day.

The **Notified** column is the most valuable thing on this screen. An event that cost
time, is not your own risk, and has no delay event behind it is money you have lost
and do not yet know you have lost — the notice period is running from the day it
happened, and nothing is chasing it.

**Raise delay event** on the row starts that clock, dated the day the thing happened
rather than today. The diary line then points at it and stops being reported as
unnotified.

## Deliveries: the docket, not the price

What arrived, who signed for it, and against which order — as written on the docket.
There is no rate and no amount here on purpose. The priced goods receipt is a
different document, raised by whoever is costing the job, and keeping the two apart is
what lets you run a diary without a cost ledger at all.

Leave the docket number blank if the load came with no paperwork. The table says *no
docket* in plain sight rather than hiding it, which is better than the delivery being
recorded on the back of a drawing.

**Condition** has three values and the middle one earns its place. *Accepted with
damage* is the load you took because the pour was booked — say so in the notes, because
that is the fact that disappears when somebody marks it simply accepted.

### Still standing on site

Tick **Still standing on site** for material that is delivered and not yet built in.

Where you keep a site store, the figure a certificate quotes comes from **stock**, not
from this tick — stock goes down as material is used and a diary tick never does, so a
count of ticks would overstate what is on site by everything already consumed, and the
error would grow every month. The tick is the site record beside it.

Where you keep no site store, these dockets are the whole of the evidence, and the
certificate says so rather than showing nothing.

### Link priced receipt

Where you run cost control, **Link priced receipt** joins the docket to the goods
receipt accounts raised for it. If one was raised against this docket number it is
already selected for you.

The **Priced receipt** column is empty until that happens, and every empty one is
material the job has received that the cost ledger has never heard about: cost
understated, margin overstated, and no error anywhere to find. Filter the tab by *Not
yet receipted* and it is a short list.

## Photos

Fifty photographs a day for two years does not belong in the document register — it
would be thirty thousand containers buried around the drawings the register exists for.
So they live here, with a caption, a **subject**, and a place from the job's location
tree.

**Concealed work** is the subject that carries money. Reinforcement before the pour,
services before the screed, a membrane before the backfill: the photograph is the only
evidence the work was ever there, and it is what stops an element being opened up.

### Promote to register <!-- requires: ConstructionDocumentCreate -->

For the handful that become as-built evidence, **Promote to register** creates a real
container in the document register with an identifier, a location and the same file.
The photograph stays here where site can find it.

Promoting puts it in the register at *work in progress*. It does not publish it —
publishing is the register's own approval gate and a different permission again.

A promoted photograph can no longer be deleted from the diary, because the register's
container points at the same file. A register listing a file nobody can produce is
worse than a photograph nobody wanted.
