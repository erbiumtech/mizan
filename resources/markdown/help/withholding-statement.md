# Tax Withheld (§165)

## What this is

Every deduction this company made from a supplier payment under **§153** of the Income Tax Ordinance: who was
paid, their NTN or CNIC, which section applied, the gross, the rate, and what was withheld.

It is the working paper behind the **§165 statement**. Filed monthly — pick the month in the filter bar — and
read for the year when you are reconciling a year of challans, which is what you get with no month chosen.

Salary withholding (§149) is not here. That is payroll's, on *FBR Tax File*.

## Turning withholding on

Nothing is withheld until you say which section applies to which supplier. Two fields on the
**beneficiary**:

- **Withholding section** — §153(1)(a) for goods, (1)(b) for services, (1)(c) for a contract, and the
  transport carve-out. Empty means the supplier is paid gross, which is how every supplier is paid until you
  choose one.
- **On the Active Taxpayers List** — a filer is withheld from at roughly half the rate. Check FBR's list
  before turning it on: the difference is the company's liability if it is wrong, which is why a new
  beneficiary starts as a non-filer.

The sections themselves arrive with the company as reference data. **Confirm the rates against the current
Finance Act before relying on them** — they move most years.

## What happens when a payment is approved

The deduction is made at approval, on the entry the payment already posts. Nothing extra to remember and no
extra step:

| | |
|---|---|
| **Debit** what the money was for | the gross |
| **Credit** Cash/Bank | the net — what actually leaves |
| **Credit** Income Tax Payable | the tax withheld |

The **bank payment file pays the net.** The payment's own amount stays the gross, because that is what the
company owes; the transfer is smaller by the tax it keeps back to remit.

## The thresholds

Below them, nothing is withheld — and they are most of what §153 is:

- a **per-payment** limit is checked against this payment on its own;
- an **annual** limit is checked against everything paid to that supplier in the tax year, including this
  payment. A monthly retainer under the limit becomes withheld from in the month the year's total crosses it.

Either one being reached is enough. The payment that crosses the annual limit is withheld from **in full**,
and earlier payments are not revisited: they are settled and posted, and going back would mean a tax line on
money somebody already banked. If you need the catch-up, make it as one journal entry so that a person has
decided the amount.

## Reading the statement

The sentence under the figures counts the deductions, the payees, the sections used, and **how many were at
the non-filer rate**. That last one is the figure to look at: a statement that is mostly non-filers is either
a real supplier base or a beneficiary list nobody has updated since those suppliers started filing.

The gross and the rate are recorded on each deduction as they stood on the day, so a supplier who starts
filing next year does not change what was deducted from them last year.

## Correcting one

There is no edit. A deduction belongs to an approved payment and its posted entry, so correcting it means
reversing the payment — the same answer the rest of the ledger gives.

## Roles and permissions

`ReportView`, like every other report. Assigning a section to a supplier is `BeneficiaryUpdate`.
