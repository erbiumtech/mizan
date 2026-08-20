## Why this register matters more than it looks

A valid claim lost to a missed notice is the commonest way a contractor gives money
away — and it is the only failure in this system that leaves **no trace at all**.
The event happened, nobody wrote to the Engineer inside the contractual window, and
the entitlement is simply gone. There is no wrong number for anyone to find.

So this register is built around one date, and a nightly email chases it.

## Raise events early <!-- requires: ConstructionDelayUpdate -->

**Before you know what the delay is worth.** The claimed days and cost are optional
precisely because they are usually unknown on the day: access was blocked, a drawing
did not arrive, the ground was not what the survey said. What you need on the record
that day is *that it happened* and *when*.

Site staff can raise events. That is deliberate — the people who watch access being
blocked are on site, and an event they cannot record is an event nobody records.

## The notice clock

**Notice is due `event date + notice period`.** The period comes from the contract
when the event names one, and from your default (28 days, FIDIC 20.1) when it does
not.

That date is **frozen onto the event** when you save it. Changing the contract's
notice period next month will not move a deadline somebody has already been warned
about — and the number of days it was based on is shown beside it so the date can be
explained rather than just trusted.

The *Clock* column counts down. When it turns red the period has run out with
nothing served.

### What the nightly email does

At **14, 7, 3, 1 and 0 days**, and then once when the date passes, everyone holding
`ConstructionDelayUpdate` gets a mail — once per threshold, never once a day. A job
that mails the same warning every morning trains you to filter it, and then the one
that mattered is filtered too.

The mail says what will be **lost**, not that a date is approaching. Where nothing
has been quantified yet it says the whole entitlement goes, because that is worse
rather than vaguer.

> It needs `schedule:run` on cron. Without it the register still works and nothing
> chases you, which is most of the value gone.

## Recording notice

The date the notice **went to the Engineer**, not the day you type it. Notice served
on the 3rd and recorded on the 11th is notice served on the 3rd, and the difference
is often the whole argument.

**A late notice is recorded, not refused.** Whether lateness bars the claim depends
on the contract, on prejudice and sometimes on the certifier's discretion — refusing
the entry would delete the only evidence of what actually happened. The row says the
notice was late and leaves the argument to the people having it.

## Time-barred

Computed from the dates, never stored: a stored flag stops agreeing with the dates
underneath it. Judged as at whatever date you ask about, so "was this claimable when
we looked in June" stays answerable.

The **Time-barred** filter is the list of money already lost. It is worth opening
monthly even if nothing is wrong, because a register with nothing in that list is
the only proof the clock is being watched.

## Particulars, and determining

Particulars are the detailed submission that follows the notice, on their own clock
(42 days from the notice by default — measured from the notice rather than the event
because that is the date you control).

**Determining is a separate permission**, `ConstructionDelayDetermine`, held by
Manager and above. Awarding days moves the completion date and decides whether
liquidated damages can be levied at all, so it is kept away from whoever raised the
claim.

Two rules the service enforces:

- **You cannot award more than was claimed.** Sixty days against a claim for thirty
  is a different event, and it needs its own notice.
- **Awarding nothing is a rejection**, and is recorded as one. A "determined" event
  showing zero days reads as an oversight to whoever finds it next year.

Grounds are required either way. The other party reads them.

## Concurrency

`Concurrent with` records that two events delayed the same work at the same time.
That is the whole argument in most extension-of-time disputes, so it is a field
rather than a sentence in the notes — but nothing here *interprets* it. What
concurrency means for entitlement depends on the contract and the jurisdiction, and
this register records the fact and leaves the argument alone.

## Withdrawing

The row and its reference stay. A gap in the reference series is a question at
adjudication; "withdrawn on the 14th, because the access was given the next morning"
is an answer.
