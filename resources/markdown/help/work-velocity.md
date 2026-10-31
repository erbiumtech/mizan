## What this shows

One row per closed sprint, per project, for the last eight closed sprints of each: when it ran,
how many items and points were finished in it, and how many of those had been carried in from an
earlier sprint.

The sprint's own page shows the same figures for one sprint. This is where the projects are read
side by side.

## How it is counted

Velocity is **computed every time, never stored**. A stored figure is right on the day it is
written and silently wrong after any correction to the items behind it.

It is counted between when the sprint **actually** started and when it was **actually** closed,
not between the planned dates. A sprint started two days late delivered what it delivered in the
days it had, and measuring it against the plan would flatter or punish the team for the calendar.

## Carried in

The number that makes a velocity honest. A sprint that delivered forty points having carried
fifteen of them in from the last one is not the same sprint as one that started them all. An item
is marked as carried when a sprint closes with it unfinished, and the mark is never reset.

## Roles and permissions

Reading this needs **ReportView** and the Work module. Nothing on it can be changed from here.
