## What this is

A sprint is a fixed stretch of work with a goal and an end date. Work items are put into one, the
sprint runs, and when it is completed everything unfinished has to go somewhere.

If your team does not work in sprints, you do not need this screen at all — the board and the
backlog work perfectly well without one.

## Starting one

A sprint needs a start and an end date before it can run, because the burndown is drawn between
them. **Only one sprint runs at a time in a project.** Starting a second is refused rather than
queued: two sprints at once makes the burndown meaningless and puts two different commitments on
one board.

## Completing one

This is the half that matters. Completing asks where the unfinished work goes:

- **into another sprint**, meaning the team still intends to do it; or
- **back to the backlog**, meaning it is no longer committed to.

Nothing is chosen for you, because those are two different statements and a tool that picks one
silently is deciding for the team.

Each item that is carried out is marked, and the count shows on the backlog as `↻ 2`. An item that
has slipped three sprints is worth talking about at planning, and nothing else would tell you.

## The sprint's own page

Open a sprint to see how it is going:

- **Where it is up to** — items and points done, and how many were carried in from an earlier
  sprint. That last figure is what makes the velocity below honest: a sprint that delivered forty
  points having carried fifteen in is not the same as one that started them.
- **Burndown** — what was left at the end of each day, against an even line. The even line is not a
  target; it is the thing the real line is read against.
- **Velocity** — what each closed sprint on this project delivered.

Velocity is computed every time it is shown, never stored. A stored figure is right on the day it is
written and silently wrong after any correction to the items behind it. It is counted between when
the sprint actually started and when it was actually closed, not between the planned dates — a
sprint started two days late delivered what it delivered in the days it had.

## Roles and permissions

Everybody may read a sprint. Starting and completing one needs **SprintManage**, which Managers
hold. Completing moves every unfinished item, which is why it is not an ordinary edit.
