# Company profiles: licensing modules by what kind of business this is

> **Built.** Module mechanics are in `docs/modules-plan.md` and
> `docs/new-module-checklist.md` and not repeated here; this document decides
> *what decides* which modules a new company starts with.
>
> Three decisions were taken against the first draft of this document and are
> folded in below: the profile list in §3 is accepted as proposed, the
> two-field design in §1 is accepted, and **a profile drives seeding as well as
> licensing** — reversing what §8 originally recommended. §9 records what that
> costs and the one constraint that keeps it safe.

The feature already exists, for exactly one case. `Company::type` is
`business | personal`, and `CompanyProvisioner:63-68` licenses
`Modules::PERSONAL_DEFAULTS` instead of the registry defaults when the type is
personal. Nothing else about the idea is missing — no new enforcement, no new
table semantics, no new gate. What is missing is a second axis, because the one
that exists cannot carry the weight.

Three findings shape the rest.

## 1. `type` already means two incompatible things

Look at what the create form promises, and it is honest about the conflation
(`CompanyForm.php:39-45`): *"Fixed once created — the chart of accounts and
starting modules follow from it."* Those are not the same kind of decision.

| Half | What it decides | Reversible? |
|---|---|---|
| **Structural** | Which chart of accounts, which transaction types, which tax schedule got seeded — `PersonalChartOfAccountsSeeder` + `PersonalTransactionTypeSeeder` vs `ChartOfAccountsSeeder` + `TransactionTypeSeeder` + `SalarySlabSeeder` | **No.** Once a journal entry points at account `Food`, the company is that shape. |
| **Commercial** | Which modules were licensed | **Yes, by design.** `Modules.php:340-345` says it outright: *"A licence, not a lock. Anything here can be switched off and anything absent can be granted later."* |

Adding `trading`, `services`, `manufacturing` as new **types** drags the
commercial half into the irreversible one. "We picked Services and they've
started selling goods" then becomes a re-provision, for a decision that was
never meant to be permanent.

It also breaks a dichotomy that six call sites depend on. `isPersonal()` is used
by `AccountForm.php:53`, `TaxEstimate.php:64`, `CompanyProvisioner.php:67,90`,
`SeedTenantBaseline.php:108,151` and `CompaniesTable.php:34`, and every one asks
*household or not*. A third type makes all six ambiguous without changing a
line of them. `Company::scopeBusiness()` is worse: `where('type', 'business')`
would silently exclude a trading company from a query named "business". It
happens to be unused today — that is luck, not safety.

### The recommendation: two fields

- **`type` stays two-valued.** Structural, immutable, exactly as now.
- **`profile`** — new, nullable string on `companies`. The *shape* of the
  business. Drives the initial licence set **and the baseline seeders**. A super
  admin can change it afterwards; licences can be re-applied as an explicit
  action.

The obvious objection is that letting a profile pick seeders drags it back into
the immutable half — which is exactly what §1 argues against for `type`. What
keeps the two apart is a single constraint, and every other design choice here
follows from it:

> **A profile drives seeding at provision time only.** Changing the profile
> afterwards re-labels the company and re-scopes what "recommended" means. It
> never re-seeds, because a chart of accounts with journal entries posted
> against it cannot be swapped, and it never revokes a licence.

So `type` stays irreversible because *what it seeded is all it ever was*, while
`profile` stays editable because seeding is only the first thing it does, not
the definition of it. A company that started life as Trading and is now
Manufacturing gets the Manufacturing label, the Manufacturing recommendations
and a one-click licence top-up — and keeps the trading chart it has been posting
to for two years, which is the correct outcome and the only available one.

## 2. A preset must be closed under `requires`, or it sells a dead module

`Modules::enabledFor()` recurses into requirements (`Modules.php:152-156`), so a
module whose requirement is unlicensed is licensed, shows a toggle on the
company's Modules page, and can never be switched on. Licensing `billing`
without `invoicing` and `accounting` produces precisely that — a toggle that
silently refuses.

`PERSONAL_DEFAULTS` is closed by hand (`personal_finance` → `accounting` ✓,
`employees` ✓) and nothing checks it. A hand-written set of seven profiles will
not stay closed. This needs a test before it needs a UI.

