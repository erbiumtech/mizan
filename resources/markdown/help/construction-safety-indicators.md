## What this page is

Your safety numbers, computed from the incidents, inspections, permits, talks
and induction records already in the system. **Nothing on this page is stored.**

That matters more than it sounds. A first-aid case becomes a lost-time injury
the day somebody doesn't come back to work, and reclassification like that is
completely normal. A stored LTIFR would still be reporting last month's
classification of an incident that has since changed. This one is recomputed
every time you open it, so it is simply right.

## Exposure hours, and why the page sometimes refuses

Every frequency rate is *incidents divided by hours worked*. Without the hours
there is no rate — and this is the one place in the application where we refuse
to show you something rather than showing you a zero.

If the denominator were treated as nought, every rate on this page would print
as `0.00`. That reads as a **perfect safety record**. What it actually means is
that nobody filled in a diary. There is no more dangerous wrong answer this
software could give you, so instead the page says **insufficient exposure data**
and tells you what is missing.

### Where the hours come from

**One source per job, chosen on the job itself** — either the site diary's
approved manpower returns, or hours booked in Timesheets. Never both.

Counting both halves every rate, because the same people get counted twice in
the denominator. A halved rate is worse than a missing one: it looks like a
number you can act on. So the job names one, and this page prints which one it
used underneath the figure.

Three things can leave you without hours, and the page distinguishes them:

- **No source chosen on the job.** Somebody has to decide. Until they do,
  nothing here can be computed.
- **The source's module isn't licensed.** Either license it, or point the job
  at the other one.
- **The source has nothing in it for this period.** For the diary, note that
  only *approved* diaries count — an editable diary isn't evidence, and a rate
  computed from drafts would move every time somebody saved one.

## The rate base, and why it's printed everywhere

A frequency rate means nothing without the base it was computed on. The same
site is a factor of ten apart quoted per 100,000 hours and per 1,000,000, and
both get called "the standard" by somebody.

So the base is on the face of every figure on this page, and it's on the section
heading too — because a heading is what ends up in a screenshot in a client
pack. Change the base in the form and every figure changes with it, visibly.

If you're comparing your number against a client's benchmark or a competitor's
published figure, **check their base first.** Most arguments about safety
statistics are actually arguments about denominators.

## The lagging indicators <!-- requires: ConstructionIncidentView -->

- **Lost-time injury frequency rate.** Injuries that stopped somebody working,
  plus fatalities. The headline figure most clients ask for.
- **Total recordable incident rate.** Everything beyond first aid: medical
  treatment, restricted work, lost time, fatality, occupational illness. First
  aid is deliberately excluded — including it is the commonest way a recordable
  rate ends up incomparable with anybody else's.
- **Accident frequency rate.** Every injury including first aid. Kept beside the
  recordable rate rather than instead of it, because a site whose recordable rate
  is falling while this one is flat has got better at classification, not at
  safety.
- **Severity rate.** Days lost rather than a count of cases. Ten one-day cases
  and one ten-day case give the same severity and very different frequency, and
  the pair is what tells you which you've got.

## The near-miss ratio

How many near misses and unsafe conditions you record per lost-time injury.
It needs no exposure hours, so it still works when everything above it is
refusing to print.

**A large number here is good.** It means people on your site report things. A
small number is usually not a safe site — it's a site where nobody bothers.

Where there's been no lost-time injury there's nothing to divide by, and the
page says so rather than showing you a flattering ratio.

## The leading indicators

These are the ones you can still do something about. None of them needs a
denominator, which is why they're on the same page as the rates: a page of
lagging figures gets read once a month by whoever writes the report, and a page
with both gets read by somebody who can change the outcome.

Near-miss reports, toolbox talks delivered and attended, inspections completed
against hold and witness points planned, permits closed on time, overdue
actions, induction coverage, and the proportion of hold points passed at the
first attempt.

Anything genuinely unknowable shows as **No data** rather than as zero. A
percentage of nothing is not nought per cent — an induction coverage of "0%" on
an empty register would tell you to go and induct people who aren't there.

Two warnings appear under this section when they apply:

- **Talks with no attendees recorded.** The talk was either recorded and not
  given, or given and not written up. Either way the attendance figures above
  are lower than what happened on site.
- **No inspection and test plan in force.** Then "inspections completed" is a
  count and not a proportion — there's nothing to be complete *against*.

## What to look at first

Exposure hours. If that section is refusing, everything below it is refusing
too, and no amount of reading the leading indicators will fix the report you
have to send out on Friday.
