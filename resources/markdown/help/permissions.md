![Permissions](/images/help/permissions.png)

## What this is — Platform Administrator documentation

The installation-wide list of permissions the code actually checks —
`JournalEntryView`, `PayslipCreate`, and so on. This is not where a company
decides who on their team can do what; that's the Roles page inside each
company's own panel, where an administrator assigns *these* permissions to
*their* roles. This page decides what the set of permissions is, full stop,
for every company at once.

## Creating or editing one

Give it a **Name** — the exact string the code checks with `can()` or
`hasPermissionTo()` — and a **Group**, which is what the Roles page's
permission picker groups by (typically the module or feature it belongs to,
e.g. "JournalEntry", "Payslip"). **Guard** is always `web`.

Creating a permission here that nothing in the code checks does nothing.
Deleting one that something *does* check breaks that check for every company
at once, immediately — there's no confirmation step that catches this for
you, so treat deletion as a code-level change, not a data cleanup.

## Roles and permissions

Reachable only by a super admin — `canAccess()` checks that directly rather
than through a permission, specifically so this can never be discovered again
from an ordinary company panel. Within the platform panel, viewing, creating,
editing, and deleting are further gated by `viewAnyPermission`,
`viewPermission`, `createPermission`, `updatePermission`, and
`deletePermission` — though a super admin already passes every check via the
platform's own Gate.
