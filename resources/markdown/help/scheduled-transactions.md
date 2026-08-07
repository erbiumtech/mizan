A standing instruction to raise the same journal entry on a rhythm: rent on the
1st, the loan instalment on the 5th, the annual licence fee every July.

## It raises drafts, never posted entries

This is the most important thing on this page.

A schedule creates a **draft** journal entry on each due date, exactly as if
somebody had typed it. It still has to be submitted, approved by somebody other
than its creator, and posted before it touches the ledger.

That is deliberate, for two reasons. An entry reaching the books is a decision
somebody makes after reading it, and a nightly job is not somebody. And a
schedule that posted directly would be a way to put anything into the accounts
with no approver at all, which is the one thing the approval workflow exists to
prevent.

## Setting one up <!-- requires: JournalEntryCreate -->

1. **Accounting → Scheduled Entries → New scheduled entry.**
2. Name it for what it is: "Office rent", not "JE1".
3. Choose how often — monthly, quarterly, every six months, or yearly — and the
   day of the month.
4. **First due** is the earliest date it may raise. **Stops after** is optional;
   leave it empty for an open-ended arrangement.
5. Add the lines, exactly as you would type the entry: an account and either a
   debit or a credit on each.
6. Save.

**A short month uses its last day.** A schedule set to the 31st fires on 28
February rather than skipping the month, because a rent that quietly misses a
month is worse than one dated two days early.

**The first occurrence never predates the start.** Begin on the 20th with a day
of 1 and the first entry is the 1st of next month, not three weeks before you
agreed it.

## The lines have to balance

Debits and credits must be equal, and each line is one or the other, never both.
The form refuses to save otherwise — with the actual difference in the message,
so you can see what is missing.

This is checked again every night. A schedule that stops balancing later — an
account deactivated underneath it, say — is **skipped**, and the **Balances**
column on the list turns to a cross. Nothing is raised from it until it is
fixed, and nothing else in that night's run is affected.

## Reading the list

| Column | What it tells you |
|---|---|
| Every | How often it comes round |
| Amount | The total of the debits, which is what each entry will be for |
| Next due | The next date it will raise, or *paused* / *finished* |
| Waiting | Due dates with no entry yet — these go out on the nightly run |
| Balances | Whether the lines add up. A cross means nothing is being raised |

**Waiting** is normally 0 or 1. A larger number means either the schedule was
back-dated, or the nightly job has not run for a while.

## Raising them early <!-- requires: JournalEntryCreate -->

**Raise now** on a row creates everything outstanding immediately, as drafts,
exactly as the nightly run would. Useful when you have just set a schedule up and
want to see what it produces, or when you need the entry before tonight.

## Nothing is ever raised twice

Whether an occurrence has been raised is asked of the **ledger** — does an entry
from this schedule with that date already exist — rather than kept as a "last
run" marker on the schedule.

That matters in practice. Delete a draft it raised and it will raise it again,
which is what you want. Run the command by hand and the nightly run will not
duplicate it. Restore a backup and nothing gets doubled.

## Catching up after an outage

Due dates come from the schedule's own start, not from when the job last fired.
If cron is down for a week, the following night raises the week's entries with
their proper dates.

There is a cap of **24 entries per schedule per run**, so that a start date typed
as 2016 instead of 2026 cannot put a hundred drafts in the ledger before anybody
notices. Anything left over is raised on the next run, and the command says out
loud when it has stopped at the cap.

## Pausing one <!-- requires: JournalEntryUpdate -->

Switch **Active** off. It stops raising, keeps its history, and can be switched
back on. The entries it already raised are ordinary journal entries and are
unaffected.

## When it runs

Nightly at 02:20, once per company, and only for companies with Accounting
licensed. Daily rather than monthly, because a schedule may name any day — and
because a daily run makes catching up the ordinary path rather than a repair job.

## Roles at a glance

| Role | What they can do here |
|---|---|
| Administrator | Everything |
| Accountant | Create schedules, edit them, raise them early |
| Manager | The same |
| CEO | The same |
| Employee | No access |

Deleting a schedule needs `JournalEntryDelete`, which is Administrator-only —
the same bar as deleting an entry from the ledger.

## Troubleshooting

**Nothing is being raised.** Check three things in order: is it Active, does the
**Balances** column show a tick, and has the first due date passed?

**"Nothing could be raised" when I press Raise now.** The dates fall inside a
closed fiscal year, or one of the accounts no longer accepts entries — it was
deactivated, or given child accounts.

**An account is missing from the list.** Only active leaf accounts that allow
manual entry can receive a posting. A group heading cannot.

**I deleted the draft and it came back.** Working as intended — "already raised"
is asked of the ledger. Pause or delete the schedule if you want it to stop.
