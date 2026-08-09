![Users](/images/help/users.png)

## What this is

Every account that can sign in to this company — their name, email, whether
they're active, which roles they hold here, and (for a super admin only)
which companies they can access at all.

## Creating or editing one <!-- requires: UserCreate, UserUpdate -->

Click **New**, then fill in **Name**, **Email**, and a **Password** (at least
8 characters; leave the password blank when editing to keep the existing one).
Assign **Roles in [Company]** — this only ever applies to the company you're
currently in; a person working across two companies holds separate roles in
each, set by switching companies first.

Two fields only a super admin sees:

- **Super Admin** — grants access to every company and the ability to switch
  into any of them. Only a super admin can grant or revoke this.
- **Company Access** — which companies this person can sign in to at all.
  Everyone else's own company is attached automatically when you create them
  here, which is the only membership this page needs for a normal admin.

## Activating and deactivating

The **Activate**/**Deactivate** button toggles whether someone can sign in at
all — a deactivated account is refused at login, nothing is deleted. This is
available to Administrator, Manager, and CEO.

## Removing someone vs. deleting them

These are different actions with different reach:

- **Remove from company** (bulk action) takes away this company's access and
  roles only. Their account, employee record, and access to any other company
  they work for are untouched. You cannot remove yourself this way — it would
  lock you out of the page you're standing on.
- **Delete accounts entirely** (bulk action, super admin only) removes the
  account from the whole installation, every company it belonged to. Anything
  of theirs that survives — payslips, MPRs, audit entries — is left pointing
  at a user that no longer exists.

## Signing in as someone else

**Log in as** switches your session to theirs, to complete something on their
behalf. Everything you do while impersonating is recorded against both of
you — including salary acknowledgements, which count as a statement of
consent from them. Use "Stop impersonating" in the banner to return to your
own account. This is never offered for a super admin's own row, and who else
it's offered for is decided by a dedicated authorization service rather than
a simple permission — if the button is missing on a row you expected it on,
that's almost certainly by design rather than a bug.

## Roles and permissions

- **View / Create / Update / Delete** map to `UserView`, `UserCreate`,
  `UserUpdate`, `UserDelete`.
- This list only ever shows the current company's own users — even a super
  admin sees one company at a time here. Managing users across every company
  at once is the Companies page, under Platform administration.
