
# Personal Finance — Implementation Plan

**Status:** Built (2026-08-06), after one significant redesign — see [The pivot](#the-pivot)
**Created:** 2026-08-06

Goal: let a person keep their own complete accounts inside this app — record what they
earn and what they spend (education and other categories), see a balance sheet of what
they own and owe, and get an estimate of the income tax they owe under Pakistani rules
according to how they earn.

Nothing like this exists today. Every existing module is company money: payroll, invoices,
the company ledger. The one per-person precedent is MPR, which keys on `user_id` in a
tenant table.

## The pivot

The first build was wrong, and the correction is worth recording because the
mistake is an easy one to repeat.

**What was built first:** a private per-user ledger. Its own `personal_*` tables,
a global scope keying every row to `auth()->id()`, and a model guard so not even
an Administrator could edit somebody else's records. About 2,100 lines.

**What the requirement actually was:** a person keeping their own books wants an
**accountant** to do it for them, and employs staff — a driver, a cook. That is
not a private ledger. That is a small organisation.

**Why that changes everything:** an organisation here is a *tenant*. Roles are
already scoped per tenant (`company_id` is the Spatie team key), so a personal
account gets Administrator, Accountant, Manager, Employee and CEO for free.
Employees, the chart of accounts, the Account Register, the Balance Sheet and the
P&L all already work per tenant. None of it needed building.

And the privacy mechanism was not merely redundant, it was **backwards**: a scope
keyed on the signed-in user would have hidden the books from the accountant hired
to keep them.

**What survived:** the individual Pakistani tax schedules and the estimate over
them — the one thing the app genuinely could not already do. About 500 lines.
**What was deleted:** roughly 1,250 lines, the same day they were written.

The lesson for next time: "personal" was read as "private". It meant "belonging
to a person rather than the business", which is a statement about *whose money*
it is, not about *who may see it*.

## Decisions

| Question | Decision |
|---|---|
| What a personal account is | A tenant, with `companies.type = personal` |
| Roles | The same five as any tenant — an accountant can keep somebody's books |
| Domestic staff | Employees with **no login**; `employees.user_id` is nullable |
| Paying staff | An expense, not a payslip. Payroll is not licensed for a personal account |
| The ledger | The tenant's own `accounts` / `journal_entries` — no parallel tables |
| Database | One per personal account, as for a company |
| Tax scope | Salaried, business, rental, capital gains, filer/non-filer |

## Facts verified against source (not assumed)

Everything below was read out of the codebase before planning, and each shaped a decision.

- **A policy cannot make anything private.** `AppServiceProvider::register()` installs a
  `Gate::before` returning `true` for every ability except `create` when the user is a
  super admin or holds Administrator. A policy returning `$record->user_id === $user->id`
  is therefore worthless against an Administrator. The codebase already documents this on
  `ExpenseClaim::assertNotOwnClaim()`: *"Enforced on the model rather than in the policy:
  Administrators and super admins pass every policy check, so a rule that has to hold for
  everyone cannot live in one."* → this drove the abandoned owner-scope design. It stopped
  mattering once a personal account became a tenant: access is membership, which the panel
  already enforces, rather than a per-row owner check.
- **`accounts.code` carries a global unique index** (`create_accounts_table`) with no owner
  column, which is exactly why the company chart cannot be made per-person — and, once a
  personal account became its own tenant, why it does not need to be: each tenant has its
  own `accounts` table, so two people's charts never meet.
- **`accounts.balance` is a cached scalar** maintained by `JournalEntryService::post()`. One
  scalar cannot represent per-owner balances — another reason a shared table keyed by owner
  was the wrong shape. Per tenant it is fine, and the existing reports work unchanged.
- **`JournalEntryService::approve()` hard-throws when approver === creator** (segregation of
  duties), so a person keeping their own books could never approve their own entry. → the
  Account Register is the entry surface, because `RegisterEntryService::bookRow()` builds
  the two lines and posts them in one step, sidestepping the workflow entirely.
- **`users` lives in the landlord DB** while domain tables are per-tenant. Cross-database
  joins fail (`LandlordUserColumn` documents the "Base table or view not found" failure,
  including on the paginator's `count(*)`). → `user_id` is a soft reference,
  `foreignId('user_id')->index()` with no `constrained()`, per `create_m_p_r_s_table`.
- **`TaxCalculatorService::annualTax()` is stateless and regime-blind** — it queries
  `salary_slabs` on `fiscal_year_id` alone. Adding a regime column there without also
  changing the query would silently mis-tax employees. → new module gets its own
  `tax_schedules` table; payroll's is untouched.
- **The fiscal year already is the Pakistani tax year** (July–June, `FiscalYearSeeder`
  seeds `2025-07-01`→`2026-06-30`). Note FBR names that period "Tax Year 2026" for the
  *ending* year while the app calls it "2025-2026" — any FBR-facing label needs
  translating.
- **`Impersonation` lets a company Administrator sign in as any non-super-admin user in
  their company.** This mattered a great deal to the abandoned privacy model and matters
  little now: access to a personal account is membership of that tenant, which is a
  deliberate act, not something impersonation quietly bypasses.
- **`CompanyFactory` licenses and enables every registry module** in `afterCreating`, so a
  new module is automatically on in existing tests.

## Phase 0 — two payroll bugs found while researching

Both live in `database/seeders/SalarySlabSeeder.php` and affect payroll **today**,
independently of this module. Fixed first, one commit each, so the module does not inherit
them.

**Bug 1 — high earners are silently zero-rated.** The FY2026-2027 top slab is
`7,000,000 – 50,000,000`. `annualTax()` matches
`min_amount < income AND (max_amount >= income OR max_amount IS NULL)`, so taxable income
above 50,000,000 matches no slab and the method returns `0.0` — no exception, no warning.
FY2025-2026 correctly uses `null`. Fix: `max_amount => null` on the top slab, plus a
`TaxCalculatorTest` case above the top threshold (the existing file has none).

**Bug 2 — the FY2026-2027 rates are unverified, and that is the year payroll uses.**
Recorded as a comment in the seeder; **no rates changed**, because guessing at tax law is
worse than flagging it.

`FiscalYearSeeder` marks both years active and `FiscalYear::booted()` stands down whichever
was activated first, so 2026-2027 wins and is what `FiscalYear::current()` returns — every
payslip is taxed on it.

What is verifiable from inside the repo: the 2025-2026 set matches the Finance Act 2025
salaried schedule exactly. The 2026-2027 set does not — eight brackets rather than six, and
20/25/29/32 in the middle where the enacted salaried schedule has 23/30/35.

An earlier reading of mine called it "the non-salaried schedule". That is wrong and worth
recording as wrong: Pakistan's non-salaried schedule opens at **15%** on the second bracket,
and this opens at **1%**, which is the salaried marker. Most likely these are provisional
figures for a tax year whose Finance Act was not yet enacted. **Action: confirm against the
Act.**

## What was built

**A personal account is a tenant** with `companies.type = 'personal'`. It provisions
through the same `CompanyProvisioner`, gets its own database, and gets the same five roles
as any other tenant — which is the whole reason this shape works, since an accountant
keeping somebody's books is just a role.

**`employees.user_id` is nullable.** A driver or a cook is employed and gets paid but never
signs in, and inventing an email address and password for them is worse than letting the
record stand alone. An `employees.name` column holds the name where there is no user to
take it from; for anybody who does sign in, the user record stays the single source of
truth, and `Employee::fullName()` encodes that precedence.

**Paying staff is an expense, not a payslip.** Payroll is deliberately not licensed for a
personal account, so there is no payslip, no acknowledgement and no accept/reject flow.
Domestic Staff Wages is an expense account in the personal chart.

**Module defaults** (`Modules::PERSONAL_DEFAULTS`): accounting, employees and
personal_finance. Not invoicing, projects, MPR or payroll. Core is always licensed since it
holds the Modules page.

**The personal chart of accounts** (`PersonalChartOfAccountsSeeder`) replaces the business
one: Education, Domestic Staff Wages, Food, Rent, Utilities, Transport, Medical, and income
accounts pre-tagged with their tax regime. It keeps codes 3200 and 3300, which the
accounting module looks up by code and without which an opening balance or a year close
would break.

**The tax engine**, which is the part the app could not already do:

- `tax_schedules`, one row per bracket per regime per tax year, seeded and editable, so a
  Finance Act is a re-seed rather than a code change.
- `PersonalTaxService`, which returns a **breakdown** — matched bracket, marginal rate,
  effective rate — and **raises rather than returning zero** when no bracket matches. That
  last point is the payroll bug above, refusing to be repeated.
- `accounts.tax_regime`, so income is classified once on the account instead of per entry.
  Untagged income is reported as unclassified rather than guessed at.
- A Tax Estimate page, visible only on a personal account, that opens on a year it can
  actually compute and states its limits on screen.

**Everything else is the existing app.** Chart of Accounts, Account Register, Balance
Sheet, Profit & Loss, Cash Flow — all unchanged, all working per tenant. The Account
Register is the everyday entry surface because `RegisterEntryService::bookRow()` builds the
two lines and posts them in one step, sidestepping the approval workflow that a household
has no use for. Accountant already holds `RegisterPost`, so the person's accountant can
keep the books.

## Known limits

- **Rental and capital gains use indicative flat rates**, not the real schedules, which
  depend on the asset and how long it was held. Labelled as such on screen.
- **Filer status is recorded and shown, not applied.** It changes withholding rates rather
  than the liability the brackets produce.
- **The estimate knows nothing about tax already deducted at source**, credits, deductible
  allowances, or the surcharge on high salaried income.
- **FY2026-27 brackets are not seeded** for the personal schedules, because the payroll
  rates for that year are themselves unverified. The Tax Estimate opens on the most recent
  year that *does* have rates rather than erroring.

## Verification

```
composer dump-autoload
vendor/bin/phpunit --filter="Module|Help|UserManual|PersonalTax|PersonalAccountProvisioning|EmployeeWithoutLogin"
vendor/bin/phpunit          # full suite
```

Tests:

- **`PersonalAccountProvisioningTest`** — a personal account provisions with the five
  roles, the household chart, the tax brackets and the right module licences; a business
  still gets exactly the registry defaults; a household can employ someone with no login.
- **`EmployeeWithoutLoginTest`** — a login-less employee exists, shows their name, and never
  leaks into `accessibleUserIds()` as user 0.
- **`PersonalTaxServiceTest`** — each regime against its schedule, the breakdown, income
  above the top bracket, a missing schedule raising rather than returning zero, unposted
  income not taxed, and the salaried figures pinned to the same expectations as
  `TaxCalculatorTest` so the two calculators cannot drift.