## 3. Profiles overlap; the honest model is a set, not a partition

A proposed set over the twelve modules that exist. Core is implicit — it is
locked and always licensed (`Modules.php:359-361`).

| Profile | Licensed on top of Core |
|---|---|
| **Personal account** | `accounting`, `employees`, `personal_finance` — today's `PERSONAL_DEFAULTS`, unchanged |
| **Services / consultancy** | `accounting`, `employees`, `payroll`, `invoicing`, `projects`, `mpr`, `expenses`, `advances` |
| **Software house / agency** | `accounting`, `employees`, `payroll`, `invoicing`, `projects`, `mpr`, `expenses` |
| **Staffing / outsourcing** | `accounting`, `employees`, `payroll`, `invoicing`, `billing`, `advances`, `expenses` |
| **Trading / distribution** | `accounting`, `invoicing`, `inventory`, `employees`, `payroll` |
| **Manufacturing** | `accounting`, `invoicing`, `inventory`, `employees`, `payroll`, `expenses` |
| **Bookkeeping only** | `accounting`, `invoicing` |

Every row above is closed under `requires` (checked against `config/modules.php`:
`billing` → employees+payroll+invoicing ✓, `advances`/`expenses` →
employees+payroll ✓, `projects` → employees ✓, `inventory`/`invoicing` →
accounting ✓). Every non-core module appears in at least one row — which is the
second invariant, and the one that catches a module nobody can ever buy.

### What each profile seeds

The seeder list is the other half of a profile, and it is not free to disagree
with the module list. Two rules cover every profile that exists today:

| Rule | Why |
|---|---|
| A personal-type profile seeds `PersonalChartOfAccountsSeeder` + `PersonalTransactionTypeSeeder`; a business-type one seeds `ChartOfAccountsSeeder` + `TransactionTypeSeeder` | The transaction types are keyed to their chart's account codes — `PersonalBaselineSeeder` says so — so a mismatched pair produces categories pointing at accounts that mean something else |
| `SalarySlabSeeder` runs **iff** the profile licenses `payroll` | Slabs with no Payroll module are reference data for a screen nobody can open; Payroll with no slabs cannot tax anybody |

Everything else — fiscal years, currencies, banks, tax schedules — is
unconditional, exactly as both baseline seeders have it today. Only **Bookkeeping
only** actually diverges from `TenantBaselineSeeder::seeders()`, by dropping the
salary slabs it has no payroll for.

Both rules are asserted, because the failure mode is a company that provisions
cleanly and is missing reference data nobody notices for months — which is the
documented reason `tenants:seed-baseline` had to be written in the first place.

## 4. Where the mapping lives

**`config/company_profiles.php`** — a profile registry alongside the module
registry. Not a `'profiles' => [...]` key on each module entry.

The counter-argument deserves stating, because `docs/new-module-checklist.md` §1
puts everything else about a module in its registry entry, and co-location is
the reason that checklist works. But the two directions have different failure
modes at different rates:

- Modules are what grow. The HRMS plan adds leave, attendance, recruitment,
  appraisals and exit; the CRMS plan adds a pipeline. Per-module `profiles` keys
  means every new module is an N-way edit across every profile, and an omission
  is silent — the module is simply never licensed for anyone.
- Profiles are a short, slow-moving list. One entry each.

The forcing function co-location would have given is recoverable as a test:
`test_every_module_appears_in_at_least_one_profile` fires on the same pull
request, at the same moment, with a better message than a missing array key.

## 5. Preset, not lock

The profile must not constrain what a super admin can license.

1. **It buys no enforcement.** `licensed && enabled && requirements` is already
   checked in `Gate::before`, `EnsureModuleEnabled` and every `canAccess()`. A
   profile constraint adds a fourth reason a module can be unavailable and no
   error message anywhere explains it.
2. **Sales makes exceptions.** A hard lock makes the exception a deploy.
3. **It would contradict the rule already shipped** for personal accounts. One
   rule that is soft everywhere beats two rules that differ by profile.

