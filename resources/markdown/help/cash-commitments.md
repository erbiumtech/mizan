## What this shows

Everything the company has already committed to over the next ninety days, on one
timeline: what will leave the bank, what will arrive, when, and whether it has
been raised yet.

Three things feed it, and each of them previously had something that *created*
these entries on a schedule and nothing that listed what was coming:

- **Scheduled entries** — recurring journal entries, such as rent or a standing
  charge.
- **Subscriptions** — a beneficiary's monthly agreement.
- **Recurring invoices** — money arriving from a customer on a retainer.

## A commitment is not a certainty

That is why **Raised** is a column rather than a filter. The two populations are
read completely differently:

- **Raised — Yes.** The entry or invoice exists. It is a payable or a receivable
  somebody can chase, and it is already in the ledger.
- **Raised — Not yet.** Nothing has been created. It is a decision still open: the
  agreement can be ended, the amount changed, the schedule edited.

A report that presented all of it as fact would be a forecast the ledger had to
honour. This one is a list of things that will happen unless somebody changes
them.

## Using it

The date is where the ninety days start, so the report always looks **forward**.
It cannot be pointed at the past, deliberately: every row there would either have
been raised or quietly missed, and the answer would be a list of things to feel
bad about rather than a list to act on.

**Amounts carry no sign.** Direction is its own column — *Out* or *In* — because a
single column with some figures negative gets added up wrongly by hand every time.
The two totals at the top are the two directions, and the figure on the record row
is the **net**: a column mixing both directions has no meaningful sum.

The list includes occurrences that **have not come round yet**, which is the whole
point. The nightly runner only ever deals with what is due now.

## Where the figures come from

**Only active schedules, subscriptions and agreements.** Deactivating any of them
takes it off this report immediately, whether or not it has occurrences left.

**A schedule that ends inside the window stops there** rather than continuing to
the horizon.

**A scheduled entry's amount is its debit side.** A scheduled entry balances by
construction, so either side is the figure, and the debit is the one that reads as
what the thing costs.

**Subscriptions and recurring invoices are monthly agreements** and are not
pro-rated: one starting on the 15th is committed for that whole month.

**Nothing here is capped.** The nightly runner will only raise a limited number of
back-dated entries at a time, so that nobody has to review two hundred at once.
That limit is deliberately not applied here — a report that inherited it would
simply be short, with nothing on screen to say so.

**A module you do not have contributes nothing.** Without invoicing there are no
recurring invoices on the report and no *arriving* total, rather than an error.

## Roles and permissions

Requires `ReportView`, gated behind the Accounting module being enabled for the
company. Read-only — opening this report raises nothing. That is worth stating,
because the bank payment file *does* create rows when opened; this does not.
