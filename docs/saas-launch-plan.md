# SaaS launch: plan

> **Not built.** Written 2026-10-28 from a code inventory and a feature-by-feature reading of
> Operentra's published module list and pricing (operentra.com/features, /pricing), the nearest
> comparable product. Nothing below exists unless marked so. Mechanics common to any new module
> are in `docs/new-module-checklist.md`; licensing in `docs/modules-plan.md` and
> `docs/company-profiles-plan.md`; the tenant-less panel in `docs/platform-panel-plan.md`. None
> of that is repeated here. This document decides *what is sold, to whom, how it is metered, and
> what is missing before a stranger can buy it*.
>
> **Decided 2026-10-28, against the first draft.** The first draft put nine things under "what
> not to build" — badges and rewards, daily reports with AI verdicts, recurring tasks with
> penalties, the merit-evidence ledger, cheque printing, field visits, custom domains per tenant,
> live biometric devices, and branches — on the argument that none is what a Pakistani SME buys
> payroll software for. The owner's decision is that a buyer comparing the two lists must find
> no empty row, and the argument is withdrawn. §7.9 and §8 carry their designs; §13 keeps only
> what is deferred for a business reason rather than a taste.
>
> **Added the same day, on the owner's instruction:** a rule builder for badges and tasks (§8.9)
> — the first draft's "three classes, not a language" survives as the reason the builder is a
> form over two registries rather than an expression language — and multi-language support
> (§8.10), which is on nobody's feature page and is the one item here that is not parity. And
> the document locale is both per company and per document kind, and a device identifies an
> employee by its own `device_pin`, never by the employee code — both decided the same day. So
> are the prices: PKR, USD and EUR lists with a ten-seat minimum, in §3.1, every figure a
> proposal the owner changes in one file. And the subscription invoices issue themselves (§6),
> which narrows the invoicing module's "a person issues" rule by exactly one flag.

**Created:** 2026-10-28
**Covers:** the scorecard against Operentra (§1), what the product is sold as (§2), tiers as
licence presets (§3), the commercial record (§4), self-serve signup (§5), metering and invoicing
through the operator's own books (§6), the HR gaps to close first (§7), the rest of their list
plus the rule builder and languages (§8), operating it (§9), phases (§10), tests (§11), open decisions (§12), what not to build (§13), depth parity with their signed-in product (§14).

Four findings shape everything below, and the second is what makes the commercial half small.

1. **The feature distance is small; the plumbing distance is the whole launch.** Operentra
   lists 101 features in ten groups. 76 exist here fully, 8 partially, 17 not at all (§1). What
   does not exist is any way for a stranger to sign up, be billed, or be cut off. §7 and §8
   close every row of the scorecard; §4–§6 are what lets anybody pay for it.

2. **Every commercial primitive is already built, and the audit closed the last gap.** A
   database per tenant; a licence per module per company with the three-state toggle
   (`company_modules.enabled` NULL / true / false); a profile that presets licences and seeders
   at provision; a platform panel with no tenant in scope; and — since 2026-10-26, finding 12 —
   `User::canAccessTenant()` refuses a company whose `status` is not 1, so suspension is one
   column write. What is missing is a *reason* the licence set was chosen (a plan), a number
   (active employees), and a job that connects the two to an invoice.

3. **The operator is a tenant.** The company that built this runs its payroll and books in it.
   So a subscription invoice is an ordinary invoice from that tenant to a contact; recurring
   invoices already carry a `quantity` per line and are raised as drafts on the 1st at 03:00
   (`RecurringInvoiceService::generateFor()`); dunning already exists and is per company
   (`SendOverdueReminders`); the customer statement already goes out by email
   (`CustomerStatementIssued`). Billing is a job that sets a quantity, not a payment module.

4. **Payroll requires only `employees`.** Checked across every `module.php`: `payroll` →
   `employees`; `invoicing`, `inventory`, `personal_finance` → `accounting`; `expenses`,
   `advances` → `employees` + `payroll`; `timesheets` → `employees` + `projects`; `billing` →
   `employees` + `payroll` + `invoicing`; `quotations` → `invoicing`; `campaigns` → `crm`. An
   HR-and-payroll tier with no ledger is licensable as the registry stands, and the ledger is a
   tier above it rather than a prerequisite.

## 1. What Operentra sells, and how much of it is here

Counted against their feature page, feature by feature, verified against the schema and the
Filament resources rather than the README — which is behind the code in places: it still says
the API is unversioned, and `/api/v1` landed 2026-10-26 (`ApiVersioningTest`).

| Group | Theirs | Full here | Partial | Missing here → closed by |
|---|---|---|---|---|
| Organization | 9 | 7 | 0 | Branches → §7.9; pay grades → §13 |
| Employee lifecycle | 9 | 8 | 1 | Contracts — `employee_settings` versions the package by date; no contract document or renewal date → §13 |
| Time & attendance | 12 | 8 | 1 | GPS attendance → §7.1 + §7.9; field visits → §8.5; biometric devices → §8.7. Self clock-in partial → §7.1 |
| Payroll | 10 | 9 | 0 | Cheque printing → §8.6 |
| Documents | 10 | 6 | 3 | QR verification (two features) → §7.5. Letters partial → §7.2 |
| Employee portal | 11 | 8 | 1 | Announcements → §7.6; badges → §8.4. The "portal" is the same panel under the Employee role, plus the 15-endpoint API and the shipped mobile client |
| Recruitment | 10 | 7 | 1 | Careers page → §7.4; offer letter → §7.2; hire → onboarding → §7.3 |
| Security | 10 | 9 | 1 | Sign-in log → §7.7 |
| Performance | 10 | 4 | 1 | Increment records → §7.10; merit-evidence ledger → §8.1; recurring tasks → §8.2; daily reports with AI verdicts → §8.3; badges → §8.4 |
| Reporting | 10 | 10 | 0 | — and scheduled delivery, saved views and one-click export are ahead of theirs |

Where the same feature is deeper here: tax is the FBR salaried slab per fiscal year with the annual
projection and the FBR file, not a generic calculator; disbursement is the Standard Chartered
iPayments file against the SBP bank directory; the payslip goes out over WhatsApp and carries an
acknowledge/reject thread; leave has approval chains, carry-forward, the sandwich rule and
comp-off; EOBI returns and the provident-fund and gratuity provisions are pay components.

What exists here with no counterpart on their list: the double-entry ledger with trial balance,
P&L, balance sheet, cash flow, bank reconciliation, fixed assets, budgets and multi-currency;
§153 withholding, tax schedules, the FBR digital-invoicing groundwork, the corporate and
sole-proprietor return packs; invoicing with aged AR/AP, quotations, recurring invoices and
credit notes; CRM with pipelines and forecasts; campaigns; support tickets with SLAs; inventory;
client billing for staffing firms; timesheets with project margin and unbilled WIP; the six
construction modules; the Claude command bar and receipt reader; custom fields, saved views and
the command palette.

Their pricing, for §3 and §12: Starter $3, Growth $6, Enterprise $9 per active employee per
month; a ten-seat minimum; annual at a sixth off; a 14-day trial with no card — Growth on the
pricing page, but a workspace opened 2026-10-29 started on Enterprise; only active employees
billed; full export available for 90 days after cancellation.

### 1.1 What the signed-in product adds to their feature page

