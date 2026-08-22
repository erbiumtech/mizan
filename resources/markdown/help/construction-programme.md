## This is a register, not a scheduler

**The programme is stored here; it is not solved here.** There is no Gantt editor, no
critical-path calculation, no float solver and no way to make one date move another —
and there will not be.

That is a decision, not a gap. Your accepted programme was produced in P6 or Asta and
*that* is the contractual document, because it is what was submitted and agreed. A
second scheduler here that disagreed with it would be worse than none: it manufactures a
number the quantity surveyor quotes in a claim and the planner does not recognise, and
the disagreement stays invisible until an adjudication.

What contract administration actually needs from a programme is four things, and they
are all here:

- a **milestone with a contractual date**, so extension of time and damages have
  something to move;
- **planned against actual**, with percent complete;
- somewhere to **hang a delay event**;
- an **activity id** an RFI or a submittal can point at.

None of those needs a critical path to be calculated.

## Baseline against planned

Two pairs of dates, and keeping them apart is the whole of an extension-of-time
argument.

**Baseline** is the accepted programme — what was submitted and agreed. Entitlement is
measured against it, and the register's **Late** column reads it.

**Planned** is the current programme, which moves every month.

One pair would make every re-programme silently retire the delay that caused it. That is
why lateness here is reported against the baseline and not against the plan: a job that
has re-programmed around its own delays reports zero against its plan and the truth
against its baseline.

## Float and the critical path are imported

`Total float` and `On the critical path` come out of the tool that produced the
programme. **Nothing in this application derives them**, and on an imported activity you
cannot edit them — re-import to change them.

The **source** is printed beside the float for a reason: those are somebody else's
numbers, and a report quoting a critical path has to be able to say which tool produced
it. A job whose critical activities are all *Entered here* is a job where somebody typed
a critical path.

## Predecessors are stored, never solved

Record them so an imported network round-trips and so the register can say what is
blocking. **Adding a link moves no date.** If a successor's planned start is wrong, it is
wrong in P6 too — fix it there and re-import.

A negative lag is a lead, which is how overlapping trades are programmed, so the field
takes negative numbers.

The **Blocking** column on the predecessors tab is what the tab is for: which of these
has not finished. "Cladding cannot start" is worth little; "and the two activities in
front of it have not started either" is a conversation.

## Milestones, and the two flags that are not the same

**A milestone the contract names** is a date extension of time can move.

**Liquidated damages apply** is a separate flag, and deliberately: a contract names
dates it does not price. Sectional completion of the car park may be contractual with no
damages against it at all. This second flag is what turns lateness into money, so it is
never inferred from the first.

## Exposure: the number this register exists for <!-- requires: ConstructionProgrammeView -->

**Exposure** is days a priced contract milestone is late against the accepted programme,
*less the extension of time actually awarded* on the delay events hung on it.

Damages accrue against that remainder. It is money leaving with nothing wrong anywhere —
and it can only be computed because the baseline is kept apart from the plan and because
delay events record what was *determined* rather than what was claimed.

The navigation badge counts the milestones carrying it.

## Recording progress <!-- requires: ConstructionProgrammeProgress -->

Progress is a **separate permission** from maintaining the programme. Percent complete
and actual dates are what every schedule index is computed from, and the person who
reports 80% is not usually the person who owns the consequence of it being 60%.

Two rules the form enforces, because the alternatives are not facts:

- **100% needs an actual finish, and an actual finish needs 100%.** A row saying the work
  is both finished and unfinished makes the schedule index and the milestone useless.
- **Progress needs a data date.** 40% as at the 1st and 40% as at the 30th are different
  facts, and without the date neither can be compared with last month.

## Budgeted value

Record it if you have it, and note that it is **deliberately not reconciled** to the
bill of quantities. The programme and the bill are two different decompositions of the
same job; forcing them to agree produces a fiction somebody then has to maintain.
