
# Personal Finance — Implementation Plan

**Status:** Planned (2026-08-06) — not yet started
**Created:** 2026-08-06

Goal: let a person keep their own complete accounts inside this app — record what they
earn and what they spend (education and other categories), see a balance sheet of what
they own and owe, and get an estimate of the income tax they owe under Pakistani rules
according to how they earn.

Nothing like this exists today. Every existing module is company money: payroll, invoices,
the company ledger. The one per-person precedent is MPR, which keys on `user_id` in a
tenant table.

## Decisions

| Question | Decision |
|---|---|
| Where data lives | Tenant DB, per company — a person in two companies gets two separate ledgers |
| Data model | True double-entry, in the module's **own** tables |
| Separation | Separate tables mirroring the ledger shape, so company reports cannot see personal money by construction |
| Tax scope | Salaried, business, rental, capital gains, filer/non-filer |
| Payroll import | None — income is entered by hand |
| Privacy | Owner manages their own; Administrator may **view** |
| Tax schedules | New table owned by this module; `salary_slabs` stays payroll's |

## Facts verified against source (not assumed)

Everything below was read out of the codebase before planning, and each shaped a decision.

- **A policy cannot make anything private.** `AppServiceProvider::register()` installs a
  `Gate::before` returning `true` for every ability except `create` when the user is a
  super admin or holds Administrator. A policy returning `$record->user_id === $user->id`
  is therefore worthless against an Administrator. The codebase already documents this on
  `ExpenseClaim::assertNotOwnClaim()`: *"Enforced on the model rather than in the policy:
  Administrators and super admins pass every policy check, so a rule that has to hold for
  everyone cannot live in one."* → ownership must be a global scope plus a model-level
  guard.
- **`accounts.code` carries a global unique index** (`create_accounts_table`) with no owner
  column, which is exactly why the company chart cannot be made per-person. → the personal
  chart uses a composite `unique(['user_id','code'])`.
- **`accounts.balance` is a cached scalar** maintained by `JournalEntryService::post()`. One
  scalar cannot represent per-owner balances; the company's own report services already
  sidestep it by recomputing from lines. → personal balances are always derived from lines,
  with no cached column.
- **`JournalEntryService::approve()` hard-throws when approver === creator** (segregation of
  duties), so a solo person could never approve their own entry. → no approval workflow;
  mirror `RegisterEntryService::bookRow()`, which builds two lines and goes straight to
  posted.
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
  their company.** No scope keyed on `auth()->id()` survives that. Given the Administrator
  is already permitted to view, this widens rather than breaks the model, but it is a known
  property, not a surprise. Impersonated actions are recorded with `impersonated_by`.
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

**Bug 2 — the FY2026-2027 schedule looks like the wrong regime.** Eight brackets with
29%/32% steps is the shape of the FBR *non-salaried* schedule, sitting in a table
`TaxCalculatorService` treats as salaried. **Not fixed unilaterally** — rewriting tax rates
on my own judgement is exactly the wrong move. Deliverable is a written finding; the real
FY2026-27 salaried numbers need confirming against the Finance Act before anyone edits.

## Phase 1 — module skeleton

Directory `app/Modules/PersonalFinance` ⇒ registry key **must** be `personal_finance`;
`ModuleMap::moduleFor()` derives it as `Str::snake(basename($dir))` and returns null on a
mismatch, which makes every gated class throw.

- `config/modules.php` — label `Personal Finance`, `requires: []` (deliberately no
  dependency on accounting or payroll — it owns its tables and its tax code),
  `licensed_by_default => false`, `plugin => PersonalFinancePlugin::class`.
- `PersonalFinancePlugin.php` — shape of `AdvancesPlugin`; `discoverResources` +
  `discoverPages`.
- `PersonalFinanceServiceProvider.php` — `POLICIES` const looped through `Gate::policy()`,
  per `ExpensesServiceProvider`.
- `bootstrap/providers.php` — append the provider. No `AdminPanelProvider` change; it
  already calls `->plugins(Modules::plugins())`.

## Phase 2 — the personal ledger

Migrations in `database/migrations/tenant/`; models extend `App\Models\TenantModel`.

```
personal_accounts     user_id, code, name,
                      type(asset|liability|equity|income|expense),
                      tax_regime(nullable), opening_balance, is_active
                      unique(['user_id','code'])      ← not globally unique
personal_entries      user_id, date, description, fiscal_year_id
personal_entry_lines  personal_entry_id, personal_account_id, debit, credit
```

`PersonalEntryService` mirrors the guarantees of `JournalEntryService::validateLines()`:
at least two lines, debit XOR credit per line, non-negative, balanced, one transaction.

**Usability point that decides whether this survives contact with a real user.** Nobody
hand-writes debits and credits to log lunch. The everyday surface is three actions —
**Record income**, **Record expense**, **Transfer** — each building the two balanced lines
itself. The raw entry screen stays for anyone who wants it.

A starter chart is seeded per user on first use: **Education** alongside Rent, Food,
Transport, Utilities, Medical and Other, plus Cash and Bank. Seeded on demand, not in
`TenantBaselineSeeder` — it is per user, not per company.

## Phase 3 — the tax engine

```
tax_schedules          fiscal_year_id, regime, min_amount, max_amount(null=top),
                       fixed_tax, percentage
personal_tax_profiles  user_id, fiscal_year_id, filer_status, notes
```

`regime`: `salaried | business | rental | capital_gains`.