What the profile *should* do beyond seeding is inform the licensing screen:
group the `Licensed modules` section (`CompanyForm.php:76-85`) into *Recommended
for a Trading company* and *Other*. Relevance as information. That also gives
the stored column a live consumer, which is the only thing that stops it rotting
into a value nobody re-reads.

## 6. What breaks silently

| Thing | What happens |
|---|---|
| A profile not closed under `requires` | Module is licensed, toggle appears, `enabledFor()` returns false forever. Reads as a broken toggle, not a bad preset. |
| Re-applying a profile via `seedDefaults()` | It writes `['licensed' => …, 'enabled' => $default ?: null]` (`Modules.php:367`) — which **resurrects a module the company deliberately switched off**. Re-apply must write `licensed` only, exactly as `EditCompany::afterSave()` does and for the reason in its comment at lines 80-84. |
| A new module in no profile | Never licensed for any new company. Found by the first customer who asks for it. |
| A profile naming a module key that does not exist | `seedDefaults()`'s `in_array` just fails to match and the module is omitted. `CompanyTypeOnCreateFormTest.php:169-172` already guards this for `PERSONAL_DEFAULTS`; generalize that assertion over every profile. |
| Profile stored, never read again | The column disagrees with reality within a month. §5's grouping is what prevents it. |
| A profile whose seeders disagree with its modules | Payroll licensed with no salary slabs cannot tax anybody; slabs with no Payroll are data for a screen nobody can open. Neither throws. §3's two rules are asserted for this reason. |
| Changing a profile appearing to re-seed | It does not, and must not. The stored profile therefore describes the company, not necessarily what its tenant database was built from — see §9. |

## 7. Phasing

Small, because the enforcement already exists and none of this touches it.

| Phase | Work | Risk |
|---|---|---|
| **0** | `config/company_profiles.php`, `App\Support\CompanyProfiles`, `profile` column (nullable, **no backfill** — an unset profile means "licensed by hand", which is true of every company today). Tests: closure under `requires`, every module covered, every key resolvable. No behaviour change. | none |
| **1** | Provisioner reads it: licences from `CompanyProfiles::modules()`, baseline from `CompanyProfiles::seeders()`. `PERSONAL_DEFAULTS` becomes the `personal` profile entry. | low |
| **2** | Create form: a Profile select, options filtered by `type` (a personal account offers only the personal profile). Helper text names what it licenses and seeds. | low |
| **3** | Edit page: profile editable, plus an **Apply profile licences** action that grants only — never revokes, never touches `enabled`, never re-seeds — behind a confirmation listing exactly what it will grant, logged like every other licence change. | low |
| **4** | Group the Licensed modules section by relevance to the profile. | none |

## 8. Open question

**May "Apply profile licences" revoke?** No. Revoking is a billing event and
should be a toggle somebody deliberately moved, not a side effect of correcting
a dropdown. The action is grant-only, which also makes it safe to run twice.

## 9. What "profile drives seeding" costs

Recording the trade honestly, because it is the one place this design is
knowingly lossy.

A profile now decides two things with different lifetimes: a licence set that
can be re-applied for ever, and a baseline that is written once and then buried
under customer data. The column stores the *current* answer, so after a profile
is edited it describes what the company is, **not necessarily what its tenant
database was seeded from**.

That gap is real and it is not worth closing. The alternatives are worse:

- **Freeze the profile at create**, like `type`. Then a company that changes
  shape can never be re-labelled or re-recommended, and the column is a
  provisioning artefact rather than a description — which is the problem §1
  exists to fix.
- **Store both** (`profile` and `seeded_profile`). A second column, a second
  thing to keep true, and the only consumer would be a diagnostic nobody runs.
- **Re-seed on change.** Not available: `ChartOfAccountsSeeder` cannot replace a
  chart that has journal entries posted against it, and pretending otherwise is
  how you lose a company's books.

The mitigation is the one that already exists: `tenants:seed-baseline` tops up
missing reference data for a company whose baseline has fallen behind, per
company, idempotently, and it already refuses to re-run `SalarySlabSeeder` over
slabs somebody has corrected by hand. It follows the profile now, which means it
is also the answer to "we changed the profile, what does that company still need".
