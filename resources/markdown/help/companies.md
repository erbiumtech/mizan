![Companies](/images/help/companies.png)

## What this is — Platform Administrator documentation

Every company (tenant) in the installation. This is where a company is
created, licensed, and — on its own edit page — has its members and roles
managed from outside its own panel.

## Creating one

Click **New**, name it, and pick a **Company Admin** from any account in the
installation — that person is attached to the new company and given its
Administrator role. This assignment only happens at creation; changing who
administers a company afterward is done through its Members tab, not by
editing this field again.

## Editing one

**Status** switches the company Active/Inactive. Its **slug** (used in URLs)
is shown but can't be changed here.

**Licensed modules** is what this company has bought, separate from what it
has *switched on* — that's the company's own choice, made on its own Modules
page. Revoking a licence here hides the module immediately but keeps the
company's on/off choice intact underneath, so re-granting it later restores
exactly what they had. Core is always included and never shown as a toggle.
This section is only offered to a super admin, even on this already
super-admin-only resource — a company admin granting themselves a module
would be a billing hole this defends against twice over.

## Members and Roles tabs

Opening a company shows its **Members** (attach or detach a user, and set
their roles in this company) and **Roles** (this company's own five roles,
same list as its own Roles page) without needing to switch into that
company's panel.

## Roles and permissions

Reachable only by a super admin.
