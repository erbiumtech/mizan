## What this is

The board shows one project's open work as columns you drag cards between. A column is one or more
**statuses**; dragging a card into it moves the item, through exactly the same rules as changing the
status on the item's own form.

## Moving a card

Drag it. Dropping it in another column moves it; dropping it higher or lower in the same column
changes its order and nothing else.

A move can be refused, and when it is the card goes back where it was and a message says why.
Two things refuse:

- **A column's limit.** If a column is set to hold six cards, the seventh is refused. This is a
  limit, not a warning — a team that wants a soft one sets it one higher. A column over its limit is
  coloured, so you can see it before you try.
- **A workflow rule.** Some statuses require an assignee, or a resolution, or may only be reached
  from certain other statuses. Those are set under Settings → Workflows.

## Filters

**Mine**, **Unassigned** and **Blocked**, plus a search box. They live in the web address, so a
filtered board is a link you can send to somebody and they see what you see.

## What is not on the board

Statuses that no column shows are named under the filters. This matters: an item moved into one of
them is still there, it just has nowhere to appear on this board. If a card seems to vanish, that
line is the first place to look.

Subtasks are not shown as cards of their own by default — they appear as a count on their parent.

## The card

Key, title, labels, type, and then whatever applies: a **Blocked** mark when something is holding it
up, the epic it belongs to, how many of its subtasks are done, its estimate, its due date in red
when it is past, and the assignee's initials.

Clicking the key opens the item.

## Board settings <!-- requires: BoardManage -->

**Board settings**, at the top of the page, changes how this board draws the project's work. It
changes nothing about the work itself: no card moves, and the workflow's rules stay as they are.

- **Name** and **Kind**. A *Kanban* board shows every open item; a *Scrum* board shows the active
  sprint's.
- **Lanes** split every column into rows by assignee, epic or priority, or not at all.
- **Show subtasks as cards** gives each subtask a card of its own. Off, a subtask is a count on its
  parent's card.
- **Columns**, in order — drag a column by its handle to move it. Each has a name, the statuses it
  holds, and an optional **card limit**. A column may hold more than one status: *In review* and
  *In QA* side by side is a common pair. A card dropped on such a column lands in its first status.

Three things refuse a save, each with the reason: a column with no status, a status in two columns,
and a limit that is not a whole number of at least one. A status in **no** column is allowed — it
is then named above the board, as described below, so its cards are never lost without saying so.

A limit belongs to its column, so moving the column moves the limit with it.

**Reset columns from workflow**, under **Boards**, puts back one column per status of the
project's workflow, in order, with no limits. Use it when a board's columns no longer make sense
— after a workflow gained statuses, for instance.

## More than one board

A project starts with one board. **Boards → New board** adds another — a Scrum board for the sprint
beside the Kanban board for everything, or a board for the support team with only their columns.
Each new board starts with one column per status and is changed through its own settings.

When a project has more than one board, a second picker beside the project's chooses between them.
The choice is in the web address too, so a link opens the board it was copied from. The project's
first board is the one shown when nothing is chosen.

**Boards → Delete this board** removes the board and nothing else: items, statuses and history
stay, and show on the project's other boards. A project's last board cannot be deleted.

## Which workflow a project uses

A project's statuses — and so the columns a new board starts with — come from its **workflow**,
chosen on the project's own form under **Workflow**. Empty means the company default.

It can be changed only while none of the project's items is in a status the new workflow lacks.
Since a status belongs to one workflow, that usually means before the project has items. Nothing
is remapped automatically: to move a running project, move its items' statuses first. When the
change goes through, every board of the project is redrawn with one column per new status, and its
limits are cleared, because columns of the old statuses would hold nothing.

## Keeping up to date

If your installation has a live connection configured, cards move as other people move them. If it
does not, the board refreshes itself every thirty seconds.

## Roles and permissions

Everybody who works the board may drag cards on it. **Moving** is a separate permission from
**editing**, so a board can be shared with somebody who should read it and change nothing — a client
contact, or a department watching progress.

Changing a board's settings, adding a board and deleting one need **Board manage**, which managers
have. Choosing a project's workflow needs permission to edit the project.
