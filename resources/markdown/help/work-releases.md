## What this is

A release is a version something ships in — `2.4.0`, `March drop`, whatever your team calls them. A
work item's release is its *fix version*: the one it is promised to.

A release is a name and two dates. Everything else about it — what is in it, what is still open,
the release notes — is read from the items, so the release can never disagree with them.

## Planning one

Create a release with the project, a name and the date it is meant to go out. Items are put into
it from the item's own form, or from the backlog in handfuls: tick what you want and choose the
release.

Only releases that have not gone out yet are offered. Promising an item into a version that has
already shipped would be a changelog that rewrites itself.

## Releasing one

**Release** stamps today's date and moves nothing. The items stay where they are, because "it
shipped" and "it is done" are different facts: a feature can go out behind a flag and still be open.

What it will not do is go out quietly with open work in it. If anything in the release is still
open, you are asked what happens to it:

- **move to another release** — the next version, or out of any release and back to the plain
  backlog; or
- **ship open** — it went out as it is, and stays attributed to this release.

Nothing is chosen for you, because those are two different statements to a customer, and a note
that lists a bug as fixed when it is not is the mistake a customer finds first.

A released release cannot be edited. Its figures are in somebody's changelog by then.

## Release notes

**Notes** shows the release notes as Markdown: one line per finished item, grouped by type, in the
order your types are listed under Settings. Paste it into the GitHub release, the customer email,
or the changelog.

The notes are worked out every time you open them, never stored, so a title corrected after the
release is corrected in the notes too. An item that is still open is left out — the notes only ever
say what was finished.

## Roles and permissions

Everybody may read releases and their notes. Creating, editing and releasing one needs
**ReleaseManage**, which Managers hold. Releasing is a statement to customers as much as to the
team, which is why it is not an ordinary edit.
