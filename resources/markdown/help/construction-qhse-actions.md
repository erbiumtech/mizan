## One list, from every source

An NCR, an inspection, an incident, a toolbox talk and an audit finding all produce the
same record: **somebody must do something by a date, and somebody else must verify it**.

They are one table on purpose. Four separate action registers produce four "overdue
actions" reports that never agree, and the one genuinely useful screen — everything
overdue, from every source, in one list — becomes a four-way union nobody maintains.

The **From** column says which finding raised each row.

## Actions are raised where the finding is

There is no "new action" button here. Raise it on the NCR, the inspection or the incident
that produced it — an action with no finding behind it is a task in a quality register,
and this is not a task manager.

## An action needs somebody against it

A user, a contact, or **just a name typed in**. The last one is the important one: on
most sites, most of the people who have to do something are a subcontractor's and are in
no table here. An action nobody is assigned to is an action nobody does, and the register
refuses to create one.

Filter by *Nobody assigned* to find any that slipped through an import.

## Done is not verified <!-- requires: ConstructionActionVerify -->

Two acts, two permissions.

**Mark done** is the assignee's claim. The action stays on the list showing *awaiting
verification* — because that state is where most quality systems quietly fail, and a
register with only "closed" loses it entirely.

**Verify** is somebody else's confirmation, and it is refused on an action nobody has
claimed to have done: verifying something that has not happened is a closure that proves
nothing.

## Containment is not correction

The types are distinct on purpose:

- **Containment** — stop it spreading now. Cordon the hole off.
- **Corrective** — fix this one. Fill the hole.
- **Preventive** — stop it recurring. Change how the hole gets left.

A register that could not tell them apart would report a site as having addressed
something when all it did was put a barrier round it.

## Cancelling keeps the row

With a reason. A row that simply disappears is the one that gets raised again next month.

## NCR CAPA is not in this table

An NCR carries corrective and preventive action as **its own fields**, because ISO 9001
asks for them on the NCR. Those are the record; these are the working tasks.

They are deliberately **not** copied into each other — a mirror is two sources for one
date. The combined overdue view assembles both and says which source each row came from,
so a figure never comes from two places without saying so.
