## What this is

Reusable lists of what has to happen when somebody joins, and when somebody leaves.

## Templates and runs <!-- requires: ChecklistView -->

A **template** is the list. A **run** is one person's copy of it.

The items are **copied** when somebody is started on a checklist, not read through to
the template. That is deliberate: editing the template next year must not change what a
leaver was actually asked to do in March. Their list says what they were asked; the
template says what you would ask today.

## Dates <!-- requires: ChecklistCreate -->

Each item carries an offset in days from the anchor — the joining date for onboarding,
the leaving date for exit.

**Negative is before.** `-7` on "Order a laptop" means a week before somebody arrives,
which is when it actually has to be ordered.

## Owners are roles, not people <!-- requires: ChecklistCreate -->

"IT", "HR", "Line manager". A template outlives whoever happens to hold the job, and one
pointing at a person who has left is a checklist nobody owns.

An individual can still be assigned to a specific item on a specific run.

## Completing <!-- requires: ChecklistUpdate -->

Ticking the last outstanding item closes the run. Reopening an item reopens the run with
it — a checklist with something outstanding is not complete, whatever it said a moment
ago.

That matters for exit checklists in particular: a final settlement reads the asset
register to know whether the laptop came back.

## Roles and permissions

**View**: `ChecklistView`. **Create / update / delete**: `ChecklistCreate`,
`ChecklistUpdate`, `ChecklistDelete`. All four cover templates, their items, runs and
run items together — nobody grants those separately.
