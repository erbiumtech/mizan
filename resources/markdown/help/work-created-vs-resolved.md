## What this shows

Per project, per week, for the twelve weeks to the report's date: how many items were created,
how many were resolved, and the difference.

Weeks run Monday to Sunday. A week in which a project neither created nor resolved anything is
left out rather than shown as a row of dashes.

## The net column

**Net** is resolved minus created. Below zero, the backlog grew that week.

It is the column to read. A project that files more than it finishes, week after week, has a
backlog that is a list of things that will never happen — however good its velocity looks, because
velocity measures what was finished and says nothing about what was promised.

## What counts

Created is the item's creation date. Resolved is when it entered a *done* status, stamped by the
status engine; an item reopened and resolved again counts in the week it was last resolved.
Archived items are left out of both.

## Roles and permissions

Reading this needs **ReportView** and the Work module. Nothing on it can be changed from here.
