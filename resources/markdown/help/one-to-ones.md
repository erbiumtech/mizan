## What this is

A record of a one-to-one: when it happened, and what was said.

## Two kinds of notes <!-- requires: ReviewView -->

**Notes** are shared with the employee. That is the point of recording them.

**Private notes** are **manager-and-above only, and are never shown to the person they are
about** — not the content, and not even the fact that any exist.

That rule needs stating on its own because of how visibility works everywhere else here: a
manager can see their whole reporting line, so without a specific rule an employee inside
their own manager's scope could read the notes written about them. They cannot, whatever
else they hold.

## Roles and permissions

**View**: `ReviewView`. **Create / update / delete**: `ReviewCreate`, `ReviewUpdate`,
`ReviewDelete` — and never on a one-to-one about yourself. **Private notes**:
`ReviewPrivateNotes`, which the Employee role does not hold.
