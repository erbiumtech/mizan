## What this chapter covers

Billing one client for one month of work — salaries, office costs and credits —
without typing any of those figures a second time.

## What a billing run is for

The month's bill to the client used to be a spreadsheet: a row per employee at
full cost, the month's office expenses underneath, less what employees repaid on
their advances, converted to the client's currency at the month's rate.

Every one of those figures already exists in the system — in payslips, in
payments, in the advance ledger — so a billing run assembles the bill from them
instead. What comes out is an **ordinary draft invoice**. Issuing it, taking
payment, ageing it and printing it are all exactly as described in the chapter
on invoicing; a billing run only decides what the lines say.

## Setting up the run

On the **Billing Runs** screen, click **New**:

1. **Client** — the contact to bill.
2. **Payroll month** and **Fiscal Year** the bill covers. Together these decide
   which payslips and which dates count, so they must match the month you mean.
3. **Invoice Date** and **Due Date** for the invoice that will be produced.
4. **Client currency** and the **Rate** agreed for that month.

The rate needs explaining, because it is not what you might assume. **The
invoice is always raised in the company's own currency.** The client currency
and rate are used only to show what the bill comes to in the currency the client
is quoted in — on screen and on the statement. Nothing in the ledger is posted
at this rate.

Creating and editing runs needs `BillingRunCreate` and `BillingRunUpdate`.

## Building the invoice

Click **Build invoice**. The run gathers three groups of lines.

**Salaries** — one line per employee holding a payslip in that month and
fiscal year, at their **full gross earnings**, sorted by name. Employees whose
gross is zero are left out.

Gross, not net, and this is the part clients query most: tax withheld and
deductions taken are settlements between the company and the employee, and the
client funds the whole cost of employing them either way. Where those gross
figures come from is the payroll chapter.

**Expenses** — the month's payments, grouped into one line per kind of expense
so that a month of small food payments arrives as a single "Food" figure. A
payment counts when it falls inside the payroll month by its value date, and is
excluded when it is tied to a payslip or is of the salary transaction type —
that money is already in the salary lines above, and billing it twice is the
mistake this rule exists to prevent.

**Credits** — what employees repaid on their advances during the month, as one
negative line. An advance leaves the company's bank when it is paid out and is
billed to the client then; as the employee repays it out of payroll, the client
gets it back. Without this the client would fund the same money twice. If the
Advances module is not enabled, or nothing was repaid, there is no credit line.

If none of the three finds anything, the build refuses and says so rather than
producing an empty invoice.

## Rebuilding, and the trap in it

Once an invoice exists the button becomes **Rebuild invoice**.

**Rebuilding replaces the invoice's lines entirely rather than adding to
them.** This is deliberate — a month is usually billed before the last few
expenses have been entered, and appending would bill the earlier ones twice —
but it means any line you hand-edited on that draft is discarded too.

The practical order is: enter the month's expenses first, then build. If you
build early, rebuild once the month is closed off.

Rebuilding is only possible while the invoice is still a **draft**. Once it has
been issued the button disappears: it is posted to the ledger and the client is
holding a copy, so rewriting it would change a document that has left the
building. To correct an issued bill, void it and raise a new one as described in
the invoicing chapter.

## Checking it before it goes out

**Statement** shows the bill as the client is meant to read it, and is worth
opening before issuing anything:

- Employees as **columns** rather than one lump — Basic Salary, Extra Work,
  Petrol, Medical and Device Allowance always shown, with Bonus and Other
  appearing only in months that have them.
- Expenses listed **individually** — "House rent", "Gas" — rather than grouped
  as the invoice lines them. Grouping is right for an invoice and wrong here,
  where the itemised figure is the one somebody rings up to query.
- Credits and the currency conversion underneath.

**PDF** produces the same thing as a printable document.

Each employee row totals to the same gross the invoice line carries, and
anything in the gross that the named columns do not account for lands in
**Other** rather than going missing — so a row always adds up to what is being
billed. The statement's total and the invoice's total are checked to agree.

## Deleting a run

Only while its invoice is still rebuildable — that is, not yet issued — and
only with `BillingRunDelete`, which **no seeded role holds except
Administrator**. Accountant, Manager and CEO can all create, build and rebuild
runs but cannot delete one.

## Who can do what

| Permission | What it allows |
|---|---|
| `BillingRunView` | See billing runs, statements and PDFs |
| `BillingRunCreate` | Set up a run |
| `BillingRunUpdate` | Edit a run, and build or rebuild its invoice |
| `BillingRunDelete` | Delete a run (Administrator only) |

Accountant, Manager and CEO hold the first three. Issuing the invoice the run
produces is a separate matter, gated by `InvoiceIssue` — see the invoicing
chapter.

Billing Runs belong to the **Billing** module. It reads from Payroll, and from
Advances when that is enabled, but neither has to be licensed for the screen to
appear — a client with no advances simply has nothing to credit.
