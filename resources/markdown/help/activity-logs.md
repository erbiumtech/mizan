![Activity Log](/images/help/activity-logs.png)

## What this is

A read-only audit trail: every record created, updated, or deleted in this
company, who did it, and when. Nothing here can be created, edited, or
deleted through the app — it's a record of what happened, not data you
maintain.

## Reading an entry

The list shows the **Model** affected, the **Event** (Created, Updated, or
Deleted), who caused it (**Causer** — shows "System" for anything done
outside a logged-in request, such as a scheduled job), and **When**. Click a
row to open it and see:

- **Subject** — which specific record, by type and ID.
- **Changes** — the actual field-level diff, as JSON.
- **Extra Properties** — anything else the action recorded beyond the plain
  before/after values.

## Roles and permissions

Viewing requires `ActivityLogView`. There's no create, update, or delete —
the policy refuses all three unconditionally, regardless of role, because an
audit trail that could be edited wouldn't be one.
