## What the register is for

A submittal is something that has to be approved before it can be built or bought: a
shop drawing, a product data sheet, a sample, a method statement.

This register is a **schedule control**, not a filing cabinet. Its one job is to answer
*when does this have to go in* — and on most real jobs its first useful act is to show
how many of those dates have already passed, because nobody did the subtraction when
the programme was agreed.

## The submit-by date is computed

You cannot type it. It is the date the item is **needed on site**, less four durations:

- **Fabrication** — how long it takes to make.
- **Procurement** — how long it takes to buy.
- **Review period** — how long the contract gives the reviewer.
- **Buffer** — the planner's cushion, kept separate so it can be seen and argued about.

A typed submit-by date goes stale the day the programme moves, and a stale one is worse
than none because somebody trusts it. So the five figures are stored and the answer
never is.

The form shows the answer while you are still typing the figures. A ninety-day
fabrication entered against a date six weeks away is a problem worth learning there,
rather than in the month the steel does not arrive.

## Long lead

Tick it by hand. Ninety days is long lead on a six-month job and ordinary on a
four-year one, so no threshold in this application can decide it for you.

What the flag buys is a register you can filter down to the twenty items that will stop
the job, out of four hundred that will not.

## Rounds, not a status

Every submission opens a **round**. The register keeps all of them.

That matters because the review period was budgeted **once**. A submittal that has been
round three times has spent float nobody planned, and every individual step still looks
reasonable while the fabrication date is missed. A single status column loses that fact
completely — the **Rounds** badge is what shows it.

Two rounds cannot be open at once. Record a round's return before submitting again,
otherwise the register loses count of how many times the item has been round.

## Approved as noted counts as approved

It means "build it, with these corrections" — the fabricator starts, so the schedule is
released. Treating it as unapproved would show a job blocked on hundreds of items that
are all actually proceeding.

## Whose lateness it is

**Late to submit is your own risk.** You are the one who submits, so the register shows
it, ranks it and lets you chase it — but there is nothing to notify, and no action here
offers one.

**A reviewer past their period is a different thing.** Sixteen days beyond a fourteen-day
review period is sixteen days of your programme somebody else has taken. The
**Turnaround** column on each round shows the days taken against the period that applied
when it went out — snapshotted onto the round, so an overrun measured against fourteen
days keeps saying fourteen whatever the contract is later renegotiated to.

## Notify a late review <!-- requires: ConstructionDelayUpdate -->

**Notify** on an overrunning round raises a delay event dated **the day the review
period expired** — not the day the drawing came back, and not today. The notice period
runs from the event, and the event is the reviewer passing their own deadline.

It asks for the delay register's permission rather than this one. Serving notice on the
employer is not the same act as filing paperwork.

## Recording a return

Date it **the day it came back**. The turnaround this register reports is the reviewer's,
and dating it from when you filed it flatters them.

A returned round needs a result. "Came back" with nothing against it leaves the register
unable to say whether the item may be built.
