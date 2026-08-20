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

## What is not here yet

**Deliveries** and **photos** are the diary's two remaining children. The delivery
row carries the flag that makes a certificate's "materials presently stored" column
defensible, and that figure is already computed from stock — reconciling the two
needs a decision rather than another table, so it has its own sub-phase.
