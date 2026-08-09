One question across the whole ledger: *every transaction over 50,000 against
this account between these dates*.

## What it searches

**Postings, not entries.** A journal entry has at least two sides and often
more, so it has no single account or amount — "over 50,000 against Rent" is a
question about one line of an entry, not the entry. Each row here is one posting:
one account, one amount, on one side.

The search box looks at the entry number, the reference, the line's narration and
the account's code and name. Everything else is a filter.

## The filters

| Filter | What it does |
|---|---|
| Accounts | One or several. Leave empty for the whole ledger |
| From / To | The entry date, inclusive at both ends |
| At least / At most | The amount, on whichever side the line uses |
| Status | Which entries count. **Defaults to Posted** |
| Side | Debits only, or credits only |

**Amount is asked of whichever side the line uses.** Every line is a debit or a
credit, never both, so *at least 50,000* matches a line reaching that on either
side, and *at most 50,000* means neither side exceeds it.

**Status defaults to Posted, on purpose.** Mixing drafts and rejected entries in
with the books gives a total under the Debit and Credit columns that is not a
figure anybody should be reading. Clear the filter when you are looking for a
draft — the current filters are always listed above the table.

## The totals

The Debit and Credit columns are totalled for **everything the filters match**,
not just the page on screen. That is what makes this usable as a check: filter to
one account for one month and the two totals are that account's movement.

They will not usually be equal, and should not be. Equal totals are a property of
a whole entry; a filtered set of postings is not a whole entry.

## Getting to the entry

The **Entry** column links to the journal entry the posting belongs to, where you
can see its other side and, with the right permission, act on it.

## Which screen to use

| You want to | Use |
|---|---|
| Find postings anywhere, by any combination | This screen |
| Work inside one cash or bank account | Account Register |
| See one account's balance over time | Account Register |
| Add, edit or post a transaction | Account Register, or Journal Entries |

This screen only finds — it never changes anything. The Account Register button
in the header is one click away when you have found what you were looking for.

## Roles at a glance

| Role | What they can do here |
|---|---|
| Administrator | Search the whole ledger |
| Accountant | Search the whole ledger |
| Manager | Search the whole ledger |
| CEO | Search the whole ledger |
| Employee | No access |

Everybody who can see a journal entry can search for one; the permission is the
same `JournalEntryView`.

## Troubleshooting

**A transaction I know exists is missing.** Almost always the Status filter — it
starts on Posted, and drafts, pending and rejected entries are excluded. Clear it
and look again.

**The debit and credit totals do not match.** Expected. They only balance across
a complete entry, and a filtered list of postings is not one.

**Searching an amount finds nothing.** The search box does not search amounts —
use **At least** and **At most**. Setting both to the same figure finds postings
for exactly that amount.

**Two rows for what looks like one transaction.** That is the entry's two sides,
and both matched your filters. Filter by side, or by one account, to see one.
