Every other chapter in this manual is about a company's money. This one is about
a person's.

A **personal account** is a place to keep your own books: what you earn, what you
spend it on, what you own and owe, the people you employ at home, and roughly
what you will owe in income tax. It works exactly like a company in this app —
its own database, its own roles, its own chart of accounts — because a household
with an accountant and staff is, for these purposes, a very small organisation.

## What makes it different from a company

Only three things, and everything else in this manual applies unchanged.

1. **It is called a Personal Account**, not a company.
2. **It starts with a household chart of accounts** — Food, Rent, Education,
   Utilities, Transport, Medical, Domestic Staff Wages — instead of receivables,
   payables and sales tax.
3. **It has a Tax Estimate screen** using the Pakistani schedules for an
   individual, which a company does not get.

It also starts with a smaller set of modules: a ledger, staff records and the tax
estimate. No invoicing, no projects, and deliberately no payroll — see below.

## 1. Getting one

A super admin creates it from the **Platform** panel, the same way as a company,
choosing Personal Account as the type. Whoever it is created for becomes its
**Administrator**.

It gets its own database, so nothing in it is visible from any company, and a
person who also works at a business has two entirely separate sets of books.

## 2. Who can see and do what

This is the part worth understanding, because it is why a personal account is a
tenant rather than a private list.

A personal account has the same five roles as any company:

| Role | Typical use in a household |
|---|---|
| Administrator | You. Full control of your own account. |
| Accountant | Someone you ask to keep your books — they can record and reconcile everything |
| Manager | A family member or assistant who helps run things |
| Employee | Staff who have a login and need to see something |
| CEO | Rarely used here; the same approval powers as Manager |

Only people you invite into your personal account can see anything in it. Being
an Administrator of a *company* gives somebody no access at all to your personal
account — they are separate tenants.

## 3. Staff who work for you

Add them under **Employees**. A driver or a cook is an employee like any other,
with one difference: **they do not need a login**. Leave the user field empty and
type their name directly. They will never sign in, and no account is created for
them.

Staff who *do* need to sign in — an assistant who records expenses for you, say —
are created through **Users** instead, which makes the login and the employee
record together.

**Paying them is an expense, not a payslip.** A personal account does not run
payroll: there are no payslips, no acknowledgement step, and nothing to accept or
reject. When you pay the cook, you record it like any other expense, against the
**Domestic Staff Wages** account. That keeps their pay in your books and on your
Income & Expenditure without dragging in a payroll cycle a household has no use
for.

## 4. Recording what happens

Use **Account Register** under Reports. Pick the account the money moved through
— Cash in Hand, Bank Account — and add a row: the date, what it was, the amount,
whether it went in or out, and which category it belongs to.

Each row writes a balanced pair of entries behind the scenes, which is what makes
the reports add up. You never have to think about that.

The full **Journal Entries** screen is there if you want it, but it carries an
approval workflow — an entry has to be approved by somebody other than whoever
wrote it — which a household cannot satisfy and does not need. The Account
Register skips it deliberately.

## 5. Seeing where you stand

All of these are the app's existing reports, working on your own books:

- **Balance Sheet** — what you own, what you owe, and the difference.
- **Profit & Loss** — what came in and what went out over a period, by category.
  This is where "how much did education cost me this year" is answered.
- **Cash Flow** — where the money actually came from and went.
- **Account Register** — one account, every transaction, running balance.

## 6. Estimating your tax

**Personal → Tax Estimate** works out roughly what you owe for a tax year.

It groups your income by the **Taxed as** setting on each income account —
Salaried, Business, Rental or Capital gains — applies that schedule's brackets,
and shows its working: which bracket applied, the rate on the excess, and the
tax for each kind of income.

The starter chart already tags Salary, Business Income, Rental Income and Profit
on Investments, so it works without configuring anything. **Other Income** is
left untagged on purpose: there is no honest default for it, so income booked
there is reported as unclassified rather than guessed at. Set **Taxed as** on the
account to include it.

**It is an estimate, not tax advice**, and the screen says so. It does not know
about tax already deducted from you at source, tax credits, deductible
allowances, or the surcharge on high salaried income. Rental and capital gains
use indicative flat rates rather than the full schedules, which depend on the
asset and how long it was held.

If a year has no brackets set up, the screen says so plainly instead of showing
zero — "nothing is configured" and "you owe nothing" look identical if a tool
reports both as 0, and only one of them is good news.

## Tax years

July to June, the same as Pakistan's, and the same fiscal year the rest of the
app uses. Note FBR names a tax year for the year it *ends* in, so July 2025 to
June 2026 is FBR's **Tax Year 2026** while this app labels it **2025-2026**.

## Roles and permissions

The tax estimate is gated on `PersonalFinanceView`, which every seeded role
holds. Everything else — the chart of accounts, the register, the reports, the
employee records — uses the ordinary Accounting and Employees permissions
described elsewhere in this manual.

`PersonalFinanceViewAny` exists for an Administrator to look across accounts and
is not held by any other seeded role.
