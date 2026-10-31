## What this is

A **workflow** is the set of statuses a work item may be in, and the rules about moving between
them. Your company starts with one, called *Default*: To do → In progress → In review → Done.

A project uses one workflow. Most companies never need a second; a company that runs client
approvals differently from internal work writes one more.

A project picks its workflow on its own form, under **Workflow**; empty means the company default.
The choice can be changed only while none of the project's items is in a status the new workflow
lacks — in practice, before the project has items — and changing it redraws the project's boards
from the new statuses. Nothing is remapped silently.

## Statuses, and the category that matters

Every status is in one of three categories — **To do**, **In progress**, **Done** — and this is the
field to get right. The name is what your team reads. The category is what every report, filter and
board column in the application reads.

*In review* and *In QA* are both **In progress**: a review is work in progress by any honest
reading. *Won't do* is **Done**: it is finished, just not by being built.

If an item vanishes from "what is still open", a status in the wrong category is almost always why.

## Rules

Four kinds, and you add only the ones you want:

- **May only move to certain statuses** — from this status, only to the ones you name.
- **Requires a resolution** — entering this status asks how the item ended.
- **Requires an assignee** — nothing enters this status unassigned.
- **Only the assignee or a manager may move it on** — stops somebody else marking your work done.

A refused move says why and the item stays where it was. Nothing moves silently.

There is deliberately no scripting here. Other tools let you write conditions and post-functions,
which becomes a programming language stored in a database that one person understands.

## Changing a workflow a project is already using

Statuses can be added and renamed freely. Removing one that items are sitting in is what to be
careful about — move them first, or they are in a status their board has no column for.

## Roles and permissions

Administrator only. A workflow governs every board in the company, so changing one is not a board
manager's decision.
