## What this is

The dropdowns in this application that **you** fill in, rather than ones we decided for
you: designations, departments, employment types, NCR categories, plant categories.

Each is a list. Adding an entry here makes it appear in that dropdown everywhere it is
used, immediately, with no deploy and no support call.

## Which dropdowns are here, and which are not

Only the lists that are genuinely yours. Most dropdowns in the panel are **workflow
vocabulary** — an invoice's status, a variation's valuation method, a contract's side —
and those are not settings: the code branches on them. "Certified" means a payment
certificate has been valued and signed, and adding a status called "Nearly certified"
would produce a value nothing in the application knows how to act on, or that the
database refuses outright. Those stay as they are on purpose.

A list appears here when two companies would genuinely write it differently and nothing
computes from it. If a dropdown you want to edit is missing, that is the question worth
asking about it.

## What appears depends on your modules

You are shown the lists of the modules this company has. Take on Construction QHSE and
the NCR categories appear; switch it off and they go quiet — your entries are kept, not
deleted, and come back with the licence.

## Renaming, and why the stored value does not change

Editing an entry changes the **label** — what people see. What each record stored stays
as it was, which is what makes renaming safe: change "Cook" to "Chef" and every employee
who was a Cook now reads Chef, with nothing to migrate.

The value is set when the entry is created, from whatever you typed, and it is not
editable afterwards for the same reason.

## Switching off versus deleting

**Switch Offered off** when a list has moved on: the entry stops being offered on new
records, and every record already using it still reads correctly. This is almost always
what you want.

**Delete** when the entry was never used — a shipped value that does not apply to you,
or a typo caught the same afternoon. Deleting an entry that records already use does not
change those records, but the panel then has only the raw stored value to show for them.

## Order

**Order in the list** decides the sequence in the dropdown; entries with the same number
fall back to alphabetical. Put the common answers at the top — a dropdown is read from
the top, and the fifteenth entry is one somebody scrolls past.

## Who can change these

Administrators. These lists decide what everybody else is offered on every screen, so
they sit with the person who already decides that — the same access as Company Settings.
