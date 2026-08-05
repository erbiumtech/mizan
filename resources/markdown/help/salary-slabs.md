![Salary Slabs](/images/help/salary-slabs.png)

## What a salary slab is

A **Salary Slab** is one bracket of a progressive annual income-tax table for
a fiscal year — the same shape as a government tax schedule: a range of
annual income, a fixed tax for reaching that range, and a percentage charged
on whatever income exceeds it. Every payslip's **Withholding Tax** and every
employee's **Annual Tax** reconciliation is calculated by finding which slab
an income falls into and applying it — nothing about the tax rate is
hard-coded, it all comes from the slabs configured here.

## How the calculation reads a slab

For an annual taxable income figure, the app finds the slab for the active
fiscal year where `min_amount` is less than the income and `max_amount` is
greater than or equal to it (or has no maximum — the top slab is normally
left open-ended). The tax is then:

```
tax = fixed_tax + percentage% × (income − min_amount)
```

If no slab matches — most commonly because none has been set up yet for a
fiscal year, or the income falls below every slab's minimum — tax comes out
as zero rather than erroring, so a missing slab is easy to miss unless
withholding tax on payslips for that year is checked directly.

## Adding one

Click **New** and fill in:

- **Fiscal Year** — only active fiscal years are offered.
- **Minimum Amount (Annual)** — the income this slab starts applying from.
- **Maximum Amount (Annual)** — where it stops; leave blank for an open-ended
  top slab.
- **Fixed Tax Amount** — the tax already owed just for reaching this slab.
- **Tax Percentage (%)** — charged on the amount above the minimum.

Slabs for a fiscal year should cover the whole range with no gaps and no
overlaps — the app doesn't validate that for you, so a typo in one slab's
range can silently zero out or misprice everyone whose income lands in the
gap.

## Roles and permissions

| Action | Permission required |
|---|---|
| View | `SalarySlabView` |
| Create | `SalarySlabCreate` |
| Update | `SalarySlabUpdate` |
| Delete | `SalarySlabDelete` |

These same four permissions also gate Pay Components — see that page's help
for why. Of the seeded roles, only **Administrator** holds them; Accountant,
Manager and CEO run payroll but do not configure its tax brackets. This
resource lives in the `payroll` module.
