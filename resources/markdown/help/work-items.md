## What this is

A work item is one piece of work: a **Bug**, a **Story**, a **Task**, an **Epic** or a
**Subtask**. The type is what you read; everything else about an item behaves the same whichever
one it is.

Each belongs to a project and carries that project's key and a number — `WRK-12` — which never
changes and is never reused. That is what you put in a commit message, a branch name or a chat
message when you want somebody to find it again.

## Status, and what "counts as"

Every status belongs to a **workflow** and sits in one of three categories: *To do*, *In progress*
or *Done*. You can call a status anything you like — "Waiting on client", "In QA" — but the
category is what the application reasons about. Reports, filters and the board's columns all read
the category and none of them reads the name.

This is why the **Where it is up to** filter keeps working whatever statuses you invent, and why
getting the category wrong is the one mistake worth checking: a status that counts as *Done* when
it should count as *In progress* takes its items out of "what is still open".

Changing a status on the form is a real move, the same as dragging a card: the workflow's rules
apply, and the item is stamped with when work started on it and when it was resolved. Those two
stamps are what cycle time is measured from, which is why nothing writes them by hand.

## Who has it

One assignee, deliberately. A card with three names on it is a card nobody owns; if two people are
working on something, say so in a comment.

**Assigned to me** is the filter most people live in. **Blocked** shows items something else is
holding up — see Links below.

## Hierarchy

One level each way: an **Epic** sits above stories and tasks, a **Subtask** sits below one. A
subtask cannot have children and an epic cannot have a parent. Three levels is what teams actually
use; the fourth exists in other tools mostly for making roadmaps.

## Links

**Blocks**, **relates to** and **duplicates**. Each is stored once, from one side: if A blocks B,
then B is blocked by A, and there is only one row saying so. You cannot end up with the two halves
of a link disagreeing.

## Estimates

Points or minutes, both optional, and your company uses one or the other. Time logged against an
item is an ordinary timesheet entry, so it reaches payroll inputs and client invoices the same way
every other hour does.

## Logging time

**Log time** on an item takes minutes, a date, a note and whether it bills, and writes one
timesheet entry — nothing else. The item's *Time* section then shows what has been logged against
its estimate, what is left (or how far over it is), and what those hours cost at the payroll rate
for each person's month. Hours nobody's payroll can price are counted and said to be uncosted rather
than priced at a guess.

Time logged from an item is yours: it goes on your own timesheet. Logging somebody else's time is
done on the timesheet, where the item picker is.

A row that was synced in from another tool — Clockify, or a commit message — is read-only on the
timesheet and says where to change it. The next sync would overwrite a hand edit anyway.

This needs the Timesheets module; without it the button and the section are absent.

## Three assists, when the company has an AI key

- **Draft from a sentence**, on the new-item form: one sentence about what is wrong or wanted,
  and the form is filled in — title, a description in the house template, type, priority, and two
  suggested labels. It is a draft: check it, change it, and nothing is saved until you save it.
- **Summarise thread**, on an item with more than ten comments: three lines at the top of the
  item, marked as generated and dated, regenerated whenever you ask.
- **Suggest estimate**: a number drawn from three finished items on the project with similar
  titles, shown with the three it came from. It is never written; type it into the field if it
  looks right.

None of the three acts on its own, and all three are absent when the server has no key. Automatic
assignment, prioritisation and sprint planning are deliberately not offered.

## Roles and permissions

Everybody who works the board may view, create, update and move items — filing a bug should never
need a permission. Deleting is the CEO's. **Moving** is a separate permission from **updating**, so
a board can be shared with somebody who may read every card and drag none of them.
