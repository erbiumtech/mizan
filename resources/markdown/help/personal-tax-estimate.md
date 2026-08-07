![Tax Estimate](/images/help/personal-tax-estimate.png)

## This is an estimate, not tax advice

Read this part before you rely on any number on this screen.

What you see here is your recorded income run through the Pakistani tax
brackets. It is a useful guide to what you are heading towards. It is **not**
your tax return, and it will not match your final liability, because it does not
know about:

- **Tax already deducted at source** — if your employer withholds tax from your
  salary each month, that is not subtracted here. The figure shown is total
  liability for the year, not what is still outstanding.

- **Tax credits** — pension contributions, charitable donations and the rest.
- **Receipted property deductions** — insurance, loan interest, property tax,
  collection charges. The automatic one-fifth repair allowance on rent **is**
  applied; these specific ones cannot be, because your ledger does not record
  them.
- **Anything you have not recorded.** Income you never entered is income this
  screen cannot see.

For anything that matters — filing, planning around a large sum, a disagreement
with FBR — talk to somebody qualified.

## What it does apply

- **The section 4AB surcharge**, where it is due: a percentage of the *tax*, not
  of your income, once taxable income passes PKR 10,000,000. It was 9% for
  salaried income up to tax year 2026 and was withdrawn for salary from tax year
  2027; 10% continues for business and rental income. Where it applies, the
  screen shows the tax and the surcharge separately so the arithmetic is
  checkable.
- **The repair allowance on rent** — one fifth of the rent, allowed automatically
  with no receipts. Rental income is never taxed on the gross, and the
  breakdown shows the allowance deducted.
- **Ordinary slab rates on rental income.** Property income has had no separate
  rate table since 2019: net rent is added to income and taxed at the normal
  schedule.

## How your income gets classified

Pakistan taxes different kinds of income on different schedules, so the estimate
first has to know which of your income is which.

That comes from the **Taxed as** setting on each of your income accounts, not
from anything you pick here. Tag "Salary" as Salaried once, and every amount
ever booked against it is treated as salary income from then on.

Income sitting on an account with **no Taxed as set** is shown separately as
**unclassified** and is **not taxed** in the estimate. That is deliberate: a
blank you can see is better than a guess you cannot. If your total looks too
low, this is the first thing to check.

## The four schedules

- **Salaried** — the FBR salaried individual brackets. These match the same
  figures the payroll side of this app uses, and are the most trustworthy
  numbers on this screen.
- **Business / self-employed** — the non-salaried individual brackets.
- **Rental / property income** — see the warning below.
- **Capital gains** — see the warning below.

**Rental and capital gains are indicative flat rates, not the real schedules.**
Property income and capital gains genuinely depend on things this module does
not track — for capital gains, what the asset was and how long you held it. They
are here so that income of those kinds produces *a* number rather than a silent
zero, and so the regime shows up end to end. Treat them as a rough marker only.

## Reading the breakdown

For each kind of income you will see:

- **Income** — the total booked to accounts tagged with that regime for the year.
- **Bracket** — which band of the schedule it landed in.
- **Marginal rate** — the percentage charged on your next rupee of that income.
- **Effective rate** — tax divided by income. Always lower than the marginal
  rate, and the more meaningful of the two if you want to know what you are
  actually paying overall.

Each schedule is a fixed amount plus a percentage of whatever you earned above
the bottom of the band, which is why a small rise in income never costs more in
tax than it gains you.

Each kind of income is assessed on its own schedule and the results added
together. A real return aggregates some of these before applying one schedule,
so this is a simplification — another reason the total is a guide rather than a
figure to file.

## The tax year

July to June, matching Pakistan's tax year, so the period lines up with what a
return covers.

FBR names that period after the year it *ends* in — July 2025 to June 2026 is
FBR's **Tax Year 2026** — while this app labels it **2025-2026**. Same twelve
months.

## If a year has no brackets set up

You will be told so plainly, with an error naming the schedule and the year.

That is on purpose and it is the most important design decision on this screen.
The obvious alternative — showing zero when no bracket matches — is
indistinguishable from "you owe nothing", and that exact behaviour once caused
the highest earners on the payroll side to be assessed at nothing without any
warning. A visible error is better than a confident wrong number.

If you hit it, the brackets for that tax year need setting up before the
estimate can work.

## Filer status

Recorded on your tax profile and shown, but **not applied** to the figures here.

Being on the Active Taxpayers List changes the rates at which tax is *withheld*
from you — on bank profit, on property transactions, at source — rather than the
liability the income brackets produce. Quietly inflating your estimate because
you are a non-filer would be wrong arithmetic presented as a feature.

## Quick answers

**The estimate is much higher than the tax actually taken from my salary.**
Expected. This is your liability for the whole year; your employer has been
withholding against it monthly. Nothing here subtracts what has already been
paid.

**Some of my income is listed as unclassified.**
The income account it came from has no **Taxed as** setting. Open **My
Accounts**, set it, and the estimate will pick it up. Until then that income is
excluded rather than guessed at.

**Why is the rental figure so rough?**
Because it is a flat indicative rate rather than the real property schedule. See
above.

**Can I use this to file my return?**
No. Use it to know roughly where you stand, and take proper advice for the
return itself.

**My income is right but the tax looks wrong.**
Check which schedule each income account is tagged with. Business income is
taxed considerably more heavily than salary at the same amount, so a
mis-tagged account moves the total a long way.
