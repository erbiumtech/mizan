![Comments](/images/help/comments.png)

## What this is

Comments attached to another record — today, payslips. Not in the sidebar;
you normally reach these from the record they're on rather than browsing a
standalone list.

## Adding and editing one <!-- requires: CommentCreate -->

Type the **Comment** and save. You can only edit or delete your own comment,
and only while it's still **open** — once someone replies to it, or it's
marked **Resolved**, it's locked; the record of the conversation stops
changing at that point.

## Resolving <!-- requires: CommentResolve -->

**Mark Resolved** (on a single comment, or as a bulk action across several)
stamps who resolved it and when. It's only offered to whoever holds the
resolve permission, and once resolved, a comment shows **Resolved** instead
of **Open** and can no longer be edited.

## Who can see what

Viewing requires `CommentView`, but that alone doesn't mean seeing every
comment: anyone who also holds `CommentResolve` sees every comment (that's
what makes them a resolver), while everyone else only sees comments on
records that belong to them — for a payslip comment, that means the
employee it was written about.

## Roles and permissions

- **View**: `CommentView`. **Create**: `CommentCreate`. **Resolve**:
  `CommentResolve`.
- There's no separate update/delete permission — editing and deleting are
  both governed by the same rule: you must be the author, and the comment
  must still be open with no replies.