Walked through 2026-10-29 in a trial workspace: every admin and portal screen (186 routes, read
from the app's own router), then again after entering six employees, a month's attendance,
leave, a loan, an expense claim, an appraisal cycle, a separation and an October payroll. The
feature page undersells the product; these exist behind the login and on neither list above.
The full feature-by-feature check is `docs/operentra-gap-analysis.md`; §14 schedules it.

| Area | Theirs | Here |
|---|---|---|
| Integrations | Self-serve API keys with scopes and a 7-day usage count; signed webhooks per event type | Sanctum tokens issued at login only; no webhooks |
| Bank file | A file designer per company account: header, row and trailer templates over `{{iban}}`, `{{amountMinor}}` and twenty other variables, date format, line ending | One format, Standard Chartered iPayments |
| Verification | Postal address check with a code, portal locked 30 days after the letter is requested; SMS codes for the mobile and the emergency contact; a mandatory-document list with an HR review queue; photo approval | Profile change requests only |
| Brand Studio | ID cards with a serial, a two-year expiry and a QR validity check, refused without a photo; visiting-card orders with a print queue; email signatures with set-up steps for five mail apps | None |
| Movements | Transfers and promotions as records; an increment issues its own letter | Dated `employee_settings` |
| Payroll | Contribution funds paid to their own account through the bank file, with a PF ledger per employee; salary-history CSV import; bonus and adjustment screens; approved expense claims reimbursed in the next run; loan types with schedules, interest, pause and resume | PF is a pay component; advances have one type |
| Leave | Encashment outside separation; cyclic accrual (X days per Y worked, for residential staff), negative balance, minimum service, a document after N days; the statutory set seeded per country (Hajj after 60 months' service, Umrah, maternity) | Encashment in the final settlement only |
| Lifecycle | Onboarding: a 13-task checklist per hire whose tasks run, link out, or close themselves from the records; separation: a clearance list fed by assets, loans and PF, with a live settlement preview (encashment at basic ÷ 30, gratuity from five years) | Checklist templates and the final settlement (`Lifecycle`) |
| Contracts | Draft → active, accepted by the employee in the portal, witnesses, signed-copy upload, IP disclosure; templates in five languages | None (§13) |
| Performance | A composite of quality and volume fed by attendance, tasks, daily reports and, where linked, GitHub reviews and WHMCS tickets; the model grades each daily report and says what it could not verify | §8.1–§8.4, being built |
| Notifications | About seventy email templates in fourteen groups, each with a visual editor, five languages and a test send; per-category in-app switches; email and SMS logs | `EmailTemplate` |
| Privacy | Subject-access export and erasure per employee; "Back up now", keeping the last fourteen snapshots | Company export (Phase 4); backups server-side |
| Commercial | Seats held above headcount and billed at the higher; offer codes; AppSumo lifetime deals; an affiliate programme; an operator console with email journeys to tenants and backup schedules | §3–§6 |

What the same data showed about their arithmetic, which is what §2 sells against:

- **A run before month-end charges the unmarked days.** Processed on the 29th, every employee
  lost the 29th–31st at the calendar-day rate (450,000 × 3/31 = 43,548) while the entry showed
  0 absent days, and their pre-payroll check reported no findings.
- **Approved overtime never reached the run.** Three approved hours, 0 on the entry.
- **"Gross" leaves out bonuses, adjustments and reimbursements**, so net plus deductions came
  to 37,300 above it.
- **Four lates cost a day's pay**, by a lates-per-absence setting.
- **A Thursday–Friday leave counted four days**, the weekend after it included.
- **A line manager on the employee role cannot approve a report's expense claim**; the approval
  is a permission, not the reporting line. HR approves without it.

## 2. Decision: what this is sold as

**Pakistan HR, payroll and books, priced per active employee, in PKR, with nothing missing from
the comparison.** The parity list (§8) is built so that a buyer with both feature pages open finds
no empty row on ours. It is not what the product is sold *on*. What it is sold on is what a
finance manager in Lahore spends the last week of the month doing: the slab change in every
Finance Act, the bank file the bank will accept, the EOBI and FBR filings, and a trial balance
that agrees with payroll — every one of which is already here and already in production for one
company.

Three consequences:

- **The HR tier competes on price; the payroll tier on correctness.** Starter has to be cheap
  because it is a commodity. Growth can carry a premium because the alternative is an accountant
  re-keying payslips into a ledger.
- **The books tier has no competitor on their shelf.** Nobody selling HRM bundles double-entry
  accounting, and nobody selling accounting in Pakistan bundles payroll that posts itself.
- **The code is public under MIT, so the product is the hosting, the Finance-Act updates, the
  bank-file maintenance and the support.** That is a fine business. It also makes the history
  scrub in `docs/open-source-release-checklist.md` §1.6 overdue remediation rather than a launch
  prerequisite; it is listed under §9 for that reason.

## 3. Tiers are licence presets, orthogonal to profiles

A **profile** says what kind of business this is: what to seed and what to recommend
(`config/company_profiles.php`). A **plan** says what the customer is paying for: which modules
may be licensed. They are different axes and must stay so — a software house on Starter and a
restaurant on Starter are the same plan and different profiles.

```php
// config/plans.php — module keys exactly as the manifests name them; prices per active employee per month
'starter'  => ['label' => 'HR',
               'modules' => ['employees', 'leave', 'attendance', 'lifecycle', 'mpr'],
               'prices'  => ['PKR' => 400, 'USD' => 3, 'EUR' => 3]],
'growth'   => ['label' => 'HR and payroll',
               'modules' => /* starter + */ ['payroll', 'advances', 'expenses', 'recruitment',
                                             'performance', 'projects', 'timesheets', 'devices'],
               'prices'  => ['PKR' => 800, 'USD' => 6, 'EUR' => 6]],
'business' => ['label' => 'HR, payroll and books',
               'modules' => /* growth + */  ['accounting', 'invoicing', 'quotations', 'inventory',
                                             'crm', 'campaigns', 'support', 'billing'],
               'prices'  => ['PKR' => 1400, 'USD' => 10, 'EUR' => 10],
               'custom_domain' => true],
'minimum_seats' => 10,
'trial'         => ['plan' => 'growth', 'days' => 14],
// add-ons, flat per company per month, Business only because of what they require:
'add_ons' => [
    'construction'     => ['prices' => ['PKR' => 15000, 'USD' => 100, 'EUR' => 100]],   // + its four children
    'personal_finance' => ['prices' => ['PKR' => 1000,  'USD' => 8,   'EUR' => 8]],     // a personal account has no seats
],
```

Where the §8 items land: the merit ledger, tasks, daily reports and badges are `performance`
(Growth); field visits are `crm` (Business); cheques are `accounting` (Business); the device
endpoint is a new `devices` module requiring `attendance` (Growth — Operentra's Starter excludes
biometrics too); locations are Core (every plan); a custom domain is a plan flag, not a module,
because it licenses nothing in the panel.

The numbers are §3.1's. `Plans::price($plan, $currency)` is the one reader.

**Two invariants, both already asserted for profiles and extended to plans by the same kind of
test.** `CompanyProfileTest` asserts a profile's module list is closed under `requires` and that
every non-core module appears in at least one profile. Plans get both: every plan closed under
`requires` (checked above by hand — `timesheets` needs `projects`, in Growth; `devices` needs
`attendance`, in Starter; `quotations` needs `invoicing`, `campaigns` needs `crm`, `billing`
needs all three of its parents, all in Business), and every module sold by at least one plan or
add-on, so there is no module nobody can buy.

**What a company is licensed = profile ∩ plan, at provision.** `CompanyProvisioner::provision()`
already hands `CompanyProfiles::modules($profile)` to `Modules::seedDefaults()`; the one change
is intersecting it with `Plans::modules($plan)` first. The profile keeps saying "a restaurant
should have inventory"; the plan says "not on Starter"; the Modules page shows the module as
*recommended — upgrade to license it*, and `CompanyProfiles::recommends()` already answers the
first half of that sentence.

**An upgrade is `seedDefaults` again with the union, never the difference.** Changing `plan`
re-runs the preset with `existing ∪ (profile ∩ newPlan)`. The NULL-means-first-grant rule on
`enabled` switches the new modules on without the customer visiting the Modules page, which is
what they paid for. Self-serve downgrade is not offered at launch (§12); when support downgrades
a company in the platform panel, licences are revoked exactly as a super admin revokes one
today, and the data stays, which is what `licensed = false` has always meant.

**The super admin's override survives unchanged.** `CompanyForm::licensableModules()` on the
platform panel still grants anything to anyone — a plan is a preset, not a lock, in exactly the
sense `docs/company-profiles-plan.md` §1 gives the phrase. A custom deal is a licence grant plus
a custom price on the agreement (§6), not a fourth plan.

*Rejected:* a `plan` key on each `module.php`. It puts price in the manifest, which is the
module's description of itself, and makes "which plan has X" a question answered by grepping 28
files. The plan owns its list.

*Rejected:* per-module pricing. It is what the licence system can express, and it is the wrong
product: nobody wants to price `advances` separately, and a menu of 27 prices is a menu nobody
finishes reading.

*Rejected:* making the plan a profile. §1 of the profiles plan spent a section separating the
irreversible axis from the commercial one; a plan is a third axis, commercial and reversible, and
collapsing it into either of the other two repeats the mistake that document undid.

### 3.1 Prices and the minimum

Three currencies, three lists, one file. **Every figure here is a proposal that lives in
`config/plans.php`; changing a price is editing a line, and git is the price history.**

| Plan | PKR / employee / month | USD | EUR | Floor at the minimum (10 seats) |
|---|---|---|---|---|
| Starter | 400 | 3 | 3 | PKR 4,000 · $30 · €30 |
| Growth | 800 | 6 | 6 | PKR 8,000 · $60 · €60 |
| Business | 1,400 | 10 | 10 | PKR 14,000 · $100 · €100 |
| Construction add-on | 15,000 flat | 100 flat | 100 flat | per company per month |
| Personal finance add-on | 1,000 flat | 8 flat | 8 flat | per account per month; a personal account has no seats |

How the numbers were chosen, so the next person can re-choose them:

- **USD sits on Operentra for Starter and Growth and a dollar above their Enterprise for
  Business.** $3 and $6 are their list and the comparison a buyer will make; $10 against their
  $9 is the ledger, which their Enterprise does not carry. EUR is the same figure as USD — one
  less number to maintain, and the few-percent difference is noise against a quarterly invoice.
- **PKR is a list price, not a conversion.** PKR 400 is roughly half what $3 converts to at the
  rate, and that is deliberate: a purchasing-power list for the home market, reviewed once a
  year on the same calendar as the salary slabs — the Finance Act — and **never indexed to the
  exchange rate**. A rupee invoice that moves with the dollar is a support call every month.
- **Ten seats, every plan, every currency, because the floor prices the account, not the
  seat.** A three-person firm pays as ten; that is what a minimum is for, and it keeps the
  smallest invoice worth the minute a person spends issuing it (§6). Five is the lever if trials
  from very small firms stall, and it is one number in the file.
- **Flat add-ons** because construction is job-centric and personal finance has no employees —
  a per-seat price on either would be a price on the wrong thing.
- **Tax is excluded everywhere.** The invoice carries whatever the operator's accountant sets
  on `tax_rates` for that customer — provincial sales tax on services for a Pakistani customer,
  the export treatment for a foreign one. The plan prices nothing with tax in it.

**Currency is fixed at subscription and decided by the billing address.** A Pakistani address
is billed in PKR; any other picks USD or EUR once. Changing currency afterwards is a new
agreement through support, because an agreement's currency is the invoice's currency and the
ledger has already posted the old ones. `companies.billing_currency` (§4) records the choice.

**USD and EUR customers are billed quarterly, in advance.** A SWIFT transfer costs the payer
twenty to forty dollars, which on a $30 invoice is absurd and on a $90 one still insulting;
quarterly is the smallest cadence a bank transfer does not dominate, and it stays until a card
gateway (§13) exists. PKR customers pay monthly by IBFT, where the transfer is free. Two small
additions to a model that today assumes monthly in the base currency: `recurring_invoices`
gains `every_months` (default 1; `coversPeriod()` adds a modulo on the months since
`starts_on`) and `currency_code` (nullable — the base), copied onto each raised draft, which
already carries `currency_code` and `exchange_rate`. `USD` joins `CurrencySeeder`, which seeds
only PKR and EUR today.

**The meter sets quantity; it never touches the price.** `unit_price` on the agreement is what
the customer was promised at subscription. A list-price change in `config/plans.php` applies to
new agreements at once and to existing ones only when a person edits the agreement after
notice. `quantity = max(seats, minimum_seats) × every_months`, and the line description says so:
"12 active employees × 3 months, counted 2027-01-01".

**The books need nothing new.** A USD invoice posts at its invoice-date `exchange_rate`; open
receivables are restated through `CurrencyRevaluation`, which already exists for exactly this;
the bank's conversion on receipt lands as the FX gain or loss the ledger already books. The
operator needs a foreign-currency account to receive into, which is a bank visit, not code.

## 4. The commercial record on `companies`

Landlord columns, all nullable, so every existing company — the operator's own included — is
untouched and means "licensed by hand, billed by nobody", which is exactly what it is today:

```
companies   + plan                 string, nullable      a key in config/plans.php
            + trial_ends_at        datetime, nullable    set at signup; cleared on subscribing
            + billing_contact_id   unsignedBigInteger, nullable, NO foreign key   (see below)
            + seats                unsignedInteger, nullable
            + seats_counted_at     datetime, nullable
            + suspended_reason     string, nullable      trial_expired | overdue | null = by hand
            + billing_currency     char(3), nullable     PKR | USD | EUR, fixed at subscription  (§3.1)
            + domain               string, nullable, unique, lowercase               (§8.8)
```

`billing_contact_id` is the id of a `contacts` row **in the operator tenant's database**. It
cannot be a foreign key because the row lives in another schema. It is the one place in the
landlord that points into a tenant; the column comment says so, and §6 is its only reader.

`seats` / `seats_counted_at` are the latest count only. **The invoice is the record of what was
billed**, as it is for everything else in this application: the line description carries the
count and the date, and a dispute is answered by the invoice, not by a seats table.

`suspended_reason` exists so that `billing:enforce` (§6) lifts only the suspensions it imposed. A
company a super admin suspended by hand has a null reason and stays suspended until a person
decides otherwise. `status` keeps its two values and its one reader.

*Rejected:* Laravel Cashier, Stripe, Paddle. Stripe does not onboard sellers in Pakistan; Paddle
and the other merchants of record need their seller eligibility verified case by case; and every
one of them adds a subscription model beside the invoicing module that already exists, with the
money landing outside the ledger that already exists. A card gateway is §13 until a customer
asks to pay by card.

## 5. Self-serve signup

Today a company is created in the platform panel by a super admin, and
`CompanyRegistrationAccessTest` pins that a company *administrator* cannot do it. Both stay true.
Signup is a third door: an **unauthenticated** visitor creating their **first** company. It lives
in this codebase because it calls `CompanyProvisioner`, whatever the marketing site
(`docs/company-website-plan.md`) turns out to be.

```
GET  /signup                      name, email, password, company name, profile — CompanyProfiles::optionsForType('business')
POST /signup                      creates the User, unverified; sends the verification mail
GET  /signup/verify/{id}/{hash}   Laravel's signed verification link; on success dispatches ProvisionCompany
GET  /signup/ready                "preparing your workspace"; polls itself; redirects to /admin/{slug}
```

**Nothing is provisioned until a mailbox is proven.** Creating a database and migrating two
hundred tables for every bot that finds the form is the failure to design against, and verifying
first removes it without a captcha. `users` gains `email_verified_at` (the landlord table does
not have it today) and `User` gains `MustVerifyEmail`. The admin panel does **not** get
`->emailVerification()`: every user who exists today was created by a person and must not be
locked out on deploy. Only the signup door checks it, in its own controller.

**An existing email is refused, not attached.** The audit's critical finding was
`CompanyInvitations::invite()` attaching an existing account to a company with no consent. Signup
must not reinvent it: an email that already has a user gets "sign in instead; to add a company
to your account, write to support", and nothing else happens. A second company for an existing
customer is a platform-panel act at launch.

**Provisioning is a queued job, `NotTenantAware`.** It takes seconds to a minute — database,
migrations, seeders, roles — which is too long for an Octane worker to hold a request open.
`RefuseTenantlessTenantAwareJobs` refuses any tenant-aware job queued with no tenant, so the job
is marked `NotTenantAware` explicitly: it serves the landlord, not a company. `TenantAwareQueueTest`
gets one more shape. The job calls `provision(name, creator: $user, type: business, profile:
$profile)` and then writes `plan = Plans::trialPlan()` and `trial_ends_at = now + trial_days`;
the provisioner stays unaware of plans beyond the intersection in §3.

**Spam gates on the POST:** Laravel's throttle per IP, a honeypot field, and the silent-success
rule `LeadCaptureController` already follows — a throttled submission looks identical to a
successful one.

What the new customer sees afterwards is the existing product: the Administrator role, the
Modules page with their plan's modules licensed and switched on, the profile's other modules shown
as upgrades, and the dashboard. No demo data (§12).

## 6. Metering and invoicing through the operator's own books

The operator company is named once: `config/saas.php` → `operator_company`, a slug from env.
Everything in this section runs on the landlord, enumerates companies there, and steps into a
tenant with `$company->execute(fn () => …)` — the pattern `BackupTenants` establishes for a
landlord job that visits tenants without becoming one (it is deliberately not `TenantAware`).

**Subscribing.** A *Subscription* page in Core, company Administrator only. Landlord data, so not
a `SettingsSections` entry. It shows plan, seats, trial end and the operator's billing address;
its one action is *Subscribe*, taking a billing email and address — the address decides the
currency (§3.1): PKR for Pakistan, USD or EUR by choice elsewhere. That creates, in the operator
tenant, a `Contact` for the customer and a `RecurringInvoice` in that currency — monthly for
PKR, `every_months = 3` for USD and EUR — with one sale line, "Mizan {plan}, per active
employee", `unit_price = Plans::price($plan, $currency)`, `quantity = max(seats, minimum_seats)
× every_months` — writes `billing_contact_id` and `billing_currency`, and clears
`trial_ends_at`. The customer pays by bank transfer against the invoice — IBFT in Pakistan,
SWIFT elsewhere — which is how businesses pay for software when nobody has given them a card
form; the operator's bank details are already on its invoice template.

**`billing:meter`, monthly on the 1st at 02:00, before the 03:00 raise.** For every company with
a `billing_contact_id`: step into its tenant, count `employees` where `is_active`, write
`seats` / `seats_counted_at`; step into the operator's tenant and set the agreement line's
`quantity` to `max(seats, minimum_seats) × every_months` with the count and date in the
description — and nothing else on the line: the price is the customer's, §3.1.
`RecurringInvoiceService::generateFor()` raises the draft an hour later as it does for every other
agreement. One active sale agreement per billing contact is the convention; the meter refuses
and reports a contact with zero or two rather than guessing.

**`billing:issue`, monthly on the 1st at 04:00, after the raise.** The invoicing module's rule is
that a draft reaches the ledger when a person issues it "after looking at it", and that rule
stays for every company and every agreement but one kind: an agreement with `auto_issue = true`,
a new boolean on `recurring_invoices`, default false, set by the Subscribe action and by nothing
else. The job steps into the operator tenant, finds that morning's drafts raised from
`auto_issue` agreements, and calls `InvoiceService::issue()` on each — the same method a
person's click calls, so whatever issuing does for a person (the number, the posting, the FBR
submission where configured) it does here — then hands the invoice to `InvoiceDeliveryService`,
which emails the PDF to the billing contact as it does for any issued invoice.

Three guards, because an invoice sent unseen is a customer's first sight of a mistake:

1. **A count that jumped is held, not issued.** If `seats` moved by more than
   `billing.issue_hold_ratio` (default a half) against the previous count, the draft stays a
   draft and the operator is notified with both numbers. A hiring spree is real sometimes; a
   folding bug is real sometimes; a person can tell in a minute and nobody receives a wrong
   invoice.
2. **A company suspended by hand is not invoiced.** `suspended_reason = null` with `status = 0`
   means a person decided something about this customer; the draft is held for them. A company
   suspended for overdue payment *is* invoiced — stopping the invoices is how a debt stops
   growing on paper while it keeps growing in fact.
3. **One failure does not stop the rest.** `issue()` throwing for one draft — a missing tax
   rate, a closed fiscal year — is logged and reported, the job continues, and exits non-zero:
   `BackupTenants`' rule, for the same reason.

`RecurringInvoiceService` itself does not change: it raises drafts for everyone, as now, and
`auto_issue` is read by one job in one place.

Only active employees count, as Operentra does it, and `is_active` is the flag every payroll
query already reads. No daily proration: the count on the 1st is the month's count. `ponytail:`
pro-rata seat changes if a customer with a hiring spike asks.

**`billing:enforce`, daily.** Three rules, each one query:

1. `trial_ends_at` past by more than `grace_days` and no `billing_contact_id` → `status = 0`,
   `suspended_reason = trial_expired`, one email.
2. `billing_contact_id` set and, in the operator tenant, an issued invoice to that contact overdue
   by more than `overdue_days` → `status = 0`, `suspended_reason = overdue`, one email. Dunning
   before that point is `SendOverdueReminders` with the operator tenant's toggle on, unchanged.
3. `suspended_reason` is `trial_expired` or `overdue` and the condition no longer holds — they
   subscribed, the invoice was paid → `status = 1`, reason cleared. A hand-set suspension is
   never lifted here.

A suspended company's users cannot sign in (`canAccessTenant`) and its API tokens fail the same
check. Its scheduled module jobs still run — the licence guard, not the status, gates those —
which is acceptable: a payroll month opening for a suspended company costs nothing. **Nothing is
ever dropped by a job.** `tenant:drop` exists and stays a command a person runs, after the export
in §9, after the 90 days.

## 7. The HR gaps to close first

Ranked by how much of each already exists. §7.1–§7.3 belong before launch; §7.4–§7.8 are the
first weeks after; §7.9 is the foundation three §8 items stand on, so it comes before them.

### 7.1 Self clock-in, with coordinates

`attendance_days` already carries `check_in_at`, `check_out_at`, `late_minutes`,
`overtime_minutes` and `SOURCE_SELF_SERVICE`; `attendance.late_grace_minutes` and the overtime
caps already derive the minutes; `AttendanceRegularization` is already the correction path. What
is missing is the button.

- A *Clock in* / *Clock out* header action on the employee's own attendance page, writing today's
  row with `source = self_service` and the browser's Geolocation reading into three new nullable
  columns: `latitude`, `longitude`, `accuracy_m`. Null when the browser refuses.
- `POST /api/v1/my-attendance/clock` for the shipped mobile client, through `ApiRoutes::group`,
  on the same stack as `/my-leave-requests`.
- **Geofencing is §7.9's last paragraph**, because a fence is a radius around a *location* and
  the location does not exist yet. Until it does, coordinates are recorded, not judged.

### 7.2 Offer and appointment letters

`IncomeCertificate`, `ExperienceLetter` and `CustomLetter` share a letterhead
(`CompanyLetterhead`) and a position: nothing stored, facts from the record, refuse to print what
the record cannot back. `OfferLetter` is a fourth sibling reading the `Offer` row — components,
salary, joining date — offered as an action on the application, downloadable, and attached to an
offer email to the candidate, which does not exist today ("Offer recorded" is a panel notification
only; the email is part of this item). `AppointmentLetter` is the same shape from the `Employee`
and its package, for the day they join.

### 7.3 Hire starts the onboarding checklist

`HireService::hire()` creates the employee and the package inside one `TenantTransaction`;
`ChecklistService::start($employee, $template)` already computes due dates from
`date_of_joining`. The join is a few lines at the end of `hire()`: if `lifecycle` is enabled for
this company and exactly one active `checklist_templates` row of the onboarding kind exists,
start it. Two templates or none → start nothing and say so in the "Hired" notification. No
default flag on the template until somebody has two.

### 7.4 Careers page

`vacancies` already holds title, department, designation, employment type, location, salary
range, openings and status; `applications` already take a resume path and a source. The page is
the lead-capture endpoint's shape, which is the status page's shape:

```
GET  /careers/{company}/{token}            open vacancies, on the company letterhead
GET  /careers/{company}/{token}/{vacancy}  the posting and the form
POST /careers/{company}/{token}/{vacancy}  applicant + application, stage = applied, source = careers
```

Two gates — `recruitment.careers.enabled` plus the token, a `SettingsSections` entry copied from
`LeadCaptureSettingsSection` — 404 for both failures, silent throttle, a fixed field list, a
resume upload bounded by size and type. **It never advances a stage**: the CRM plan's rule, at the
door where breaking it is most tempting.

### 7.5 QR verification on letters and payslips

The letter services store nothing, deliberately, so verification cannot look up a stored copy. It
recomputes. The printed facts — employee code, dates, designation; for a payslip, month and net —
are hashed with the app key; the hash travels in the QR as part of a URL
(`/verify/{company}/{token}/{kind}/{id}/{hash}`); the verify page re-derives the hash from the
live record and answers *matches* / *does not match* / *no such record*, showing only the facts
that were printed. `Invoice::fbrQrDataUri()` already renders a QR as a data URI for both PDF
engines; same helper. Same two gates as §7.4 (`documents.verification.enabled`).

### 7.6 Announcements

One tenant table — `announcements (title, body, pinned, audience, published_at, expires_at)` —
owned by `employees`, a resource, a dashboard widget for the current ones, and on publish the
existing `database` + `mail` + `broadcast` channels to the audience. `audience` is `all`, a
department name (departments are an option list, not a table), or — after §7.9 — a location;
once §8.9 lands, an attribute rule, so "every manager in Karachi" is one announcement.

### 7.7 Sign-in log

No table. Listeners on `Illuminate\Auth\Events\Login`, `Logout` and `Failed` write `activity()`
rows on the landlord log with IP and user agent; `PlatformActivityLogResource` already lists
landlord activity, and impersonation already logs there. Failed attempts are logged against the
email rather than a user, so an enumeration attempt is visible as one.

### 7.8 A ZKTeco preset for the attendance importer

`AttendanceImport` reads columns by header name and was designed as the device integration point
(`SOURCE_DEVICE` exists; the class docblock explains why no vendor was named). A `--preset`
mapping the common ZKTeco attendance-log export onto `employee_id, date, check_in, check_out`,
with `source = device`, covers every office whose device is not on the network. §8.7 is the
device that is.

### 7.9 Branches: locations in Core, and the geofence they make possible

`docs/hrms-plan.md` §3 decided the holiday calendar stays company-wide "until a second site
exists in the schema", and wrote down the shape for when one does. This is that shape, built now
rather than on the first multi-site customer, because §7.1's geofence, §8.5's field visits and
§8.7's devices all want somewhere to stand.

```
locations    id, name, code, address, city, latitude, longitude, geofence_radius_m (nullable), is_active   (tenant, Core)
employees    + location_id   nullable FK
holidays     + location_id   nullable FK — null means company-wide, exactly as the plan foresaw
```

Core, by the same precedent that put `FiscalYear` and `Holiday` there: Employees, Attendance,
Leave and Recruitment all read it and none owns it. `vacancies.location` stays the free-text
column it is — a posting says "Lahore or remote" — and gains nothing; `ponytail:` a FK when a
report needs to join on it.

**`HolidayCalendar` learns one optional argument.** `isHoliday($date, ?int $locationId)`,
`between()` and `datesBetween()` the same: a holiday applies when its `location_id` is null or
equals the one asked about. `AttendanceCalendar`, the leave-day generator and `scaffold()` pass
the employee's location; a caller that passes nothing gets the company-wide set, which is every
existing call today. **The data-migration warning stands:** `leave_days` already generated under
the company-wide calendar are not recomputed — the never-after rule in `docs/hrms-plan.md` §4.1
— so a location's extra holiday changes the months after it is entered, not the ones before.

**The geofence.** `attendance.geofence_mode` is a company setting, `off | flag | refuse`, default
`off` — a pay-affecting switch ships off, as every one in the HRMS plan does. With a mode set and
the employee's location carrying a radius, a §7.1 punch outside it is either flagged
(`outside_geofence` on the row, visible in the register and the month summary) or refused with
the regularization request as the way in. Operentra's "only when they are on site" is `refuse`;
`flag` is the mode a company should start on, because a phone's first fix is often a hundred
metres out and a refused punch at 9:00 is a support call at 9:01.

Reports: the headcount and attendance pages gain a location filter; `HeadcountMovement` gains a
by-location breakdown. Announcements (§7.6) gain a location audience.

### 7.10 Increment record from the appraisal

Half built. `config/performance.php` carries `increment_scale` — a rating-to-percentage
*suggestion*, and its own comment says why it is config: "a rating is an opinion, a package is
approved". `ReviewCycleService` already reads it. What is missing is the record: an action on a
finalised review, *Propose increment*, that creates the next `employee_settings` version from the
first of next month with `basic_wage` scaled by the suggested (editable) percentage, **through
the same `EmployeeChangeRequest` approval path a package change takes today**, with
`review_id` stamped on the request so the appraisal report can show which proposals became
packages. The review never writes the package; an approver does. That is Operentra's "increment
records" and "increment recommendations" in one item, and it is a join, not a feature.

## 8. The rest of their list, built

Each item below is a product in its own right on their page. Here each is designed as the
smallest thing that is honestly the feature, on machinery that already exists, with the one rule
that keeps it from doing harm stated up front. The merit ledger comes first because three of the
others write to it.

### 8.1 The merit-evidence ledger

**One rule: the ledger is append-only, and nothing writes a rating from it.** A score traces back
to dated proof, which is the feature; the proof never becomes the score on its own, which is
`docs/hrms-plan.md` §4.5's rule and the comment on `increment_scale`.

```
merit_entries   id, employee_id, occurred_on, points (signed smallint), source_type, source_id (nullable morph),
                reason (string), recorded_by (nullable user — null is the system), reverses_entry_id (nullable),
                created_at                                                                  (tenant, performance)
```

No `updated_at`, no delete; `MeritEntry` throws on update like `activity_log` does. A correction
is a new entry of the opposite sign carrying `reverses_entry_id`, so "why was this reversed" has
an answer in the data. The balance for a period is a `SUM` over the rows — **computed, never
stored**, the ledger rule from accounting applied to points, because a stored total drifts and a
drifted merit total is an appraisal dispute.

Writers: badges (§8.4, credit), missed tasks (§8.2, debit), accepted daily-report verdicts
(§8.3, either), and — behind `performance.merit.late_mark_points`, default 0 — a late mark beyond
grace from attendance. Points per source are a company setting under `performance.merit.*`, so
one company's "perfect month is 10" and another's "is 50" are both right.

Readers: the review form shows the cycle period's ledger beside the rating fields as evidence
(`review_cycles.period_start`/`period_end` bound the query); the employee sees their own ledger in
the portal; a manager sees the downline's through `EmployeeAccess::scopeAccessibleEmployees`,
which is how every other employee-bearing screen is scoped. A *Merit Ledger* report page
registered through `ReportCatalogue::register('Performance', …)`.

### 8.2 Recurring tasks, and what a missed one costs

```
recurring_tasks    id, title, description, assignee_kind (employee | department | location | all | rule),
                   assignee_ref, assignee_rule (json, nullable — §8.9),
                   cadence (daily | weekly | monthly), due_time, due_weekday / due_day_of_month,
                   working_days_only (bool), grace_hours, penalty_points, penalty_amount (nullable), is_active
task_occurrences   id, recurring_task_id, employee_id, due_at, completed_at, completed_note,
                   missed_at, excused_by, excused_reason, merit_entry_id (nullable)
```

**`tasks:roll`, daily at 00:05.** For every active task and every employee it resolves to — a fixed
kind, or the employees a §8.9 rule matches that morning — create
today's occurrence (unique on task + employee + due date, so a re-run is a no-op), skipping a
non-working day when `working_days_only` — `WorkPatternResolver::isWorkingDay()` for the pattern
and `HolidayCalendar::isHoliday()` for the calendar, asked separately because the resolver's own
docblock says combining them is how a caller ends up asking half. Then mark yesterday's
uncompleted occurrences past `grace_hours` as missed, and write **one merit debit each** of
`penalty_points`. A manager *excuses* a miss — the excuse writes the reversing entry; the miss
stays on the row.

Employees complete tasks from a *My tasks* page and `GET/POST /api/v1/my-tasks`; managers see the
downline's board. The daily report (§8.3) is a recurring task of a reserved kind, so a missing
report is a missed task with no second mechanism.

**Money, off by default.** `penalty_amount` is a rupee figure a company may put on a task;
`performance.penalty_pay_component` names the deduction component it lands on, and is null
unless an administrator picks one. When both are set, `tasks:settle` on the last day of the month
sums the month's unexcused misses per employee into one `payslip_component_inputs` row on that
month's draft payslip, idempotent on (payslip, component), and only if the payslip is still a
draft — a locked payroll run is never touched. The HRMS plan's rule, kept: a company that
touches no setting is paid exactly as before, and `PayslipCalculationSeamTest` is the test that
says so.

### 8.3 Daily reports, and the verdict on them

```
daily_reports   id, employee_id, report_date (unique with employee), body (text), submitted_at,
                verdict (nullable: substantive | thin | off_topic), verdict_summary, verdict_questions (json),
                verdict_at, verdict_model, manager_decision (nullable: accepted | overridden), manager_note,
                merit_entry_id (nullable)
```

MPR stays what it is — a monthly, self-service report keyed on `user_id` with its comparison
export; the daily report is a different cadence with a different reader and lives in
`performance`, beside the ledger it feeds. Who must file one is a recurring task (§8.2) of the
reserved kind `daily_report`, assigned to a department, a location or everyone, so "missed" and
"penalty" are already defined and there is no second schedule.

**The verdict is one call to the model that already exists.** `App\Support\Ai\StructuredModel`
does exactly one thing — turn text into a fixed set of fields against a JSON schema — and
`Claude` implements it with the prompt split its docblock insists on: the stable half (`$system`:
the company's reporting rules, the employee's designation, yesterday's report for continuity) and
the volatile half (`$utterance`: today's body). A `TenantAware` job after submission asks for
`{verdict, summary, questions[]}` and writes the four `verdict_*` columns. `ai.enabled` off, or no
key, means no verdict and nothing else changes; `FakeStructuredModel` makes every test of this
deterministic and network-free.

**The model never writes the ledger.** A verdict is advisory. The manager's morning view lists
yesterday's reports with their verdicts; *accept* writes the merit entry the verdict implies
(`performance.merit.report_points` by verdict), *override* writes the manager's own. Until the
manager acts the employee sees that a report was filed, not what a model thought of it (§12).
Cost is one call per report, bounded by `ai.max_tokens`; the `retain_utterances_days` pattern the
command bar uses prunes the verdict reasoning, not the report.

### 8.4 Badges and rewards

```
badges            id, name, description, icon, points, rule (nullable json — §8.9; null is manual), is_active
employee_badges   id, badge_id, employee_id, awarded_on, awarded_by (nullable — null is a rule), reason, merit_entry_id
```

Two ways to earn one. **Manual:** an *Award badge* action on the employee page, permission
`BadgeAward`, a reason required. **By rule:** `rule` holds a rule built in §8.9's builder —
conditions over the metric registry, with a window — evaluated by `badges:award` monthly, once
per badge per employee per period. Three badges ship seeded with their rules already written —
*Perfect attendance this month*, *Thirty on-time reports*, *Five years of service* — so a company
starts with examples it can clone and change rather than a blank form.

Every award is a merit credit of the badge's `points`, a notification over the existing channels,
and a row the portal shows under *My badges* — earned ones with the date, and **locked** ones (an
active badge not yet earned, with its rule's description), which is the half of the feature that
makes somebody want the other half.

### 8.5 Field visits

```
field_visits   id, employee_id (the visitor), subject_type / subject_id (lead | opportunity | contact — the morph
               next_actions already uses), planned_at, checked_in_at, in_latitude, in_longitude, in_accuracy_m,
               checked_out_at, out_latitude, out_longitude, notes, outcome,
               confirmation_sent_to, confirmed_at, confirmed_name, next_action_id (nullable)       (tenant, crm)
```

A visit is a next action with a location and a witness. Check-in and check-out come from the
mobile client — `POST /api/v1/field-visits/{visit}/check-in` and `/check-out` with coordinates —
or from the panel on a phone, through the same Geolocation reading §7.1 uses. Nothing judges the
coordinates: contacts carry an `address`, not a point, and a distance to an address is a geocoder
and a dependency; the visit shows a map link and the reader judges. `ponytail:` geocode on save
when a company asks for a distance.

**The client's own confirmation.** On check-out a temporary signed link
(`URL::temporarySignedRoute`, a day) goes to the contact person by email, or by WhatsApp —
which means `WhatsAppSender` gains `sendText()` beside `sendDocument()`, in the interface and both
drivers (`TwilioWhatsAppSender`, `CloudApiWhatsAppSender`, and the log fake). The public page
`/visits/confirm/{visit}/{signature}` shows the date, the visitor's name and the company, takes a
name and one tap, and writes `confirmed_at` / `confirmed_name`. Two gates, as every public page
here: the signature and `crm.field_visits.enabled`. The confirmation proves presence to the
rep's manager; it is not a signature on anything and the page says so.

Reports: visits per rep per period with the confirmed share, and the unconfirmed list — the
number a sales manager actually wants. Both through `ReportCatalogue`.

### 8.6 Cheque printing

Payments already have the lifecycle this needs — `draft → approved → exported → paid` — and a
`payment_type` the bank file resolves. A cheque is a payment type the bank file leaves alone and
a printer picks up.

```
cheque_books    id, company_bank_account_id, prefix, first_no, last_no, next_no, is_active
cheques         id, cheque_book_id, company_bank_account_id, cheque_no (unique per account), payment_id (nullable),
                payee_name, amount, cheque_date, crossed (bool), status (printed | void | stopped | cleared),
                printed_at, printed_by, void_reason
cheque_layouts  id, company_bank_account_id (nullable — null is the default layout), page_width_mm, page_height_mm,
                fields (json: payee, amount_words, amount_figures, date, crossing — each {x, y, width, font})
```

The flow: a payment of type `cheque` is approved; *Print cheque* takes the next number from the
account's book, renders the PDF through the existing `Pdf` support with the layout's absolute
millimetre positions, writes the `cheques` row as `printed`, and the payment's `reference`
becomes the cheque number when it is released to `paid`. Void and reprint are two actions that
each write a row; a number is never reused. `BankPaymentFile` excludes `payment_type = cheque`,
so a mixed approval batch splits itself.

**Amount in words.** `AmountWords::parse()` turns "twelve lakh fifty thousand" into a number for
the command bar; cheques need the inverse, `AmountWords::write(float): string`, in the same
class, in the same lakh-and-crore idiom, ending "Only". Two directions of one table, tested
against each other.

**The layout designer is a form and a test print.** Fields are x/y/width per element; *Test
print* renders the field names at their positions on a blank page to hold against a real cheque.
Dompdf honours absolute positioning, so this prints on the fallback engine too — but the
per-engine override partials the payslip template carries exist for a reason, and the cheque
template gets the same treatment on day one rather than after the first misaligned batch.

Reconciliation: `BankReconciliationService::autoMatch()` matches on amount and date; a cleared
cheque's statement narration carries its number, so the matcher gains the number as a hint and
`cheques.status` flips to `cleared` when its line is matched. A *Cheque Register* report lists
printed, void, stopped and cleared with the payment and the journal entry behind each.

### 8.7 Biometric devices, live

**The device connects to us; we do not connect to it.** A ZKTeco on a customer's LAN has no
route from a cloud server, so "live polling" from here is a connection that cannot be opened.
What the devices ship with instead is a push mode — the ADMS / "cloud server" setting in the
device menu — where the device opens the connection, uploads every punch as it happens, and polls
for commands. That is live, it needs no port on the customer's side, and it is the integration.

```
attendance_devices   id, company_id, serial_no (unique), name, model, last_seen_at, is_active        (LANDLORD)
device_punches       id, serial_no, device_pin, punched_at, status_code, verify_code, raw, folded_at (tenant, devices)
employees            + device_pin   string, nullable, unique — the numeric user id the device knows them by
```

**`device_pin` is its own column, decided.** The device's user id is numeric and short;
`employees.employee_id` is a string like `EMP-001`, renumberable through
`EmployeeCodeRenumbering`, and printed on every document. Tying the two would make a device
enrolment a reason not to renumber, and a renumbering a reason every punch stops folding. The
PIN is entered once, on the employee, beside the device section's *unmapped* list that shows
which PINs are punching with nobody to belong to.

The table is landlord-side for the same reason the signup door is: the device identifies itself
by serial in the query string and sends no header, so the serial is how the tenant is found,
before any tenant is current — `ResolveLeadCaptureTenant`'s job, by serial instead of slug. A
company administrator registers a serial from a *Devices* settings section (a landlord write from
a tenant page, which `company_modules` already is), and sets `device_pin` on each employee.

Endpoints, public, in a `devices` module requiring `attendance`, mirroring what the device
expects of a push server: a handshake on `GET /iclock/cdata?SN=…&options=all`, punches on
`POST /iclock/cdata?SN=…&table=ATTLOG` as tab-separated `PIN TIME STATUS VERIFY …` lines, and
`GET /iclock/getrequest?SN=…` for commands (always none, answered `OK`). An unregistered serial
gets a 404 and nothing stored — an endpoint that acknowledges an unknown device teaches a scanner
it exists. Throttled per serial. Every accepted punch is one `device_punches` row, raw line
kept; `last_seen_at` is written on every call, and a device silent for a day raises a
notification to the administrator, since a quiet device looks exactly like a quiet office.

**Folding is the importer's job.** A `TenantAware` job per company, every few minutes, takes
unfolded punches, groups them by `device_pin` and date, maps the PIN to the employee, and hands
first-punch and last-punch to `AttendanceRecorder` as check-in and check-out with
`source = device` — the same path `AttendanceImport` walks, idempotent on (employee, date), so a
device that re-sends a day changes nothing. A PIN no employee carries is left unfolded and listed
on the Devices section as *unmapped*, which is how a new hire's enrolment is noticed.

For a device whose firmware has no push mode, the §7.8 preset remains, and `ponytail:` a
fifty-line agent on a PC in the office that polls port 4370 and posts the same lines to the same
endpoint is the follow-up — the server side does not change for it.

### 8.8 Custom domains per tenant

**What a custom domain changes is the host, the branding and the front door — never the path.**
Filament resolves the tenant from the `{tenant}` segment at boot (`->tenant(Company::class,
slugAttribute: 'slug')`) and `docs/modules-plan.md` §1 is explicit that nothing per-company can be
decided at boot. So `hr.acme.pk/admin/acme/…` is the URL, and the work is making that host
answer, with the right certificate and the right logo.

- **`companies.domain`** (§4), set from the Subscription page on a plan with `custom_domain`.
  The page shows the CNAME to create (`hr.acme.pk → app.<platform host>`), looks it up, and
  refuses to save until it resolves to us — a domain nobody controls must not be claimable.
- **The host is accepted** because Laravel's `TrustHosts` middleware gets the platform host plus
  every `companies.domain`, cached and flushed on save. A `ResolveCompanyFromHost` middleware on
  the panel then does three things: on a custom host, `/` and `/admin` redirect to
  `/admin/{slug}`; `/admin/{other-slug}` on that host is a 404 — a domain shows one company, the
  same "the URL names a company" boundary `ResolveCompanyFromRoute` enforces; and the login page,
  which has no tenant yet, learns which company it is from the host, so the `brandName` /
  `brandLogo` closures already on the panel show the customer's letterhead logo
  (`CompanyLetterhead::logoUrl()`) at the front door and not only inside.
- **TLS is issued on demand, not by hand.** A Caddy in front of nginx with `on_demand_tls` and an
  `ask` endpoint — `GET /domains/check?domain=…` answering 200 only when a company owns the
  domain — issues the certificate on the first request and renews it forever, and the `ask`
  check is what stops it issuing for any host that happens to point at us. Nothing per customer
  to run. The alternative, certbot with a `domains:sync` command regenerating the list and
  reloading nginx, works and is what `deploy/reverb/README.md` already does for one host; it is
  one more thing to break per customer, which is the reason to prefer the first. §12.
- Sessions and cookies are per host, so a user signs in on their domain and stays there; the
  mobile client uses tokens and is unaffected. Mail still goes out from the platform's domain —
  sending *as* the customer's domain is SPF and DKIM per customer, and §13.

### 8.9 A rule builder for badges and tasks

**A form over two registries, not a language.** The first draft's objection to a rule builder
was that the expression language it needs is a second product. It is, and this design does not
have one: a rule is a list of conditions, each a *field* from a fixed registry, an *operator* the
field's type allows, and a *value* typed by the field. There is nothing to parse, nothing a
person can inject, and the dropdown is the documentation. `ReportDefinition` is the precedent —
a JSON `state` sanitised against its dataset on write and on read, so a definition can never
hold a key the registry does not know — and the builder stores and checks its rules the same way.

```
rule    { "any": [ { "all": [ {field, operator, value, window?}, … ] }, … ] }
        — groups OR'd, conditions within a group AND'd, no deeper nesting
```

Two vocabularies, because badges and tasks ask different questions:

| Registry | Answers | Fields (each a small PHP class) | Used by |
|---|---|---|---|
| **Attributes** — who somebody is | from the `employees` row | `department`, `designation`, `location` (§7.9), `employment_type`, `manager`, `years_of_service`, `date_of_joining` | task assignment (§8.2), badge eligibility, announcement audience (§7.6) |
| **Metrics** — what happened | computed over a window | `attendance.present_ratio`, `attendance.late_marks`, `attendance.absent_days`, `reports.filed_ratio`, `reports.on_time_streak_days`, `tasks.missed`, `tasks.completed_ratio`, `merit.balance`, `leave.unplanned_days` | badge qualification |

Windows: `this_month`, `last_month`, `last_3_months`, `year_to_date`, `all_time`. Operators:
`=`, `≠`, `≥`, `≤`, `between`, `in` — the field's type decides which are offered, the way
`DatasetFilter` offers a date range to a date and a select to a code. Every metric class has one
method, `value(Employee $employee, Window $window): int|float`, reading the tables that already
exist; a new metric is a class and a registry line, and it appears in the dropdown.

**The preview is the test.** The form is a Filament Repeater — field, operator, value — with a
*Who matches today* action beside it that runs the rule against the company and lists the
employees it would select. A rule that will award a badge or assign a task to a hundred people
is seen before it does. `RuleEvaluator::matches(Employee, Rule, Carbon $asOf)` is the whole
runtime; `badges:award` and `tasks:roll` are its only callers, and the rule itself has no side
effects.

*Rejected:* an expression-language package. A dependency for what a Repeater and two registries
do, and it makes "which fields exist" a documentation problem rather than a dropdown.

*Rejected:* nesting beyond any-of-all. Two levels express everything their page describes and
everything a preview can still explain; a third is where a rule stops being readable by the
person who did not write it.

### 8.10 Multi-language

**What is true today.** The interface is English, in 3,792 hard-coded strings — 2,658 of them
`->label('…')`, about 200 navigation and model labels, 59 notification titles, 96 Blade views —
and 34 `__()` calls. Filament ships its own chrome in sixty-odd locales including `ur`, whose
lang file sets `direction => rtl`, so buttons, pagination and table controls flip to Urdu and
right-to-left the moment the locale changes; the application's own words do not. The only Urdu
in the product is on the way *in*: the command bar understands Urdu script and roman-Urdu
(`TransactionTypeAliasSeeder`) and `ai.voice.locales` lists `ur-PK`.

**Locale is a user preference with a company default.** Resolution, in order:
`users.preferences['locale']` — the per-user JSON store that already exists — then
`settings('company.locale')`, then `config('app.locale')`. A `SetLocale` middleware on both
panels; `Accept-Language` through `ApiRoutes::group` for the mobile client; every notification
sent with `->locale()` of its recipient, so one payslip issued to two people arrives in two
languages. `Carbon::setLocale()` follows, for month and day names.

**Urdu first, and the mechanism for any.** `lang/en.json` is the source, with the English
sentence as the key — Laravel's JSON translation, so a key missing from `ur.json` falls back to
the English sentence and never to a blank or a key name. `lang/ur.json` is the first translation;
Arabic — Filament has `ar`, also RTL — is the second if the Gulf market is wanted (§12). The
repository is the translation memory; a browser-side editor for it is §13.

**The sweep is mechanical, module by module, and a test is what makes it stick.** Every
`->label('X')` becomes `->label(__('X'))`; navigation, model and group labels become methods
returning `__()`; Blade literals become `{{ __('…') }}`; notification titles the same. The
rewrite is a script; the review is reading. Done one module at a time, like the physical move in
`docs/modules-plan.md` §1 — never as one commit. `TranslationCoverageTest` greps each swept
module for a literal `->label('` and fails on the first one, and lists every `en.json` key
absent from `ur.json` — so coverage cannot regress and the untranslated set is always one test
run away.

**Right-to-left.** Filament flips the layout from the lang file's `direction`. The custom theme
(`resources/css/filament/admin/theme.css`) and the report pages are audited for physical
properties — `margin-left`, `text-align: left`, `float` — and rewritten to logical ones
(`margin-inline-start`, `text-align: start`); Tailwind 4's logical utilities cover the templates.
The dashboard widgets, the account register and the comparison statements are the screens to
check by eye, because a running balance that reads right-to-left is a different number.

**Documents are a company decision, not a user one — per company, and per document kind.**
`settings('documents.locale')`, default `en`, is the company's document language;
`settings('documents.locale.{kind}')` overrides it for one kind, and a kind with no override
follows the company. Resolution is kind → company → `en`, and `Pdf::view()` resolves it once per
document from the kind the template declares. The kinds are the documents that leave the
company: `payslip`, `letter` (the income certificate, experience, offer, appointment and custom
letters together — one reader, one language), `invoice` (with quotations, credit notes and
statements), `cheque`, and `report` for a scheduled report delivery. Decided for the factory
case: Urdu payslips for the floor and English letters for the bank are two settings, not a
support call. The Company Settings section is one default select and one select per kind, each
offering *Follow company default* or a language, so the common case stays one choice.

A report somebody exports for themselves follows their own UI locale, not the document locale —
it is for the person exporting it, which is the line between a screen and a document. The
reader of a payslip is often a bank, of a letter a next employer, of an invoice the FBR, and
most of them read English, which is why `en` stays the default at every level. Urdu documents
render **on the Chromium engine only**: Dompdf does not shape
Arabic-script text, so joined letters come out as disjointed glyphs — the same engine gap the
per-engine payslip partials exist for. `Pdf::view()` therefore refuses the dompdf fallback for
an RTL document locale with a message that says why, rather than printing something a bank
would bounce. Noto Nastaliq Urdu and Noto Naskh Arabic are installed on the VPS for Chromium.
`AmountWords::write()` gains an `ur` table so a cheque or a payslip in Urdu spells its amount in
Urdu (§8.6); digits stay Western.

**What is not translated: user data.** Designations, departments, option lists, pay component
names, the chart of accounts — these are the company's own words in whatever language they
typed, and a company that wants Urdu designations types Urdu designations. Role names stay the
identifiers they are. Numbers stay in Western digits and PKR formatting stays as it is, which is
how Pakistani business writes them; Eastern Arabic numerals are §12.

## 9. Operating it as a product

What already works, and what is still a person's job:

| Concern | State |
|---|---|
| Deploy migrates every tenant | **Done** — `deploy/deploy.sh:56-57` runs `migrate --force` then `tenants:migrate`; module schedules survive `route:cache` since `fad4656a` |
| Backups per tenant, encrypted | **Done** — `BackupTenants`, `BACKUP_ARCHIVE_PASSWORD`; `BackupConfigurationCheck` watches the configuration |
| API versioned | **Done** — `/api/v1`, `ApiVersioningTest`, `docs/api/openapi.yaml`. The README still says otherwise and needs that sentence removed |
| Self-monitoring | **Done** — the Projects module's health checks watch the installation's own URLs |
| Customer data export | **Build** — `tenant:export {company}`: the tenant dump plus the tenant's files directory, zipped into the tenant's own storage behind a signed download link, runnable by the company Administrator from the Subscription page. This is the 90-day promise and the precondition for `tenant:drop` |
| Demo seeder guard | **Build** — `DatabaseSeeder` refuses outside local and testing (audit finding 14), now that a production host has a public form on it |
| TLS for customer domains | **Build** — the Caddy front from §8.8, or the certbot sync; one of the two, decided in §12 |
| Device endpoint exposure | **Build** — `/iclock/*` is the second unauthenticated writer on the host after signup; its throttle and 404 rule are in §8.7 and `SecurityHardeningTest` |
| Support | **Reuse** — the operator tenant's own `support` module takes customer tickets; its SLA reports are the ones to show a customer |
| Data residency | **Decide** — one VPS today (`deploy/`). Customers will ask where the database is; the answer needs to be a sentence on the pricing page, not a surprise |
| Terms, privacy policy, DPA | **Write** — not code; blocks selling to any company with a lawyer. The AI verdict (§8.3) and the device punches (§8.7) each need a sentence in the privacy policy |
| Public repo history | **Overdue** — `docs/open-source-release-checklist.md` §1.6; the real roster and pay figures are still in history. Not a SaaS blocker, but every month it stands more copies exist |

## 10. Phases

| Phase | Delivers | Depends on | Size |
|---|---|---|---|
| 0 | `config/plans.php`, the seven `companies` columns, a `Plans` support class, the profile ∩ plan intersection in the provisioner, the plan tests | — | S |
| 1 | Signup: the four routes, `email_verified_at` + `MustVerifyEmail`, `ProvisionCompany` job, spam gates | 0 | M |
| 2 | Subscription page, `billing:meter`, `billing:enforce`, `config/saas.php`, the operator-tenant contact and agreement; `every_months`, `currency_code` and `auto_issue` on the agreement, `billing:issue` with its three guards, USD in `CurrencySeeder` | 0 | M |
| 3 | §7.1 clock-in, §7.2 letters, §7.3 hire → checklist | — | S each |
| 4 | §9 export command and demo-seeder guard; README correction | — | S |
| 5 | §7.4 careers, §7.5 QR, §7.6 announcements, §7.7 sign-in log, §7.8 import preset, §7.10 increment record | — | S–M each |
| 6 | §7.9 locations, the calendar argument, the geofence | 3 | M |
| 7 | §8.1 merit ledger, then §8.2 tasks and `tasks:roll` / `tasks:settle` | — | M |
| 8 | §8.3 daily reports and verdicts; §8.4 badges | 7 | M + S |
| 9 | §8.6 cheques, `AmountWords::write()`, the layout designer, the register | — | M |
| 10 | §8.5 field visits, `WhatsAppSender::sendText()`, the confirmation page | 6 | M |
| 11 | §8.7 the `devices` module, the push endpoints, the folding job | 6 | M |
| 12 | §8.8 custom domains: the column, the host middleware, the TLS front | 2 | M, plus ops |
| 13 | §8.9 rule builder: the two registries, the evaluator, the Repeater form with preview, the three seeded badge rules; §8.2's rule assignee and §7.6's rule audience switch to it | 7, 8 | M |
| 14 | §8.10 multi-language: locale resolution, the module-by-module sweep with `TranslationCoverageTest`, `lang/ur.json`, the RTL audit, the document locale per company and per kind with its engine guard | — | L |
| 15 | §14.1 run approval, pre-flight and staleness; structures; bonus and adjustment records; salary-history import; bulk download, reminders, rounding, pay day | — | L |
| 16 | §14.1 bank-file designer; provident-fund ledger | 15 | M |
| 17 | §14.2 the employee record: fields and status, departments, designations and pay grades as rows, directory filters, import, bulk activate, probation, transfers, promotions, rehire | — | L |
| 18 | §14.3 expense lines and the two-step chain; advance types, applications, schedules, reports | — | M |
| 19 | §14.4 leave groups, eligibility, calendar, encashment; overtime requests, pattern fields and assignment, reminders, swaps; holiday types and import | 17 | L |
| 20 | §14.5 contracts, onboarding item kinds, separations and clearance, the login grace | 16, 17 | L |
| 21 | §14.6 competency templates and self review; interviews | — | M |
| 22 | §14.7 letter and document templates, event letters, letter requests, email coverage, notification preferences, the message log | 14 | M |
| 23 | §14.8 required documents, phone and address codes, the gate, subject access and erasure, the backup button | 17, 22 | M |
| 24 | §14.9 API keys, webhooks, user and role administration, the run buttons, plan change, held seats | — | M |
| 25 | §14.10 Brand Studio, sales report and scorecards, field-visit depth, announcements, assets, reports, company details | 6, 7 | M–L |

Phases 0–4 are launch. 3 and 4 have no dependency on 0–2 and can run alongside them. 5 is the
first weeks after launch; 6 before 10 and 11 because both stand on locations; 7 before 8 because
both of 8 write to the ledger. 13 comes after 8 because its presets are badges. 14 depends on
nothing and is the largest row by far, so it runs beside the others, one module per sitting, and
every module swept after it starts is written translated. 9 and 12 are independent of everything
but their own row. The order of 6–14 is a recommendation and the only hard edges are the ones in
the table. Every phase
ships behind nothing: a company with `plan = null` behaves exactly as today, which is what the
first test in §11 pins, and every pay-affecting switch — the geofence, the penalty component,
the late-mark points — ships off.

Phases 15–25 are §14: the depth behind the rows that 0–14 closed. 15 and 17 come first because the
payroll run and the employee record are the two screens a buyer works in during the first week.
The edges: 16 after 15 (the designer formats the run's file), 19 after 17 (leave groups attach
to the record), 20 after 16 and 17 (clearance reads the PF ledger; separation and probation live
on the record), 22 after 14 (a template carries a locale), 23 after 17 and 22 (the codes verify
the record's phone and address, and the address code is a letter), 25 after 6 and 7 (visits
stand on locations, scorecards on the merit ledger). The rest stand alone.

## 11. Tests

One feature test per concern, named for the sentence it asserts:

- `PlanTest` — every plan is closed under `requires`; every non-core module is sold by some plan
  or add-on; `starter ⊂ growth ⊂ business`; every plan and add-on prices every currency the file
  lists; a company with `plan = null` is licensed exactly as `CompanyProvisioningTest` already
  says.
- `PlanIntersectionTest` — a restaurant on Starter is licensed `profile ∩ starter`, is
  recommended `inventory`, and cannot switch it on; upgrading to Business licenses it without
  touching anything the company had switched off.
- `SignupTest` — the form creates an unverified user and provisions nothing; verification
  dispatches `ProvisionCompany`; the job is `NotTenantAware` (`TenantAwareQueueTest` gains the
  shape); an existing email is refused and attaches nothing (the audit's finding 1, at the new
  door); a throttled POST answers exactly like a successful one.
- `BillingMeterTest` — counts only `is_active` employees; applies `minimum_seats`; multiplies by
  `every_months`; writes the count and date into the line description; never rewrites
  `unit_price`; refuses a contact with zero or two agreements. A Pakistani address subscribes in
  PKR monthly and cannot pick USD; a foreign one picks USD or EUR and is quarterly.
- `BillingIssueTest` — only `auto_issue` drafts are issued; every other company's drafts are
  untouched; issuing goes through `InvoiceService::issue()` and the delivery goes out; a seat
  count that jumped past the ratio is held with a notification; a hand-suspended company's draft
  is held; an overdue-suspended company's is issued; one throwing draft is reported and the rest
  still issue; the Subscribe action sets the flag and nothing else can.
- `BillingEnforceTest` — the three rules of §6, and the one that matters most: a hand-suspended
  company is never reactivated by the job.
- `SecurityHardeningTest` gains: a suspended company's API token fails; the signup door cannot
  reach an existing company; an unregistered device serial gets 404 and stores nothing; a custom
  host shows one company and 404s the rest.
- `SelfClockInTest`, `OfferLetterTest`, `HireStartsOnboardingTest` — each pinning the one rule
  in its §7 item: coordinates recorded, not judged; a letter refuses with a blank letterhead;
  two templates start nothing.
- `CareersPageTest`, `DocumentVerificationTest`, `FieldVisitConfirmationTest` — two gates, 404
  for both failures; never advances a stage; never reveals more than was printed; a confirmation
  link works once and expires.
- `LocationCalendarTest` — a location holiday is a holiday for that location's employees and
  for nobody else; a caller passing no location gets the company-wide set; already-generated
  `leave_days` are untouched by a new location holiday.
- `GeofenceTest` — `off` records and judges nothing; `flag` records and flags; `refuse` refuses
  and the regularization path still works; no radius means no judgement in any mode.
- `MeritLedgerTest` — append-only (update throws); a reversal is a new row pointing at the old;
  the period balance is a sum; the review form shows the ledger and never pre-fills a rating.
- `RecurringTaskTest` — `tasks:roll` is idempotent; `working_days_only` skips weekends and
  holidays, asked separately; a miss writes one debit; an excuse writes one reversal; the
  settle job touches only a draft payslip, only when a component is configured, and
  `PayslipCalculationSeamTest` still passes with nothing configured.
- `DailyReportVerdictTest` — on `FakeStructuredModel`: a verdict is written to the four columns
  and nowhere else; `ai.enabled` off writes nothing; the ledger changes only on the manager's
  accept or override.
- `BadgeTest` — a manual award needs a reason and `BadgeAward`; a rule awards once per period;
  every award is one credit; locked badges list exactly the active unearned ones.
- `ChequeTest` — `AmountWords::write()` and `parse()` round-trip over a table of lakh/crore
  cases; a number is never reused across void and reprint; `BankPaymentFile` excludes cheque
  payments; a matched statement line clears the cheque.
- `DevicePushTest` — the handshake and a punch upload store raw rows under the right company;
  folding gives one attendance row per employee per day with `source = device`; a re-sent day
  changes nothing; an unmapped PIN is listed, not dropped.
- `CustomDomainTest` — a domain that does not resolve to us cannot be saved; the host is trusted
  after save; `/` on the host redirects to the company; the login page carries the company's logo.
- `RuleBuilderTest` — a rule holding a field the registry does not know is refused on write and
  dropped on read; each operator is offered only to the types that allow it; any-of-all evaluates
  as written over a seeded month; the preview lists exactly who `badges:award` would award; the
  three seeded rules match the employees they describe and nobody else.
- `LocaleResolutionTest` — user preference beats company default beats app locale; the API
  honours `Accept-Language`; two recipients of one notification get two languages.
- `TranslationCoverageTest` — no literal `->label('` survives in a swept module; every `en.json`
  key has a `ur.json` key or is listed by name.
- `RtlDocumentTest` — an RTL document locale on the Chromium engine renders; on the dompdf
  fallback it refuses with the message, never a PDF; `AmountWords::write()` spells a lakh-and-crore
  amount in Urdu and still round-trips in English.
- `DocumentLocaleTest` — a kind override wins over the company default; a kind without one
  follows the company; nothing set is `en`; a self-exported report follows the user's locale and
  ignores both; the engine guard reads the resolved locale of that document, not the company's.

## 12. Decisions still open

1. **Which plan a trial starts on.** Operentra's pricing page says it trials Growth; a workspace
   opened on 2026-10-29 started on Enterprise, its top tier (§1.1). Trialling Business instead shows
   the ledger, which is the thing nobody else has — at the cost of a customer who then chooses
   Growth losing screens they saw.
2. **Demo data in a trial.** The demo seeders exist. A trial with sample payslips and a trial
   balance shows the product; a trial with the customer's own first employee is the one they
   keep. Not seeded by default; a *load sample data* action is a small follow-up if trials stall.
3. **Self-serve downgrade.** Not offered at launch; support does it in the platform panel.
   Revisit on the first request.
4. **Where the marketing site lives.** `docs/company-website-plan.md` already asks whether a
   public site belongs in this codebase. Signup does; the pages around it need not.
5. **Data residency.** One sentence, decided before the pricing page is written.
6. **When the employee sees the verdict (§8.3).** After the manager acts, as written — or at
   once, which is faster feedback and also a model's opinion of a person delivered with nobody
   between. The first is the default until a company asks for the second.
7. **The penalty component (§8.2).** Off by default, as written. Whether the Growth tier's
   onboarding should even mention it, or leave it for a company that goes looking, is a product
   call.
8. **TLS front for custom domains (§8.8).** Caddy on demand, or certbot with a sync command.
   Caddy is recommended for the reason given there; it is also a new process on the VPS.
9. **Languages after Urdu (§8.10).** Arabic opens the Gulf; Sindhi and Pashto are
    Filament-less and would need the chrome translated too. None until Urdu has shipped and
    been read by a customer.
10. **Eastern Arabic numerals.** Western digits as written; a user preference if anybody asks,
    never on a document a bank reads.

## 13. What not to build

Deferred for a business reason, each with the trigger that reopens it:

- **A card gateway, Cashier, or any subscription model beside the invoicing module.** §4. The
  trigger is a customer who cannot pay by transfer.
- **Annual billing.** `every_months = 12` with the quantity fixed at subscription, at ten
  months' price for twelve — Operentra's sixth off. The cadence column from §3.1 makes it a
  price line, not a feature; it waits for the first customer who asks.
- **Daily seat proration.** The 1st-of-month count, until a customer's hiring pattern makes it
  unfair.
- **Automatic deletion of a suspended tenant.** A person runs `tenant:drop`, after the export.
- ~~**Pay grades and contract documents.**~~ Moved to §14.2 and §14.5 on 2026-10-29, when the
  signed-in walkthrough showed both as full screens rather than a form worth guessing at.
- **An expression language for rules.** §8.9 is a form over two registries; the moment a rule
  needs a formula, that is a metric class, not a parser.
- **The model writing the ledger on its own.** §8.3's rule, not a deferral.
- **Geocoding contact addresses for a distance check on visits.** A dependency for a number
  the reader can judge from a map link; the trigger is a company that wants the number.
- **The on-prem polling agent for devices without push.** §8.7's last paragraph; the server
  side is built for it, the agent waits for the first such device.
- **Sending mail as the customer's domain.** SPF and DKIM per customer; the trigger is a
  customer whose recipients reject platform-domain mail.
- **Machine-translating user data.** Designations, accounts and option lists are the company's
  words; a model rewriting them is a model editing the books. Never.
- **A browser-side translation editor.** `lang/ur.json` in the repository is the translation
  memory, reviewed like code; a Crowdin-shaped screen is a product for a team of translators
  this does not have.
- **The GitHub and WHMCS feeds, and the identity matching behind them.** Built for Operentra's
  own staff — a hosting company scoring its developers and support desk. The trigger is a
  customer whose work lives in either; `merit_entries.source_type` is where it would land.
- **AppSumo codes, coupons and an affiliate programme.** Sales channels, not features. The
  trigger is a decision to sell through one; a coupon is then a discount line on the
  subscription invoice (§6).
- **Marketing from the operator console** — email journeys, broadcasts and social posts to
  tenants. The trigger is more tenants than a person can write to.
- **Managing users, fingerprints, backups and commands on a device.** §8.7 answers every command
  poll with none, deliberately. The trigger is a customer for whom enrolling at the device
  itself is not enough.

## 14. Depth parity: what the signed-in walkthrough found

`docs/operentra-gap-analysis.md` checked the 199 features behind their login against the code:
38 here as fully, 107 narrower, 54 missing. §7 and §8 closed the rows of their *feature page*;
this section closes the depth, as Phases 15–25 (§10). The rule is §8's: the smallest thing that
is honestly the feature, on machinery already here, with the rule that keeps it from doing harm.
Four gaps are already designed elsewhere — a second language (§8.10), badges evaluated in bulk
(§8.9's evaluator), custom domains (§8.8), live devices (§8.7) — and four are deferred in §13.

### 14.1 The payroll run and the pay structure

**One rule: a run never charges a day nobody has marked without saying so.** Theirs did (§1.1).

- **An approved state.** `PayrollRun` is `open` → `locked`. Add `approved` between them, with its
  own permission beside `PayrollRunLock`; *send back* returns an approved run to open.
- **A pre-flight.** A `PayrollPreflight` service before payslips are raised: blockers (no
  package; bank-paid with no account) refuse the run, warnings (unmarked days in the period,
  listed by employee; a run before month-end) are shown and acknowledged.
- **Staleness.** The run keeps the latest change it read among attendance, leave, claims,
  advances and the payslip component inputs; its page warns when any input is newer.
- **Structures.** A salary-structure template — components as a share of gross or a fixed
  amount — that fills an employee's package from one gross figure. A form helper: the itemised
  package stays the payroll input.
- **Bonus and adjustment records.** Dated one-off lines per employee per month, written to the
  payslip component input store, not typed onto the payslip.
- **Salary-history import.** `CsvImporters::register('salary-history', …)`: past months as
  imported, locked payslips, previewed first, revertible by batch.
- Bulk payslip download (a ZIP, as the certificate bulk download already is), acknowledgement
  reminders (at most three, then silence), rounding and pay day in Company Settings.
- **A bank-file designer.** `IPaymentsFileWriter` becomes one seeded format of a
  `BankFileFormat` per company bank account — header, row and trailer templates over
  placeholders. A designed format is previewed against the real run before it can be downloaded.
- **A provident-fund ledger.** `provident_fund_entries`, append-only per employee — opening,
  contribution at lock from `StatutoryContributions`, profit, withdrawal — the merit ledger's
  shape (§8.1) for money. The final settlement reads it.

### 14.2 The employee record

**One rule: `is_active` keeps its meaning.** Every scope in the codebase reads it; a new
`status` (active, on leave, suspended, resigned, terminated, retired) sits beside it and derives
it, so nothing that filters on it changes.

- Father's name, NTN, nationality, marital status; an emergency contact with a name and a
  relation; current and permanent addresses as two structured blocks; a photo, changed through
  `EmployeeChangeRequest` like every other self-service edit.
- **Departments and designations become rows** — parent, code and head; level and pay grade —
  migrated from their option lists, with the ledger's department dimension reading the new
  table. **Pay grades** (§13 deferred them) are a min–max band on the designation that warns,
  never blocks, when a package falls outside it.
- Directory filters by department and status, and a grid layout.
- `CsvImporters::register('employees', …)` with the dry run the attendance importer has; a bulk
  *Activate* that creates and invites each employee's user and opens their leave year.
- Probation end and confirmation dates; a Probations list, overdue first, with *Confirm*.
- Transfers and promotions: `EmployeeJobHistory` already dates department and designation
  changes. Add location, two list pages over it, and *Record a transfer / promotion* actions
  that write the dated change. Rehire reopens a leaver and keeps the old spell in the history.

### 14.3 Expenses and advances

- **Claims get lines** — date, category, amount, receipt — and a draft state. Approval becomes
  the manager, then HR, on `LeaveApprovalChain`'s pattern. Reimbursement through payroll stays
  as it is. A category is the company's own list, each mapped to the transaction type it posts
  as, so the ledger does not move.
- **Advances get types** (maximum amount, maximum instalments, interest, active limit), an
  employee application with approval, an instalment schedule, pause and resume (skipped months
  exist), and the schedule, defaulters and disbursements reports.

### 14.4 Leave, attendance and holidays

**One rule: approved overtime reaches the payslip, or the request says why it did not.** Theirs
approved three hours and paid none (§1.1).

- **Leave groups**: bundles of leave types assigned to employees. The default group is every
  active type, which is today's behaviour.
- **Eligibility**: gender, minimum service, employment types, maximum consecutive days, a
  document after N days; a colour and a pay percent per type. Umrah and marriage leave seeded,
  and a service minimum on Hajj — seeded figures a company edits.
- A leave calendar (a month grid over `LeaveDay`); encashment outside separation as a request
  that debits the balance and adds a payslip input.
- **Overtime requests** with approval, feeding payroll; a break and a grace per work pattern; an
  assignment screen over `employee_work_patterns`, which is dated and already read by
  `WorkPatternResolver`; *mark all present* for a date; the import template as a download;
  missing-punch reminders (off by default); rest-day swaps resolved by the resolver.
- Holidays get a type, a recurring rollover, a calendar grid and a CSV importer.

### 14.5 Contracts and the employee lifecycle

- **Contracts** (§13 deferred them): `employment_contracts` — type, number, dates, gross,
  probation, draft / active / ended — rendered from a template, carrying the QR of
  `DocumentVerification`, accepted by the employee in the panel (time and IP, as a payslip
  acknowledgement is), with witnesses, an IP disclosure, and the signed scan as an
  `EmployeeDocument`. The expiring report reads it.
- **Onboarding** starts for every new employee, not only a Recruitment hire. Checklist items
  gain a kind — manual, link, auto-check — and auto-check items close from the records (user
  created, package in effect, contract active, asset issued). A tick-and-waive screen.
- **Separations** become a record — type, notice date, last working day — with a clearance
  list: auto items from assets, advances and the PF ledger (§14.1), manual items with waive.
  The settlement preview reads it, and the employee's login closes a configurable number of
  days after the last working day.

### 14.6 Appraisals and interviews

- Review templates with weighted competencies (summing to 100), rated per competency by the
  employee and the manager; the rating scale a company setting; *Change reviewer*; finalize with
  an increment as a percentage or an amount, and a promotion recommendation. Both still write
  nothing until approved — `docs/hrms-plan.md` §4.5.
- Interviews: *Schedule* on an application, interviewers as users, a feedback form for each,
  the outcome moving the stage.

### 14.7 Letters, templates and notifications

- **Letter templates** become company-editable records per kind with placeholders, rendered by
  the engines that render them now, with a locale once §8.10 lands. Letters issue themselves on
  the events they record — increment, promotion, transfer, confirmation — and employees request
  the rest through an HR queue.
- **Payslip and certificate templates** are a choice of layout and header and footer text, not
  free HTML, so the per-engine overrides keep working.
- Email templates cover every mail the app sends, with a preview and a test send; notification
  preferences per category per user; one `message_log` of every mail and WhatsApp message —
  channel, recipient, purpose, status.

### 14.8 Verification and privacy

**One rule: the gate never blocks for a check the company cannot perform.** If WhatsApp is not
configured, phone verification is off, whatever the setting says.

- Required documents per kind (mandatory flag, formats, size), a verify-and-reject queue over
  the existing `verified_at`, and the compliance report.
- The primary phone and the emergency contact verified by a WhatsApp code through
  `WhatsAppSender`; the address by a code printed on a letter (§14.7) and typed back.
- A company setting naming which checks are required, and the gate that enforces them.
- **Subject access and erasure** per employee: an export scoped from `TenantExport`; erasure
  anonymises the person and **never deletes a posted record** — payslips and ledger entries are
  kept for the statutory period.
- A *Back up now* button over `TenantBackup`, with the snapshot list.

### 14.9 The platform

- **API keys** on a screen over Sanctum tokens, with abilities as scopes (today there is one,
  `api`), last use and revoke.
- **Outgoing webhooks**: endpoints, event types from the domain events, HMAC-signed, retried on
  the queue, with a delivery log.
- Users: last login from `LogAuthenticationEvent` (which then records the company, so a tenant
  sees its own sign-ins), resend the invitation, reset 2FA. Roles: duplicate, with the system
  roles locked. Filters on the activity log.
- Buttons for the runs that are commands today: open the leave year, and §8.9's badge evaluation.
- A *Change plan* action on the Subscription page, and held seats: `seats_reserved` on the
  company, the bill counting the greatest of headcount, reserved seats and ten.

### 14.10 The remainder

Independent of each other, and split as convenient:

- **Brand Studio**: ID cards (serial, expiry, the QR of `DocumentVerification`, a print sheet,
  revoke), an email-signature generator from the employee and company records, business-card
  orders with a print queue, and a brand kit (colours, typeface) the cards use.
- A weekly sales report — fed by CRM where it is licensed, a short form where it is not —
  and scorecard profiles: per-designation weights over the merit sources, with quality and
  volume shown beside the net points.
- Field visits: staff enrolment, a GPS verdict against the client's pin and radius, an accuracy
  limit, a photo, a rating on the confirmation page; a log of refused clock-ins.
- Announcements with a category, a priority, role and person audiences, a scheduled publish and
  an archive. Assets with a tag, a vendor, a warranty and their own category list.
- The reports their walkthrough had and this lacks: anniversaries, demographics, late and
  absence lists, leave utilisation, ratings distribution, the tax slab analysis the catalogue
  already claims, the advance schedule and defaulters.
- A statutory-coverage page listing the Acts the product implements, per profile; STRN and a
  date format on the company; a phone, a map link and a head-office flag on a location; SWIFT
  and an IBAN prefix on a bank; the employee-code prefix as a setting; a favicon upload.
