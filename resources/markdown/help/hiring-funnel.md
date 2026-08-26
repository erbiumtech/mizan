## What this shows

Every vacancy with its applications broken down by stage, how many offers were
taken, how long hiring took, and how long the vacancy has been open.

Four questions on one table, because they are asked together. A vacancy with fifty
applicants and no offers, and one with two applicants and an offer declined, are
different problems — and neither is visible in the other's figures.

## The columns

**Applied, Screening, Interview, Offer, Hired, Rejected** — where each application
currently sits. A stage nobody has reached shows a dash, so the shape of the funnel
is legible at a glance rather than a wall of noughts.

**Offers taken** is offers accepted as a proportion of offers **answered** — not of
offers issued. An offer somebody has not replied to yet is not a refusal, and
counting it as one would make a company that had just sent three offers look as
though it had lost them. A dash where nothing has been answered.

**To offer** is the average days from applying to the offer being *issued*. A draft
offer nobody has sent is not a milestone the candidate has reached, so it does not
count.

**To join** is the average days from the offer going out to the joining date on it,
and only for **accepted** offers. A declined offer has a joining date nobody is
going to honour, and averaging it in would describe a notice period that never
happened.

**Open for** is how long the vacancy has been open, in days. Filled and closed
vacancies show a dash: ageing is only a question about something still open, and
putting a closed vacancy's age in the same column would invite the two to be
averaged together.

## Withdrawals

An applicant who **withdrew** is deliberately not in the stage columns. They left of
their own accord, and counting them beside rejections would read as the company's
decision. They are included in the applications total and counted in the note.

## Averages over small numbers

Both time figures are means, over however many offers a vacancy has produced. Over
two or three hires that is a rough guide rather than a statistic, which is why the
application count sits on the same row — read the two together.

## Using it

The date includes every vacancy opened on or before it, so a date in the past gives
the funnel as it stood being filled by then. Note that the *stages* are always
current: an application's stage is where it sits now, not where it sat on that date.

**The table scrolls sideways.** Six stage columns plus four measures is more than the
pane is wide, and collapsing the stages into a total would remove the only thing
that makes it a funnel.

## Roles and permissions

Requires `ReportView`, gated behind the Recruitment module being enabled. Read-only
— nothing here moves an application, issues an offer or closes a vacancy.
