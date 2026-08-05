## What this is

The five roles for this company — Administrator, Manager, CEO, Accountant,
Employee — and exactly which permissions each one carries. This is where you
change what a role can do; who holds which role is set on the person's own
record, on the Users page.

## Editing a role's permissions

Open a role and the **Permissions** section lists every permission grouped by
the module it belongs to, each as a checklist you can bulk-toggle a whole
group with. Only permissions belonging to a module this company has
**enabled** are shown — assigning rights to a feature the company can't reach
would just be noise. If a module gets switched off with permissions from it
already granted to a role, those grants aren't lost; they're only hidden
until the module comes back on.

Renaming a role or changing its **Guard** doesn't change who it applies to —
only `web` exists as a guard here.

## Roles and permissions

Viewing, creating, editing, and deleting roles are their own permissions —
named `viewAnyRole`, `viewRole`, `createRole`, `updateRole`, and `deleteRole`
rather than following the usual `RoleView`/`RoleCreate` pattern used
elsewhere in the app. Administrator holds every permission in the system by
default, including these.

This list only ever shows the current company's own five roles — a role
belongs to exactly one company, even though every company has one named
"Accountant." Switch companies to manage another company's roles; seeing
every company's roles side by side is the Platform Roles page, under Platform
administration.
