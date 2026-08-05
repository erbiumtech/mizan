![Platform Users](/images/help/platform-users.png)

## What this is — Platform Administrator documentation

Every account in the installation, across every company — including other
platform admins, who are excluded from any single company's own Users list.
This is the cross-company view; a company's roles and day-to-day membership
are still managed on that company's own Users page.

## Creating or editing one

Fill in **Name**, **Email**, and a **Password**. Two switches decide their
reach:

- **Platform admin** — administers the whole installation: every company,
  and this panel itself.
- **Active** — an inactive account can't sign in anywhere, to any panel.

**Company access** is which companies the account may sign in to at all —
there's no roles field here, since a role is per-company; set what someone
can *do* inside a company on that company's own Members tab.

An account attached to no company can't sign in anywhere at all — the **In no
company** filter finds these, since they're otherwise invisible (created by
leaving the last field on this form empty).

## Signing in as someone else

**Log in as** works the same way as the company panel's version, but from
here it can reach across the whole installation rather than one company at a
time — you're placed into one of their own companies. It's never offered for
another platform admin's row.

## Roles and permissions

Reachable only by a super admin. Deleting the last platform admin would lock
everyone out of this panel, including whoever did it — that rule is enforced
on the model itself, not on this screen, so it holds from the console and a
queued job too, not just here.