`PersonalTaxService` — its own calculator, so the module keeps `requires: []` and stays
clear of `ModuleBoundaryTest`. Same proven arithmetic
(`fixed_tax + percentage × (income − min_amount)`) but it returns a **breakdown** — matched
slab, marginal rate, effective rate — because a personal tax screen must show its working.
It **errors rather than returning zero** when no slab matches, which is Bug 1's root cause.

Income is bucketed by regime through a `tax_regime` attribute on each income account: tag
"Salary" once and every entry against it is classified.

**Honest scope.** Encoding Pakistani tax law fully is large and consequential. What this
phase guarantees: every rate is **seeded, editable, per-fiscal-year data**, so a Finance Act
is a re-seed rather than a code change; the breakdown is visible so figures are auditable;
and the screen and help both state plainly that this is an estimate, not tax advice.

Encoded but flagged for confirmation rather than asserted correct:

- the salaried-vs-business threshold (dominant-share rule — exact percentage to confirm);
- the 9% surcharge on high salaried income from Finance Act 2025, absent from the app today;
- capital gains rates by asset class and holding period;
- what filer/non-filer actually changes — it drives *withholding* rates, not slab
  liability, so v1 records and displays it rather than silently applying it to the result.

Payroll's exemption logic is **not** reused. Its 10% medical exemption is applied to total
earnings, uncapped, and to employees with no medical allowance at all — the real rule is
10% of *basic salary*. That bug is not copied.

## Phase 4 — screens

Under a `Personal` navigation group, all gated by `BelongsToModule`:

- **Accounts** (resource) — the person's chart with balances.
- **Transactions** (resource) — the ledger, with the three quick actions.
- **Balance Sheet** (page) — assets, liabilities, net worth. The hard, already-correct part
  is ported rather than reinvented: `FinancialReportService::positionSection()` /
  `SECTION_SIDE` and `GeneralLedgerService::balanceAsOf()` take account-shaped and
  line-shaped rows, so they port near-verbatim. The page is a near-copy of
  `Accounting/Filament/Pages/BalanceSheet.php`.
- **Income & Expenditure** (page) — earnings vs spending by category for a fiscal year;
  this is where "how much went on education" is answered.
- **Tax Estimate** (page) — income by regime, slab applied, breakdown, estimate, disclaimer.

### Ownership — the most important implementation note

Per the `Gate::before` finding above, ownership is enforced structurally, with the policy
as documentation rather than as the gate:

1. **`BelongsToOwner` trait** — `booted()` global scope filtering to `auth()->id()`, plus a
   `creating` hook stamping `user_id`. No privileged bypass in the scope.
2. **Model-level guard on save and delete** (the `ExpenseClaim` pattern) throwing when the
   row's `user_id` is not the acting user. This is what actually delivers "Administrator may
   view but **not edit**", which the Gate would otherwise override.
3. **Administrator cross-user view** is an explicit opt-in: a `withoutOwnerScope()` escape
   used only on the admin read path, gated on `PersonalFinanceViewAny`. Deliberately **not**
   `ScopesToAccessibleEmployees` — that grants a manager their whole downline, the opposite
   of what is wanted.

Permissions in `PermissionSeeder`, group `PersonalFinance`, mirrored in
`ModuleMap::PERMISSION_GROUPS`: `PersonalFinanceView`, `PersonalFinanceCreate`,
`PersonalFinanceUpdate`, `PersonalFinanceDelete`, `PersonalFinanceViewAny`.
`RoleSeeder`: Administrator gets everything via `Permission::all()`; **Employee** gets
View/Create/Update/Delete — this module is for everyone, not only finance staff.

`ModuleMap` additions: `MODELS` (alias exactly `App\Models\<Basename>`), `RESOURCES`,
`PAGES`, `PERMISSION_GROUPS`.

## Phase 5 — documentation

Not optional — `HelpCoverageTest` fails the build if a resource list page or standalone page
lacks `HelpAction::make(...)` with a matching `resources/markdown/help/<slug>.md`. Five help
docs, with `<!-- requires: ... -->` annotations on action sections per the role-aware help
mechanism. Screenshots via `php artisan help:screenshots`. Plus manual chapter
`resources/markdown/manual/13-personal-finance.md` registered in `UserManual::CHAPTERS`
(`UserManualTest` asserts the list and the directory agree in both directions).

## Verification

```
composer dump-autoload
php artisan test --filter="ModuleCoverageTest|ModuleGatingTest|ModuleBoundaryTest|\
HelpCoverageTest|HelpContentAccuracyTest|UserManualTest|FilamentResourcesSmokeTest|\
ModulePermissionFilteringTest|TaxCalculatorTest"
php artisan test          # full suite — 1312 green as of 2026-08-06
```

New tests:

- **`PersonalTaxServiceTest`** — each regime against its seeded schedule, boundary amounts,
  income above the top slab (must **not** return zero), and the breakdown figures.
- **`PersonalLedgerTest`** — entries must balance; the three quick actions produce the right
  two lines; balances and net worth add up.
- **`PersonalFinancePrivacyTest`** — the decisive one: user A cannot see, open, edit or
  delete user B's rows through the resource, the table query, or a direct record URL; an
  Administrator can view but **not** edit; and no personal row appears in the company Trial
  Balance, P&L, Balance Sheet or Account Register.

Manual check: `php artisan help:screenshots --only=personal-accounts …`, then sign in as an
Employee and as an Administrator to confirm ownership holds in the browser.

For existing companies the module is off by default — `php artisan tenants:migrate`, then
license it per company on the Modules page.
