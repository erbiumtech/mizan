## What this is — Platform Administrator documentation

Every role, in every company, in one list — the cross-company view a single
company's Roles page can't give you. It answers "which company is missing a
role, and where did this extra one come from?" without opening each company
in turn. **Company** is shown and sortable/searchable on every row; a role
whose company has since been deleted still shows here (as "no company"),
which is exactly the row this screen exists to surface.

## Listing only — there is no create or edit here

Roles are created by the same seeder that provisions a company, so the five
standard names (Administrator, Employee, Accountant, Manager, CEO) and their
starting permissions stay identical everywhere. A role created by hand here
would have no permissions and no obvious company to belong to.

Editing a role's permissions is also not done here, on purpose: with no
current company selected, the permission picker would offer every permission
in the whole installation — including ones for modules that role's company
has never licensed. Click **Open** on a row to edit it on that company's own
Roles page instead, where only the modules it actually has are offered.

## Roles and permissions

Reachable only by a super admin.
