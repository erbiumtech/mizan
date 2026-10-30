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

## Keeping up to date

If your installation has a live connection configured, cards move as other people move them. If it
does not, the board refreshes itself every thirty seconds.

## Roles and permissions

Everybody who works the board may drag cards on it. **Moving** is a separate permission from
**editing**, so a board can be shared with somebody who should read it and change nothing — a client
contact, or a department watching progress.
