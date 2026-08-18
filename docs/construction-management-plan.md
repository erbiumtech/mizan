# Construction Management — Plan

**Status:** **Phases 0 to 5 complete, and Phase 6a and 6b with them (2026-08-18). Phase 6c — the subcontract
certificate relieving its commitment — is next, and finishes Phase 6.**

Phase 5 built procurement end to end in `construction_costing`: requisitions, commitments, goods receipts, invoice
allocations with their queue screen, and the computed three-way match — 114 tests across five files. Phase 6a met
Phase 6's exit condition ahead of the rest of the phase: a certificate refuses to issue against expired insurance,
and the override records who, when and why — 30 tests. Phase 6b added back-charges, where the useful rule is that
notice is a state the register can be queried on rather than a habit somebody has — 38 tests. All three are written
up under their phases below.

Phase 4 built `construction_contracts` in full: the contract and its item schedule under both standards, variations
with the agreed-versus-forecast rule, progress claims, payment certificates with their deductions, the retention
ledger with its nightly reconciliation, the printed certificate under both PDF engines, and §10.4's invoice
hand-off — 137 tests across six files.

Two things about the last two sub-phases are worth carrying forward:

- **The printed document is engine-independent because the pagination is not in the engine.**
  `CertificateSchedule` chunks the continuation sheet in PHP, so the sheet breaks the same way whether headless
  Chrome or Dompdf renders it. The honest limit is stated in `ConstructionCertificatePrintTest`: the PDF *bytes*
  differ by engine because one writer is Chrome's and the other Dompdf's, so what is asserted identical is the
  **document** — the same pages, the same rows on each, the same brought- and carried-forward figures, the same
  "page n of m". The only difference between the two renderings is the Dompdf stylesheet in the head, which is
  what §10.5 promises.
- **The account map is a settings block contributed by this module**, which is what §18.2's refusal to add
  `'core' => [… 'construction']` requires and what the `SettingsSection` registry from the packaging plan exists
  for. Building it caught a real hole on the way: validating the codes against the chart locked *every* company
  out of Company Settings, because the construction accounts arrive with the construction profile rather than
  with the base chart. The block is now hidden without the module, and a code left at its shipped default is not
  validated there at all — the refusal it needs belongs at the point of use, where `ConstructionAccounts::id()`
  names the missing code and the seeder.

Three decisions inside Phase 4 are worth carrying forward:

- **The retention ledger is written only by `RetentionService`, and issuing a certificate is the trigger.** The
  certification service computes the deduction row and calls that service, which links the movement to the row —
  so §11's reconciliation compares two registers line by line rather than in total.
- **The reconciliation compares *held* movements with the certificates, not the balance.** Comparing the balance
  would report a difference on every job that has ever released retention, which is a report people stop reading.
- **`contract_sum_to_date` is not a column.** It is the original plus the agreed variations, both frozen on the
  certificate, so a third stored column could only ever disagree with its own two inputs. §8.4's G702 mapping
  already calls line 3 derived; the header list that also names it is the redundancy, not the mapping.

**Phases 0 to 3.** Phase 1 built the spine — jobs, the WBS, locations, the cost-code library and the ISO 19650
document register. Phase 2 built the cost ledger and its live
report. Phase 3 built budget versions with the baseline, progress measurements, earned value, forecast runs and
§3.5's four-column report, in `ConstructionEarnedValueTest` (38 tests). Three things about Phase 3 as built are
worth carrying forward, each found by a test rather than by reading:

- **The model is `JobBudget`, not `BudgetVersion`.** Accounting already owns the `App\Models\BudgetLine` morph
  alias, and aliases are keyed on the class basename — so the pair is `JobBudget` and `JobBudgetLine` over the
  planned `construction_budget_versions` and `construction_budget_lines` tables.
- **Budget and earned value roll up the job tree, not just cost.** A budget is held on the job that was
  tendered while reporting happens at whatever level somebody asks, so the report reads the *current* version of
  every job in the subtree and earned value reads every *baseline* in it. With only the root's own version, a
  development that is exactly on budget reports a variance equal to its entire spend.
- **One measurement per control account per period is enforced in the service, not by the index.** Most control
  accounts have a null `wbs_node_id`, and both MySQL and SQLite treat nulls in a unique index as distinct — so
  the index lets a second row through and the job's earned value doubles, which reads as ahead of schedule.
  Re-measuring an open month revises the figure in place; a locked one is refused.

Phase 0's own record follows. Both halves of Phase 0 are done: the one
prerequisite in another module — `InvoiceService::purchaseEntryLines()` now flips the leg for a negative line,
so a subcontract retention line can post, covered by `PurchaseInvoiceNegativeLineTest`, and it corrected the
risk entry that described the failure as silent — and all four decisions, recorded in the Phases section
below. In brief: `ConstructionQhse` casing; `stock_locations` owned by Inventory rather than `store_id`, and
written into `docs/retail-stores-pos-plan.md` as well; reference data ships as structure plus CSV import with
**no seeded code lists**, which makes the redistribution licensing question non-blocking instead of answering
it; and construction is a seventh navigation domain. Phase 0 was decisions and one fix; the tables arrived with
Phase 1. Its last outstanding item, the **query-budget test**, was parked until there was a report to budget and
is now `test_the_report_does_not_grow_a_query_per_row` — the four-column report and the earned-value metrics take
the same number of queries for four hundred cost codes as for two.
**Created:** 2026-08-14
**Covers:** the job and its classification (§1–§2), the job-cost ledger and its reconciliation to the
books (§3–§4), procurement, site stores, labour and plant (§5–§7), the head contract, variations,
certification and
retention under both FIDIC and AIA (§8–§11), subcontracts (§12), schedule and earned value (§13–§14),
document control to ISO 19650 (§15), field operations (§16), QHSE to ISO 9001 and ISO 45001 (§17), and
the module boundary (§18)

Goal: let a contractor run a building or civils job from this application end to end — win it, price
it, buy for it, staff it, build it, measure it, get certified and paid for it, and know at any moment
what it has cost against what it was sold for. The commercial half is built to the two contract
families the world actually uses (**FIDIC** for international and most of Asia, the Middle East and
Africa; **AIA** for North America), selected per job, over one set of tables. The site half is built to
the management-system standards a client audit asks for: **ISO 19650** for information, **ISO 9001**
for quality, **ISO 45001** for safety.

Six findings shape everything below, and the second and fourth are the whole difficulty.

1. **`projects` already exists and is the wrong shape.** `App\Modules\Projects\Models\Project` is
   delivery teams plus **uptime monitoring** — `project_environments` with health checks, certificate
   expiry and a public status page
   (`database/migrations/tenant/2026_07_27_140000_create_projects_tables.php`). It has no tasks, no
   milestones, no WBS, no budget and no cost. A construction job is not that entity with a different
   label, and this plan does not extend it — §1 says what it does instead, and what stops two
   project-shaped things in one panel from confusing everybody who uses it.
2. **The ledger has no dimension column.** `journal_entry_lines` is `journal_entry_id, account_id,
   debit_amount, credit_amount, description` and nothing else
   (`2026_07_15_082837_create_journal_entry_lines_table.php`). Per-job profit therefore needs either a
   job on the line, a chart of accounts per job, or a second ledger that reconciles.
   `docs/retail-stores-pos-plan.md` §2.2 hit the identical fork for stores and put the dimension on the
   line. **This plan takes the third branch by decision** (§3), which buys a cost model the GL cannot
   express and owes a reconciliation that cannot be skipped (§4).
3. **`stock_movements` has no location.** Product, type, quantity, unit cost, `remaining_quantity`, a
   date, and nothing else (`2026_07_16_120000_create_products_and_stock_movements_tables.php`);
   `InventoryValuationService::onHand()` sums every movement for a product, everywhere. "What steel is
   on site" is unanswerable today. The POS plan's finding #1 is the same column, and §6 says which of
   the two plans owns the migration, because two plans silently assuming the other will add it is how
   neither does.
4. **There is no procurement at all.** No purchase orders, no goods receipts, no requisitions, no
   three-way match, no warehouses — a supplier bill is `invoices.kind = 'purchase'` and a supplier is
   `contacts.kind ∈ {supplier, both}`. **Commitment is the number a construction manager actually runs
   the job by** — what is already promised to somebody but not yet invoiced — and there is nothing in
   this application that holds it. §5 is therefore the largest net-new schema surface here, larger than
   the contract half.
5. **Timesheets bill but never cost.** `timesheet_entries` carries `minutes`, `is_billable` and a rate
   ladder of project → employee → company default
   (`2026_08_13_120000_create_timesheet_entries_table.php`). There is a charge-out rate and no cost
   rate, no burden, no overtime premium as cost. Labour is usually the largest controllable cost on a
   job, and §7 has to add costing without breaking the billing that rate ladder already serves.
6. **Budgets are per GL account per fiscal period.** `budgets` + `budget_lines` are
   `(fiscal_year, account, period, amount)`. A construction budget is per cost code per job, is not
   bounded by a fiscal year, and moves every time a variation is approved. It is a new table, not an
   extension of that one.

## What already exists and is reusable

Written down because the size of this plan depends on it. The accounting spine is built and this plan
adds no second one.

- **Double-entry posting is solved and has exactly one door.**
  `App\Modules\Accounting\Services\JournalEntryService::create($header, $lines)` validates the balance,
  converts foreign currency through `Support\Money`, derives the fiscal year from the entry date and
  wraps the write in `TenantTransaction::run()`. Everything in §4 posts through it and nothing here
  writes a journal line directly.
- **A module→account mapping precedent exists.** `App\Modules\Accounting\Support\PayrollAccounts`
  resolves which account a payroll component posts to. §4's `ConstructionAccounts` is that class with a
  different vocabulary — WIP, cost of construction, retention receivable, retention payable, uncertified
  revenue — and copying its shape is cheaper than inventing a configuration screen.
- **Invoicing is complete in both directions.** `invoices.kind` is `sale|purchase`, so the client
  invoice and the supplier bill are the same table with the same posting service, with tax per line,
  credit notes, multi-currency and payment settlement against A/R and A/P. **No document in this plan
  re-implements an invoice** — a payment certificate *produces* one (§10).
- **Clients and subcontractors are already Contacts.** `contacts.kind` is `customer|supplier|both`,
  with NTN/CNIC, payment terms and a bank. A subcontractor is a supplier; this plan adds the
  construction-specific facts about one (prequalification, insurance, retention terms) alongside rather
  than a second party table.
- **Imprest floats are a solved pattern.** `PettyCashService` runs opening balance → vouchers →
  closing → replenishment. A site petty-cash float is the same shape with a job on the voucher.
- **Row-level scoping has a precedent.** `App\Support\EmployeeAccess` plus a `getEloquentQuery()`
  filter per resource is how manager/downline visibility works. Job-team scoping — a site engineer sees
  their sites and no others — is the same shape with a different resolver (§1).
- **PDFs are already fought for.** `spatie/laravel-pdf` with Dompdf and per-engine override partials,
  and the constraints are known and recorded. §10's G703 is the hardest page in this plan and §10 says
  how it survives Dompdf.
- **Comments, custom fields and the audit trail are polymorphic and free.** `HasComments`,
  `HasCustomFields` and `Auditable` attach to any model. An RFI needs a discussion thread, not a
  `rfi_comments` table.
- **Module mechanics are documented and enforced.** `docs/new-module-checklist.md` is the thirteen-step
  authority — registry entry, company profile, plugin, provider, `ModuleMap`, policies, permissions,
  tenant migrations, route middleware — and `ModuleCoverageTest`, `ModuleGatingTest`,
  `ModuleBoundaryTest` and `CompanyProfileTest` fail the build for anything missed. **This plan restates
  none of it** and §18 gives only what is specific to these modules.

## Not doing

- **Extending `Project`.** Decided, and finding #1 is why: that model is an environment monitor. Making
  a construction job a subtype of it would put `health_status` and `certificate_expiry` on a
  bridge deck, and would make the construction modules unsellable to a company that never bought
  Projects. The cost of the decision is two project-shaped nouns in one application, and §1 pays it
  with a naming and navigation rule rather than pretending it does not exist.
- **Dimensions on `journal_entry_lines`.** Also decided, and §3 defends it. The consequence — two
  places a cost can live — is the most expensive thing in this plan if it is left to look after itself,
  which is why §4 is a design section and not a paragraph.
- **A Gantt engine.** §13 builds activities with dates, progress and predecessors because SPI and a
  three-week look-ahead need them. Critical-path calculation, resource levelling, calendars with
  exceptions and a drag-and-drop bar chart are Primavera P6 and MS Project, both of which every serious
  contractor already owns. The plan imports from them and exports to them; it does not compete.
- **A BIM viewer or model server.** §15 is a **document register** to ISO 19650 — containers, naming,
  suitability, revisions, states, transmittals. Rendering IFC, clash detection and federated models are
  a different product with a different cost base. The register is what the standard actually audits.
- **An estimating package.** Cost codes carry budgets and rates (§2, §3); a first-principles estimating
  build-up with resource libraries, waste factors, productivity norms and price books is its own
  application. The plan takes an estimate **in** — as budget lines against cost codes, importable from a
  spreadsheet — and does not produce one.
- **Legal compliance as code.** §10 notes that statutory payment regimes exist — the UK Construction
  Act's payment notice and pay-less notice with their five-day and seven-day clocks, US prompt-payment
  and lien statutes, and the retention reforms now in front of the UK parliament — and models them as
  **configurable notice periods and document types**, not as hardcoded rules per jurisdiction. A
  hardcoded statute is wrong the day it is amended and nobody notices.
- **Payroll for construction trades.** Certified payroll, prevailing wage, union fringes and CIS/1099
  deduction schemes are per-jurisdiction payroll products. This plan **consumes** a labour cost rate
  (§7) and **records** whether a certified payroll report was received from a subcontractor as a
  compliance document (§12). It does not compute one.
- **A separate mobile application.** §16's daily log and punch list are Filament pages that work on a
  phone browser, with photo upload and offline tolerance treated as a later question. A native site app
  is a second application with its own release cycle, and `docs/retail-stores-pos-plan.md` §12 already
  records what offline-first costs when it is real.
- **Equipment telematics and fuel cards.** §7 costs plant by hours at a rate. Reading hour meters off a
  telematics API is one integration per manufacturer and belongs to whoever buys it.

## §1 What a job is

A **job** is one contract to build one thing at one place: a tower, a road package, a fit-out, a
substation. It is the unit that gets a contract sum, a cost code structure, a site, a team, a
programme, certificates, retention and a final account. Everything else in this plan hangs off it.

`construction_jobs` (tenant):
`code` (unique, `J-2026-014`), `name`, `description`, `parent_id` (self FK — see below), `path`
(materialised ancestor path, `/12/47/`), `client_contact_id` (FK `contacts`, nullable until award),
`project_id` (nullable FK `projects`, **guarded** — see §18), `nature`
(`building|civils|infrastructure|fit_out|mep|marine|other`), `status`
(`tender|awarded|mobilising|in_progress|suspended|substantial_completion|defects_liability|final_account|closed|cancelled|lost`),
`contract_standard` (`fidic|aia|custom`), `currency_code`, `site_address_line_1/2`, `site_city`,
`site_country`, `latitude`, `longitude`, `commencement_date`, `planned_completion_date`,
`revised_completion_date` (moves only through an approved extension of time — §13),
`actual_completion_date`, `substantial_completion_date` (the Taking-Over date under FIDIC, the
Substantial Completion date under AIA — one column, two labels), `defects_period_days`,
`final_certificate_date`, `contract_sum` (original, `decimal(15,2)`), `retention_pct`,
`retention_cap_pct` (retention stops accruing at this share of the contract sum — the "limit of
retention"), `retention_first_release_pct`, `advance_payment_pct`, `advance_recovery_start_pct`,
`advance_recovery_rate_pct`, `liquidated_damages_per_day`, `liquidated_damages_cap_pct`,
`payment_terms_days`, `certifier_contact_id` (the Engineer under FIDIC, the Architect under AIA),
`manager_employee_id`, `qs_employee_id`, `site_agent_employee_id`, `closed_at`, timestamps.

`construction_job_employee` mirrors `project_employee` exactly — `job_id`, `employee_id`, `role`,
`from_date`, `to_date` (null = open stint), unique on `(job_id, employee_id, from_date)` — and is what
`JobAccess` reads (§18). A site engineer sees the sites they are on; a commercial manager sees all of
them by permission, not by assignment.

**The revised contract sum is computed, never stored.** Original sum plus approved variations (§9), read
through one accessor. The house rule from `docs/new-module-checklist.md` §10 applies with unusual force
here: a stored contract sum that drifts from the variation register is a number two people will quote in
a meeting and neither will be able to explain.

### 1.1 Two project-shaped nouns in one panel

This is the cost of the self-contained decision and it is worth naming plainly. A company that licenses
both Projects and Construction has a `Project` (a software engagement with environments and a status
page) and a `Job` (a building site), and they are unrelated tables with similar-sounding names.

Three rules keep them apart, and all three are cheap:

- **Different nouns, different domains.** The model is `Job` and never `Project`. Construction gets its
  own rail domain (§18); `ProjectResource` stays where it is, in the `Employee` group under **People**.
  A user never sees the two in one menu.
- **The label is per-tenant.** `Job` is the default, and a company setting renames it to `Site`,
  `Contract` or `Works` across every construction screen. A Gulf contractor says "project", a UK
  subcontractor says "site", a US GC says "job", and arguing about it is not worth a code change.
- **A job may *name* a project, never *be* one.** `construction_jobs.project_id` is nullable and
  offered only when `modules()->enabled('projects')`, exactly as `invoices.project_id` already works
  (`2026_08_07_120000_add_project_to_invoices_table.php`, and the coupling is already recorded in
  `ModuleBoundaryTest::KNOWN_COUPLINGS`). It buys a company that runs both the ability to see a job's
  hours in the timesheet module. It buys nothing else and is never required.

### 1.2 Sub-jobs, and why the hierarchy is not optional

`parent_id` exists because contracts are routinely awarded and certified in parts: a development with
three towers where each tower is taken over separately, a highway with four lots each with its own
completion date and its own liquidated damages, a framework with a job per call-off order. Modelling
those as unrelated jobs loses the consolidated cost report the board asks for; modelling them as one job
loses the per-lot certificate, the per-lot retention release and the per-lot LDs, all of which are
contractual.

So: a job may have a parent, cost and certificates attach to **any** job in the tree, and every report
takes a job and rolls up its descendants through `path`. Depth is not constrained in the schema but the
UI offers two levels and a third only on request — three levels of job plus the WBS below it (§2) is
already four levels of rollup, and beyond that people stop being able to find anything.

## §2 Classification: WBS, cost codes, and the standards that make the numbers comparable

The reason a construction system is not a generic project tracker is here. A cost has to be filed
against *what was built* and *what kind of cost it was*, using a coding scheme that is the same on the
next job — otherwise the cost history a contractor prices the next tender from does not exist.

Three structures, and keeping them separate is the design:

| Structure | Answers | Scope | Table |
|---|---|---|---|
| **WBS** | *what* is being built, and where | per job | `construction_wbs_nodes` |
| **CBS / cost codes** | *what kind* of cost this is | per tenant, shared across jobs | `construction_cost_codes` |
| **Control account** | the intersection — the thing that carries a budget | per job | `construction_budget_lines` (§3) |

That intersection is not an invention: **ANSI/EIA-748** defines the control account as the point where
scope, budget and actuals meet, and it is what makes earned value computable at all (§14).

`construction_wbs_nodes` (tenant): `job_id`, `parent_id`, `path`, `code` (`1.2.3`), `name`, `kind`
(`phase|zone|level|element|package`), `sort_order`, `is_leaf`, timestamps. Unique on `(job_id, code)`.
A WBS node is a *deliverable or a location*, never a cost type — "Tower B / Level 4 / Facade" is a WBS
node; "welding labour" is not.

`construction_cost_codes` (tenant): `parent_id`, `path`, `code`, `name`, `cost_type`
(`labour|material|plant|subcontract|other` — the standard five), `unit` (`m3`, `m2`, `t`, `hr`, `sum`),
`is_leaf`, `is_active`, `sort_order`, and then the classification mapping columns:
`masterformat_code`, `uniformat_code`, `uniclass_code`, `omniclass_code`, `nrm_code`,
`icms_category`, `icms_group`, `notes`. Unique on `code`.

### 2.1 One tree, many mappings — not many trees

A contractor is asked for the same money in four different shapes in the same month: the tender came in
**CSI MasterFormat** divisions, the QS measured it under **RICS NRM**, the design team codes information
in **Uniclass 2015** (UK) or **OmniClass** (North America), and the client's cost report has to arrive in
**ICMS 3** categories because that is what allows the number to be compared with a project in another
country.

The wrong answer is a tree per standard, and it is wrong for a reason that shows up late: every cost then
has to be classified four times, the four classifications drift, and reconciling them becomes a monthly
job for a person. The right answer is **one cost-code tree the company actually uses, with the standard
codes carried as columns on it**. Every report is then a `group by` on whichever column was asked for,
the mapping is maintained once per code rather than once per transaction, and a code that has not been
mapped to ICMS shows up as a single "unmapped" row in the ICMS report — visible, countable, fixable —
rather than as a silently wrong total.

**ICMS is the one that is not optional**, and it earns its two dedicated columns. ICMS 3 fixes Level 2
as six cost categories — **A**cquisition, **C**onstruction, **R**enewal, **O**peration,
**M**aintenance, **E**nd of life — and mandates standardised Level 3 cost groups beneath them (thirteen
under Construction, eight under Operation), specifically so that projects in different countries and
different national standards can be compared. Level 4 sub-groups are the company's own. That is exactly
the shape of `icms_category` + `icms_group` on a cost code whose own numbering is whatever the company
has always used: the local scheme survives, and the international report is a group-by.

### 2.2 The library is per tenant, not per job

Cost codes are shared across every job in the company and this is the entire point of having them. "What
did formwork to soffits cost us per square metre, across the last six jobs" is the question a contractor
prices the next tender with, and it is unanswerable the moment each job invents its own codes. A job
therefore *selects* codes rather than *owning* them: opening a budget line against a code is what puts it
on the job (§3), and a job's cost report shows only the codes it has used.

The escape hatch for the genuine case — a one-off provisional item that will never recur — is a code
flagged `is_active = false` at library level and used on one job. That is a deliberate, visible act.
Inventing a private numbering scheme per job is not available.

Reference data ships as a seeder: MasterFormat's fifty divisions and their common Level 2 titles, a
UniFormat elemental set for estimating, and the ICMS Level 2/3 spine, as an **opinionated starting
library a company edits**, not as a locked standard. Per `docs/new-module-checklist.md` §1a the seeder
is listed against the `construction` company profile and nowhere else, or `CompanyProfileTest` fails.

## §3 The job-cost ledger

This is the decision the plan turns on, and it was taken deliberately: **construction keeps its own cost
ledger and reconciles to the books, rather than adding a job dimension to `journal_entry_lines`.**

### 3.1 Why a second ledger, honestly

The dimension-on-the-line approach — which `docs/retail-stores-pos-plan.md` §2.2 chose for stores — is
simpler and has one source of truth, and if job costing were only "profit per job" it would be the right
answer here too. It is not enough for construction for three reasons that are not preferences:

- **A cost entry needs a quantity and a rate, and a journal line cannot carry one.** Rate analysis —
  *"we budgeted 4,200 per m³ of poured concrete and we are running at 5,050"* — is the entire discipline
  of construction cost control, and it is unavailable from money alone. Putting `quantity`,
  `unit_of_measure` and `unit_rate` on `journal_entry_lines` would impose construction's shape on every
  payroll and bank posting in the application.
- **Not every job cost is a general-ledger event.** Burden absorbed at a rate, internal plant charged at
  an hourly rate, overhead allocated on a basis, a notional tender comparison — these are management
  facts. Some post to the GL as recoveries; some deliberately never post. A ledger that must balance
  cannot hold the ones that never post, and inventing balancing entries for them corrupts the accounts.
- **Cost periods and fiscal periods are different clocks.** A job runs three years and reports monthly
  against a valuation date; the GL closes on a fiscal calendar. Forcing job cost onto the fiscal clock
  makes the monthly cost report a fiscal-year artefact, which is not what a certificate is measured on.

**The price of the decision is that nothing forces the two to agree**, and §4 is what pays it. If §4 is
ever descoped, this section should be descoped with it — the pair only makes sense together.

### 3.2 What a cost entry is

An immutable statement: *this much value, of this cost type, landed on this job, WBS node and cost code,
incurred on this date, in this cost period, caused by this document, and here is its relationship to the
general ledger.*

`construction_cost_entries` (tenant): `job_id` (always a cost-bearing leaf job), `wbs_node_id`
(nullable), `cost_code_id`, `job_cost_code_id` (nullable — the job's own BoQ line, for printing),
`cost_type` (**snapshotted** from the code, not read through it), `kind`
(`actual|accrual|allocation|reclass|reversal`), `amount` `decimal(15,2)` **signed**, `quantity`
`decimal(14,4)`, `unit_of_measure`, `unit_rate` `decimal(14,4)`, `incurred_on`, `posting_period` (the
first day of the cost month, a date and not a 1–12 index, for the reason `budget_lines.period_start`
already gives), `is_late_for_period`, `fiscal_year_id`, `gl_treatment`
(`mirrored|posted|pending|memo`), `journal_entry_id`, `gl_account_id`, `posted_to_gl_at`, `batch_id`,
`contact_id`, `employee_id`, `worker_id`, `is_burden`, `reverses_id`, `reversed_by_id`, `description`,
`reference`, `nullableMorphs('source')`, `created_by`, timestamps.

Three details are load-bearing rather than incidental:

- **One signed amount, not a debit and a credit.** A debit/credit pair here imports an accounting form
  that buys nothing and invites the question "which column does a credit note go in", which two
  developers will answer differently.
- **`cost_type` is snapshotted.** Re-typing a cost code in June must not restate March's labour/material
  split — the same reasoning that stores `invoice_lines.tax_amount` rather than recomputing it.
- **`gl_treatment` is a column, never inferred from `journal_entry_id IS NULL`.** `pending` (should have
  reached the GL, has not yet) and `memo` (deliberately never will) look identical as a null, and a null
  that means "we do not know which" is exactly how a sub-ledger drifts for a year unnoticed.

**The invariant**: the sum of `amount` over a job's entries, filtered by nothing but the period, **is**
the job's cost. No `is_active`, no soft delete, no current-version flag. A flag that must be filtered is
a flag somebody forgets, and the query that forgets it is a cost report that is wrong and looks fine.

`construction_cost_batches` gives bulk operations one thing to reverse — a month's overhead allocation
across two hundred codes, a labour run, an accrual and its reversal — with `kind`, `period_start`,
`journal_entry_id`, `posted_at` and `reversed_batch_id`. A reversal that has to re-find its two hundred
rows by predicate is a reversal that will one day find a hundred and ninety-nine, and nothing will say
which one it missed.

### 3.3 Corrections are reversals, with one line drawn deliberately

An entry becomes immutable when either its period is closed or `posted_to_gl_at` is set. After that a
correction is a `kind='reversal'` row with the amount and quantity negated and `reverses_id` pointing
back, plus the corrected entry where the cost was real but mis-coded.

Before that point, editing an entry in an open period is allowed and audited. Absolutism here is worse
than the rule: forcing a reversal pair for a typo made ten seconds ago produces three rows where one is
true, and a cost report full of ±5,000 pairs is unreadable. The line sits exactly where `journal_entries`
already draws it — `JournalEntryService::post()` refuses a closed fiscal year and `::reverse()` mirrors
rather than mutates.

### 3.4 Cost periods

`construction_cost_periods`: `period_start` (unique), `period_end`, `status`
(`open|closed|reconciled`), `closed_at`, `closed_by`, `reconciled_at`, `gl_control_total`,
`jc_control_total`, `difference`, `notes`.

A closed period refuses new entries. **A late supplier invoice dated into a closed month lands in the
open period with `incurred_on` preserved and `is_late_for_period = true`**, and there is a Late Costs
report. Reopening a signed-off period to slot one invoice in invalidates the WIP snapshot, the client
certificate and the GL summary that all depended on that period's total — and silently changing a closed
month is the precise failure this whole design exists to prevent.

### 3.5 Budget, commitment, actual, forecast — the four columns

`construction_budget_versions`: `job_id`, `version_no`, `name` (*Tender*, *Contract award*, *Rev 3 post
VO-12*), `kind` (`estimate|original_budget|revision`), `status`, **`is_baseline`** and **`is_current`**
(exactly one of each per job), `effective_from`, and the approval stamps.
`construction_budget_lines`: `budget_version_id`, `job_id` (denormalised for the hot query),
`wbs_node_id`, `cost_code_id`, `cost_type`, `quantity`, `unit_of_measure`, `unit_rate`, `amount`, and a
nullable `period_start` that time-phases the line.

**Versioned because earned value needs a baseline that does not move and a cost report needs a budget
that does.** Without the split, cost variance is measured against a number somebody edited last Tuesday,
schedule variance is meaningless, and the variance report answers a question nobody asked. Superseded
versions stay for comparison, which is the same reasoning `budgets.is_active` already gives.

`construction_forecast_runs` and `construction_forecast_lines` hold the surveyor's cost to complete per
control account, the method used, and the resulting forecast final cost. **A forecast is a snapshot, not
a mutable row**: the whole value of forecasting is comparing last month's estimate at completion with
this month's — *we said 4.2 in March and 4.9 in April; what moved* — and a single mutable row destroys
the only report that makes forecasting worth doing.

The report, per job subtree × cost code, for a period or to date:

| Column | Source |
|---|---|
| Budget | `construction_budget_lines` of the `is_current` version |
| Committed, open | `Σ commitment_lines.amount − Σ reliefs.amount`, approved commitments only (§5) |
| Actual | `Σ cost_entries.amount` where `kind != accrual` |
| Accrued | `Σ cost_entries.amount` where `kind = accrual` |
| Cost to complete | `construction_forecast_lines.cost_to_complete` |
| Forecast final | actual + accrued + cost to complete |
| Variance | budget − forecast final |

**The committed-aware rule: cost to complete may never be less than the open commitment on that code.** A
cost code with a purchase order worth more than its remaining budget is already overspent, and a forecast
saying otherwise is forecasting money that has already been promised away. The form defaults the figure
to the greater of remaining budget and open commitment; a lower number is accepted **with a reason** and
appears on a *forecasts below commitment* exception report.

## §4 Reconciliation to the general ledger

The load-bearing consequence of §3. A second ledger that nobody proves is a second ledger that is wrong.

### 4.1 Nothing double-posts

| `gl_treatment` | Meaning | Who posted the GL side |
|---|---|---|
| `mirrored` | The source document already posted. Job cost is a *dimension* of an existing GL cost. | Invoicing, Inventory, Payroll |
| `posted` | Construction posted it, in a summary journal. | `ConstructionGlPostingService` |
| `pending` | It should reach the GL and has not yet. | Nobody, yet |
| `memo` | Deliberately outside the GL. | Nobody, ever |

If the source is a GL document — supplier invoice, payment, stock movement, payslip — construction posts
**nothing** and mirrors. If the source is construction-only — site labour with no payroll behind it,
burden absorption, internal plant recovery, overhead allocation, the goods-received accrual, the
uncertified-work accrual, WIP — construction posts a **summary journal per period per (GL account × cost
type)** through `JournalEntryService::create()`, exactly as `InventoryService` posts its system entries.

Per-entry posting is not on the table: a hundred thousand entries a period would make
`journal_entry_lines` the largest table in the tenant and the general ledger unreadable. **What makes
summary posting safe is the batch link** — the batch carries `journal_entry_id`, every contributing entry
carries `batch_id`, and any GL line explodes into its constituents in one query. A summary posting
without that link is a number in the accounts nobody can explain, and should be treated as a defect
rather than a shortcut.

### 4.2 The reconciliation report

`construction_control_accounts` nominates which accounts are in scope, by `kind`
(`cost|wip|accrual|retention|revenue|recovery|contract_asset|contract_liability`) — a table rather than a
config list, so a company can name its own accounts and the report can name them back.

```
GL cost for the period          Σ journal_entry_lines (Dr − Cr) on control accounts of kind=cost,
                                posted entries only — the same filter FinancialReportService uses,
                                which must be matched exactly or the two sides will disagree
                                about drafts and nobody will know why
  less   GL cost carrying no job        [UNALLOCATED — shown, never spread]
  ±      reconciling items             [GRN accruals, materials on site, retention movement,
                                        internal plant recovery, burden absorbed]
= Expected job-cost total
  vs     Σ construction_cost_entries.amount where gl_treatment != 'memo'
Difference                              must be 0.00
```

Then the drill-down **by cause**, which is what makes it a tool rather than a number: entries still
`pending` by age; GL lines on a control account with no cost entry (somebody journalled a cost straight
to `5020` from the Accounting panel — by a wide margin the most common cause); cost entries pointing at a
journal entry that was later reversed or unposted (the nastiest, because the GL side was corrected in
another module and the job cost was not); **unallocated purchase invoices, rendered even when empty**;
entries against a closed job; absorption gaps; and rounding, isolated so it cannot be used to explain
anything else.

### 4.3 What happens when they disagree

`construction_reconciliations` stores each run — totals, difference, cause breakdown, `status`
(`balanced|unbalanced|accepted`), and who accepted it and why. Five mechanisms, because a report nobody
opens is not a control:

1. A scheduled `construction:reconcile` command, licence-guarded, that writes a row and notifies when
   unbalanced.
2. The period cannot be closed while unbalanced.
3. Unless a user holding `ConstructionPeriodForceClose` accepts the difference **with a stated reason**.
4. **A forced close never fudges the ledger.** No plug entry, no balancing figure. Both sides stay true
   and the difference stays visible in every later period until the cause is fixed.
5. A feature test that posts one of every source type — allocated supplier invoice, GRN accrual, material
   issue, labour with burden, internal plant, subcontract certificate with retention, overhead
   allocation — and asserts `difference === 0.00`. That is what turns this section from a claim into an
   assertion.

### 4.4 WIP, and the loss that must be taken at once

Per job per period: cost to date, estimated total cost (the approved forecast), percent complete
(cost-to-cost, surveyed, or milestone — chosen per job, because one company runs both), contract value
including **approved** variations with pending ones shown separately and excluded, revenue recognised,
billings to date, and then the two positions every construction balance sheet carries — **costs and
recognised profit in excess of billings** (a contract asset) and **billings in excess of costs** (a
contract liability). When the forecast final cost exceeds the contract value, **the whole expected loss
is recognised immediately**, never pro-rated.

`construction_wip_snapshots` stores this per job per period, and it is the one stored total in this plan
that is not a performance materialisation. The argument against the house rule in
`docs/new-module-checklist.md` §10: a WIP position is a **judgement at a point in time** — the surveyor's
forecast, the surveyed percentage, the loss provision — not a derivation from immutable facts.
Recomputing last March's WIP with today's forecast would silently restate a month that was signed off,
reported to a bank and used to compute a bonus, and the journal posted then would no longer be
explicable by any query. So the current unlocked period is computed live and the locked ones are frozen.

The WIP journal posts the **movement** from the previous locked snapshot, not the balance. Reversing last
month's whole position and re-posting this month's is a defensible alternative — `journal_entries` has a
`reversing` type already — but it produces a profit and loss whose gross figures are enormous and whose
monthly movement has to be inferred. Choose one; two code paths each choosing differently is the failure.

### 4.5 Accruals

Two, both as batched `kind='accrual'` entries: **goods received not invoiced** (Dr job cost / Cr GRNI)
and **subcontract work done not certified** (Dr job cost / Cr accrued subcontract costs). Both
**auto-reverse at the opening of the next period** rather than being matched off against the eventual
invoice, because matching an accrual line-by-line to a later invoice is the same heuristic that fails for
commitment relief, and an accrual that fails to match sits on the balance sheet forever with nobody able
to say what it is for. Reverse-and-re-accrue is self-correcting; its own failure mode — the reversal not
running, so the accrual and the real invoice both sit in the ledger and the job costs double for a
month — is why the reversal belongs to period *open* rather than period close.

## §5 Procurement, and the number a site manager actually manages by

Committed cost — money promised to somebody and not yet invoiced — is what tells a manager whether a cost
code is overspent *before* the invoice arrives. Nothing in this application holds it today (finding #4),
so this is the largest net-new schema surface in the plan.

**`construction_commitments`** is one table serving purchase orders, subcontracts and plant hire:
`number`, `type` (`purchase_order|subcontract|plant_hire|manual`), `contact_id`, `status`
(`draft|pending_approval|approved|issued|partially_relieved|closed|cancelled`), `currency_code`,
`order_date`, `required_by`, `payment_terms_days`, `retention_percent`, approval and issue stamps, and
`closed_at` + `close_reason` — because closing a purchase order with an open balance is an intentional
act with an author, never a number that quietly stops changing.

`construction_commitment_lines` carries `job_id`, `wbs_node_id`, `cost_code_id`, `product_id`
(guarded on Inventory), description, quantity, rate and amount. **The job is on the line, not the
header**: one order of rebar split across three sites is completely normal, and forcing one order per job
means either the supplier gets three orders for one delivery — which he will not honour, and the delivery
note will then match nothing — or somebody codes the whole load to one job. Both are silent cost
misallocation and the second is invisible.

One commitments table with a subcontract *terms* extension rather than two tables, because the four-column
report and the relief mechanism must behave identically for orders and subcontracts or "committed"
means two different things in one column; two tables makes every commitment query a `UNION`, and a
`UNION` is where one branch silently gains a filter the other does not and the report still renders.

**Relief is an explicit row, not a subtraction.** `construction_commitment_reliefs` records each
`receipt|certificate|invoice|cancellation|close_out` against a commitment line. The tempting alternative
— *committed = ordered − invoiced*, matched by job, code and supplier — is a heuristic that fails the
first time one invoice covers two orders or one line is part-delivered, and it fails by leaving an
over-commitment nobody can point at and nobody can clear. With explicit reliefs, open commitment is
provable as `line.amount − Σ reliefs`. **The hazard is double relief** — the goods receipt relieves, and
then the invoice for the same receipt must not relieve again. The rule: relief happens once, at the
earlier of receipt or certificate, and the invoice relieves only the unreceived balance.

The chain, and what is reused:

| Step | Reuse or new |
|---|---|
| Material requisition | new `construction_requisitions` — nothing here has a demand document |
| Supplier quote / RFQ | new, later phase. `quotations` is *outbound* and requires Invoicing; an inbound quote is a different document and the name collision is worth flagging early |
| Purchase order | `construction_commitments` type `purchase_order` |
| Goods receipt | new `construction_goods_receipts` — where cost first touches the job |
| Supplier bill | **reuse** `invoices.kind='purchase'` and `InvoiceService` |
| Job/code attribution of the bill | new `construction_invoice_allocations` |
| Three-way match | new service and report, computed |
| Subcontract | `construction_commitments` type `subcontract`, pointing at the `construction_contracts` row |

> **Resolved 2026-08-18, at the start of Phase 5, because this section and §8.1 disagreed.** §5 above says a
> subcontract is a commitment with a 1:1 *terms* extension; §8.1 says the one contracts table serves "the head
> contract and the subcontract" with `side = payable`. Both are in this document and they cannot both be the whole
> answer.
>
> They are two aspects of one agreement, and the model links them rather than choosing: **the contract row is the
> agreement** — schedule, variations, certificates, retention, which is the whole of §8–§12 — and **the commitment
> row is the money promised**, relieved as certificates are issued, which is what the four-column report's
> *committed* column reads. `construction_commitments.contract_id` is nullable and points at the contract when
> there is one.
>
> The terms extension is therefore unnecessary: a subcontract's terms are the contract's columns, which already
> carry retention, payment days, damages and the release rule. Building a second set on a commitment would be the
> "staged retention release is the fiddliest logic in the suite, so a second copy of it will diverge" failure that
> §8.1 spends a paragraph rejecting.
>
> Commitments live in `construction_costing`, which is where §18's table puts "procurement and commitments". The
> `contract_id` is unconstrained rather than a foreign key, because costing does not require
> `construction_contracts` — the same treatment `construction_jobs.project_id` gets, and for the same reason.

A goods receipt does three things: relieves the commitment, raises an accrual cost entry at order rate,
and — only when the delivery is into a site store rather than straight to the work face, and only when
Inventory is licensed — writes a stock movement (§6).

**`construction_invoice_allocations` is a table, not three columns on `invoice_lines`.** One line —
*"rebar, 12 t"* — is routinely split across two jobs and three cost codes; columns force a 1:1 and the
workaround is splitting the invoice line, which makes the document this application prints disagree with
the one the supplier sent, discovered months later during a dispute. It also keeps construction's schema
inside construction's tables: three construction-shaped columns on `invoice_lines` would make Invoicing
carry this module's schema and would point a `ModuleBoundaryTest` coupling the wrong way.

**The cost of that choice, stated plainly: a purchase invoice can be posted with no allocation at all,
and the general ledger is perfectly correct while the job is under-costed.** It is the single most likely
silent failure in the module. Two things answer it and both are structural rather than procedural — an
*Invoices awaiting allocation* queue as a screen people work from, and a permanent, always-rendered
section of the reconciliation report showing zero when there is nothing rather than being hidden.

Three-way match — ordered against received against invoiced, per commitment line — is **computed, not
stored**, with one exception: accepting a variance is a human decision, so `match_status = 'accepted'`,
who accepted it and the reason *are* stored. A decision with no record is not a control. Tolerances live
in config overridable by settings, the `PayrollAccounts` pattern.

## §6 Site stores and material issue

Three paths, in the order most contractors actually use them.

**Direct to site is the default and touches no stock at all.** A goods-receipt line marked direct writes
a cost entry and no stock movement. This path works with Inventory unlicensed, which is what keeps
construction sellable to a contractor who buys everything to site and tracks no stock — which is most of
them, most of the time.

**A site store needs stock to have a location, and it does not have one** (finding #3). This is the same
gap `docs/retail-stores-pos-plan.md` finding #1 names, and the two plans now collide on one migration.
That plan proposes `stock_movements.store_id → stores`, backfilled and made non-null, and its own risk
section calls the backfill a one-way door. **If it lands in that form, construction site stores get two
bad options**: create fake `stores` rows — a building site in a table carrying till settings, a sales
channel and a POS registration number — or add a *second* location column, at which point on-hand is a
sum over two nullable dimensions, wrong at every location and correct in total, which is the hardest
class of wrong to notice.

**Decided 2026-08-17 — adopted, and written into `docs/retail-stores-pos-plan.md` §2.1 and its Phase 0 as
well, which was the actionable half of this note. The location belongs to Inventory.** A `stock_locations` table owned by Inventory (`code`, `name`, `kind` of
`warehouse|shop|site|van|transit`, address, `inventory_account_id`), `stock_movements.stock_location_id`
backfilled once, `stores.stock_location_id` in retail and `construction_jobs.stock_location_id` here. One
column, one backfill, one change to the valuation API, and both modules are served without either
depending on the other. **This is the highest-value cross-plan note in this document**, and two plans each
assuming the other will raise it is how neither does.

The issue document is construction's regardless. `construction_material_issues` and its lines carry job,
WBS node, cost code, product, quantity, FIFO unit cost, returned quantity, wastage quantity and reason,
and two names — who issued and who received, because the paper docket has two signatures. None of that
belongs on `stock_movements`, which is deliberately thin and already has `nullableMorphs('source')` for
exactly this division of labour: the module owns the document, Inventory owns the movement, which is what
`InvoiceService::recordMovement()` does today.

One coordinated change: `stock_movements.type` is `purchase|sale|adjustment`. Construction needs `issue`
and `return`; the retail plan needs `transfer`, `waste` and `count_adjustment`. Booking a controlled site
issue as an "adjustment" makes the shrinkage report meaningless, because adjustments are supposed to be
the *unexplained* ones. Expand the enum once, in one migration, covering both plans.

**Materials on site** — delivered, costed, not yet consumed — is exactly the gap between receipt and
issue, and it is one query. It is also a line on every payment certificate (§10), which is the second
reason receipt and issue are separate documents.

## §7 Labour and plant

### 7.1 Construction owns its labour records, and the reason is not tidiness

Three facts force it. `timesheet_entries` bills and never costs — the rate ladder resolves what time is
*billed* at, by its own migration comment, and there is no cost rate and no burden anywhere.
`timesheet_entries.project_id` is **not null** and constrained to `projects`, so a construction job
cannot appear on one at all without the Projects module. And the decisive one: **on a site, most hands
are not employees** — no payslip, no login, paid weekly through a gang leader. Requiring an `Employee`
row per labourer would make Employees a hard dependency and would put three hundred people who are not
employed into the HR register, where Leave, Payroll and Lifecycle would then all see them.

So `construction_workers` (with a nullable `employee_id` for the ones who *are* employees, and a nullable
`subcontractor_contact_id` for labour supplied through a gang leader), `construction_trades` with default
codes and rates, and `construction_labour_records` carrying job, WBS node, cost code, date, normal and
overtime **minutes** (minutes, like `timesheet_entries` and attendance, because a rate multiplied by a
rounded decimal of hours accumulates visible error across a month), the overtime multiplier, and — the
important part — `cost_rate_per_hour` and `burden_percent` **snapshotted at approval**.

Where Timesheets *is* licensed, its entries import into labour records rather than being read in place,
carried on the `source` morph. Billing keeps its ladder; costing gets its own.

### 7.2 A dated rate table, not a rate column

`construction_labour_rates` resolves **job+trade → job → worker/employee → trade → company default**,
each row with `effective_from` and `effective_to`.

A wage revision effective the first of April must not restate March's job cost. A `cost_rate_per_hour`
column on the worker does exactly that, silently, the moment somebody edits it: every historical record
recomputes, every closed period's cost changes, and there is no journal, no audit and no report of what
moved. The snapshot on the record is the second line of defence; the dated table is the first.

### 7.3 Burden and internal plant must be absorbed, or both ledgers diverge

Burden is written as its **own** entry against the **same** cost code, flagged `is_burden`, so "labour
cost" stays one number and burden stays separable. The rule that matters: **burden charged to jobs must
credit a Labour Burden Absorbed account**, against which the real statutory and welfare costs accumulate
through payroll, with the difference showing as over/under absorption on the profit and loss. Charge it
and never absorb it and job cost exceeds GL cost by exactly the burden, growing every month, with no
error anywhere — §4 is the only thing that would find it.

Plant is the same shape and the same trap. `construction_plant_items` (owned, hired, or hired with
operator; linked to `fixed_assets` when owned) and `construction_plant_logs` (working, idle and standby
units, meter readings, fuel, operator, downtime reason, rate snapshotted). **Charging a job internal hire
for a company-owned excavator must credit a Plant Internal Hire Recovery account**, against which
depreciation — `DepreciationService` already exists — fuel, repairs and the operator accumulate. Debit
the job with no credit and the fleet looks free while every job looks expensive.

Hired plant runs the ordinary chain, and the plant log becomes the *check*: days on site times rate
against the invoice, which is a two-way match that catches the classic over-billing of plant left
standing after it was collected.

## §8 The head contract, under both standards

Three decisions carry this half of the plan, and they are worth stating before the tables.

**Cumulative is stored; the period movement is derived.** Both a FIDIC Interim Payment Certificate and
an AIA G703 are cumulative documents — *"total completed and stored **to date**"*. Store the period
amount and sum for the cumulative, and a corrected earlier certificate silently breaks every later
total. Store the cumulative and a correction is self-healing: the next certificate's "this period"
figure absorbs it, which is exactly what happens on paper.

**One set of tables, two vocabularies, and — the highest-leverage decision here — three neutral date
columns.** Taking-Over under FIDIC and Substantial Completion under AIA are the same real-world event;
so are expiry of the Defects Notification Period and the end of the correction period. One
`practical_completion_date` plus `defects_period_days` makes retention release **one function with a
branch** instead of two implementations that drift.

**Draft computes, issue freezes.** The house rule is that totals are computed, and it protects *running
balances*. A payment certificate is not a running balance; it is a statement of a moment handed to a
third party. This codebase already makes exactly this exception and says why —
`Invoice::exchangeRate()` reads the stored column "because a rate recorded later for the invoice date
must not silently restate an invoice that has already been issued". Certificates, certificate lines and
variation amounts follow the same rule.

### 8.1 One table for the head contract and the subcontract

`construction_contracts` (tenant): `job_id`, **`side`** (`receivable|payable` — we bill the employer, or
we pay a subcontractor; the only structural difference between the two halves of this module),
`contract_standard` (`fidic|aia|custom`), `fidic_book` (`red|yellow|silver|green|gold|pink`, driving
printed clause references and nothing else), `contract_number`, `contact_id`, `parent_contract_id` (a
subcontract names the head contract above it, which is what makes back-to-back retention reportable),
`title`, `scope_summary`, **`measurement_basis`**
(`lump_sum|remeasured|mixed|cost_plus|target_cost`), `classification_system`, `currency_code`,
`exchange_rate`, then the money — `contract_sum`, `retention_percent`, `retention_limit_percent`,
`retention_limit_amount`, `retention_release_rule`
(`fidic_two_stage|aia_substantial|single_stage|custom`), `retention_first_release_pct`,
`materials_retention_percent`, `advance_payment_amount`, `advance_recovery_start_pct`,
`advance_recovery_rate_pct`, `liquidated_damages_per_day`, `liquidated_damages_cap_pct`,
`payment_terms_days`, `certification_period_days`, `minimum_certificate_amount` — then the dates:
`contract_date`, `commencement_date`, `time_for_completion_days`, `contract_completion_date`,
`extended_completion_date`, `practical_completion_date`, `defects_period_days`,
`final_completion_date`, and `status`.

**`measurement_basis` is not derived from `contract_standard`, and conflating them is a real error**:
AIA contracts are routinely unit-price, and FIDIC Yellow is lump sum. It is the measurement basis, never
the standard, that decides whether quantities are remeasured.

**Expiry of the defects period is computed** — `practical_completion_date + defects_period_days` — and
never stored. A stored expiry date is a date that stops agreeing with the completion date somebody
corrected last week, and the disagreement is worth money.

**One table rather than two** because the arithmetic is mirrored: a subcontract has a schedule,
variations, applications, certificates, staged retention, and a certificate that becomes a *purchase*
invoice instead of a *sale* invoice. Two tables means two variation tables, two certificate tables and
two retention ledgers — and staged retention release is the fiddliest logic in the suite, so a second
copy of it will diverge. The precedent is exact and already in this repository: `invoices.kind` is one
table for receivable and payable, with `InvoiceService` branching only at the posting step.

`construction_contracts.contract_standard` is **copied down from the job and frozen on first
certification**. It drives the numbering series, the retention release rule and the printed form of
documents already issued; flipping it in month fourteen would retroactively re-label certificates one to
thirteen, re-print them on a different form and change how much retention is releasable today, with no
event recording any of it. The same argument `quotations` makes for supersession — *"a quote is a
document somebody has in their inbox"*. It also lets a FIDIC Red head contract sit above bespoke
subcontracts, which a job-level-only setting cannot express at all.

### 8.2 The SOV line and the BOQ item are the same row

`construction_contract_items`: `contract_id`, `parent_id` (BoQ sections, G703 subtotal groups), `item_no`
(the printed line number — `2.1.4` or `03 30 00`), `sort`, `wbs_node_id` and `cost_code_id` (**the join
to job cost**), `classification_code`, `description`, `item_type`
(`measured|lump_sum|provisional_sum|prime_cost_sum|dayworks|contingency|milestone|advance|adjustment`),
`unit`, `quantity`, `rate`, **`scheduled_value`**, `retention_applies`, `materials_allowed`,
`source_variation_id`, `supersedes_item_id`, `is_active`.

**Which fields differ by standard: none.** AIA fills `item_no`, `description` and `scheduled_value` and
leaves unit, quantity and rate null. FIDIC fills unit, quantity and rate and derives the scheduled value.
The difference is which columns are null, not which table you are in — and that is the proof the
dual-standard model holds rather than a claim about it.

`scheduled_value` is recomputed from quantity × rate **while the contract is draft** and frozen at
execution. It is the figure the client signed, and it must not move when somebody edits a quantity on a
remeasured line — otherwise G703's "work completed from previous applications" can exceed its
"scheduled value", and the form becomes arithmetically impossible.

### 8.3 What `contract_standard` actually drives

A `ContractVocabulary` value object, keyed on standard. Nothing else in the module branches on it.

| Concept | `fidic` | `aia` | `custom` |
|---|---|---|---|
| Item schedule | Bill of Quantities | Schedule of Values | Contract Schedule |
| Change | Variation (VO) | Change Order (CO) | Change |
| Contractor's document | Statement | Application for Payment | Payment Application |
| Certifier's document | Interim Payment Certificate | Certificate for Payment | Payment Certificate |
| Certifier | Engineer | Architect | Certifier |
| Completion event | Taking-Over Certificate | Substantial Completion | Practical Completion |
| Defects | Defects Notification Period | Correction Period | Defects Period |
| Final | Performance Certificate | Final Completion | Final Completion |
| Numbering | `IPC-{n}` / `VO-{n}` | `APP-{n}` / `CO-{n}` | `PC-{n}` / `CH-{n}` |
| Retention default | two-stage, 50% at Taking-Over | released at Substantial Completion | single stage |

Numbering follows `Quotation::nextNumber()` in shape but is scoped **per contract rather than per year**:
certificate numbering restarts at one for each contract and must have no gaps, because a missing
certificate number is a question at adjudication.

### 8.4 Worked example — one FIDIC certificate, one AIA application, the same tables

A remeasured FIDIC Red contract, sum 500,000,000, retention 10% capped at 5% of the sum, advance
50,000,000 recovered at 25% once certified value passes 10%.

Certificate 7 header, all frozen snapshots: `contract_sum_original` 500,000,000,
`variations_net_to_date` +13,020,000 (**agreed** variations only), `contract_sum_to_date` 513,020,000,
`gross_work_to_date` 212,400,000, `gross_materials_to_date` 3,200,000, `gross_value_to_date`
215,600,000. Then `construction_certificate_deductions`, every one a row and every one signed negative:
retention −2,140,000, advance recovery −5,350,000, an NCR deduction under clause 14.6 −850,000, and
previously certified −184,900,000, leaving `current_due` 22,360,000.

The AIA form reads off the same columns:

| G703 column | Source |
|---|---|
| A, B, C — item, description, scheduled value | `contract_items.item_no`, `.description`, `.scheduled_value` |
| D — work completed from previous applications | `certificate_lines.previous_work_value` (a frozen snapshot) |
| E — work completed this period | `cumulative_work_value − previous_work_value` |
| F — materials presently stored | `certificate_lines.cumulative_materials_value` |
| G — total completed and stored to date | D + E + F |
| H — balance to finish | C − G |
| I — retainage | `certificate_lines.line_retention` |

| G702 line | Source |
|---|---|
| 1 Original contract sum | `certificate.contract_sum_original` |
| 2 Net change by change orders | `certificate.variations_net_to_date` |
| 3 Contract sum to date | derived |
| 4 Total completed and stored to date | `certificate.gross_value_to_date` (= Σ column G) |
| 5a / 5b Retainage on work / on stored material | deduction rows, split by basis |
| 5 Total retainage | `certificate.retention_to_date` |
| 7 Less previous certificates | `certificate.previously_certified` |
| 8 Current payment due | `certificate.current_due` |

The FIDIC certificate needed five header figures and a deductions child table. The AIA certificate needs
the identical five. The only figure FIDIC uses that AIA does not — advance recovery — is a deduction
*row*, not a column, so an AIA certificate simply has no such row. That is the whole of the dual-standard
claim, discharged.

## §9 Variations and change orders

One table, `construction_variations`: `contract_id`, `variation_number`, `origin`
(`employer_instruction|engineer_instruction|architect_supplemental|contractor_proposal|rfi|ncr|site_condition|design_change|provisional_sum_expenditure|dayworks|claim|compensation_event`),
a `source` morph pointing at the RFI, NCR or instruction that caused it, `title`, `description`,
`justification`, `valuation_method`
(`rates_in_contract|pro_rata_rates|new_rate_agreed|dayworks|lump_sum|cost_plus_percentage` — FIDIC 12.3
and 13.3), `status`, `quoted_amount`, `assessed_amount`, `approved_amount`,
**`is_price_provisional`**, `provisional_confidence`, `time_impact_days`, `time_granted_days`,
`eot_status`, and the instruction, submission, pricing, approval and incorporation stamps.

`construction_variation_items` carries `action` (`add|omit|remeasure|rate_change`), the target
`contract_item_id`, the new item's description, unit, quantity, rate and amount, and — the audit trail —
`previous_quantity` and `previous_rate`.

```
draft ─► submitted ─► priced ─┬─► approved ──────────────────────► incorporated
                              ├─► approved_in_principle ─► priced ─► approved ─► incorporated
                              └─► rejected
```

**`approved_in_principle` with `is_price_provisional` is the state construction actually lives in**:
instructed, work proceeding, price disputed for four months. Modelling it as either approved or not
forces a choice between certifying money nobody agreed and forecasting a cost the job is already
incurring.

**The rule that follows is the sharpest one in this section.** A provisionally priced variation is
**excluded from the certified contract sum and included in the forecast**. Two named scopes on the
model — `agreed()` (`approved` and not provisional) and `forecast()` (`approved` or
`approved_in_principle`) — and **no bare `where('status', 'approved')` anywhere in the module**. The
certificate's `variations_net_to_date` and G702 line 2 read `agreed()`; the cost report reads
`forecast()`. One boolean, two audiences, and conflating them is how a job reports a margin it does not
have for two quarters running.

**Approval writes items; it never edits them.** An `add` creates a contract item carrying
`source_variation_id`, which is exactly how AIA prints change-order lines appended to the G703. An `omit`
creates a **negative** scheduled value rather than reducing the original — reducing the original destroys
the audit trail *and* breaks certificates already issued, because column D would then exceed column C,
which is impossible on the face of the form. A negative line is ugly on the page and correct in the
ledger; the print layer may net the pair. `remeasure` and `rate_change` are the one permitted in-place
edit, and they are safe precisely because on a remeasured contract the quantity was always provisional —
it is a bill of *approximate* quantities — and every certificate line carries its own frozen cumulative
value regardless.

**Provisional and prime cost sums** are item types, and expending one is a variation with
`origin = provisional_sum_expenditure` that omits the sum in full and adds the actual instructed work.
That is what FIDIC 13.5 requires, and it is what keeps the contract sum from double-counting.

**Dayworks** are priced from the site diary — labour hours by trade, plant hours working and idle,
materials — through a `DayworksPricingService` that reads a date range of daily-log children and
produces a priced variation. Guarded on the field module: without it, dayworks are typed by hand and the
action is absent rather than broken.

## §10 Progress, certification and payment

### 10.1 Two documents, because they are two documents

`construction_progress_claims` is what the contractor submits; `construction_payment_certificates` is
what the certifier issues. FIDIC clause 14 makes them different documents by different parties with
different dates and different legal effect — time bars run from the statement, the payment period runs
from the certificate — and one row cannot hold two issue dates and two authors honestly. On the payable
side the subcontractor's application arrives and we certify a *different* number, and **applied versus
certified is the single figure every commercial manager asks for**, visible only if both survive.

The strong counter-argument is AIA, where G702 is literally one form serving as both, with the architect
striking through and initialling the certified figure. The answer: G702 is *printed* as one form, and two
tables print it as one form by joining. One table cannot print a FIDIC certificate that certifies a
figure different from the statement without inventing shadow columns — so two tables serve both standards
and one table serves only one of them.

`payment_certificates.progress_claim_id` is **nullable**, because clause 14.6 permits the Engineer to
certify without a conforming statement. The nullability costs nothing; its absence would be a hard block
on the FIDIC path.

### 10.2 Value is authoritative; percent and quantity are inputs

A claim line carries `measurement_input` (`percent|quantity|value|milestone`) — **which one the person
actually typed** — alongside `cumulative_percent`, `cumulative_quantity`, `cumulative_work_value` and
`cumulative_materials_value`. A lump-sum line is claimed as a percent, a remeasured line as a quantity, a
milestone line as nothing-or-everything; all three resolve to a value, and the value is what the
certificate sums.

Percent alone breaks the moment a variation grows a remeasured line's quantity: the stored percent is now
against a stale denominator, so the line reads 87% while the money is fully certified. Quantity alone has
nothing to say about a lump-sum line. Recording which input produced the value is what lets a later
re-derivation reproduce the person's intent.

The certificate freezes `previous_work_value` and `previous_materials_value` per line as a **snapshot of
the prior certificate**, so column D prints without joining to a certificate that may since have been
voided.

### 10.3 Every deduction is a row

`construction_certificate_deductions`: `kind`
(`retention|retention_release|advance_recovery|ncr|liquidated_damages|back_charge|contra_charge|previous_certificates|tax_withheld|unfixed_materials_adjustment|other`),
`description`, a **signed** `amount` (negative reduces the payment — one convention, stated once), a
nullable `source` morph pointing at the NCR or the back-charge or the retention movement, a nullable
`account_id` posting override carried through to the invoice line, `is_automatic`, and `approved_by`.

Generalising the bottom half of the certificate into one child table is what makes an NCR deduction
traceable to the NCR, advance recovery auditable against the contract terms, and liquidated damages a
first-class fact rather than a note in a memo field.

Statutory payment regimes are configuration on the contract, never code: `payment_terms_days` and
`certification_period_days` express FIDIC's 28 and 56 days, NEC4's much shorter periods, and — with the
notice documents of §15 — the UK Construction Act's payment notice and pay-less notice clocks. A
hardcoded statute is wrong the day it is amended, and nobody notices.

### 10.4 Where construction stops and `invoices` takes over

**Sharp boundary: construction owns everything up to and including the certificate. The certificate
produces a *draft* invoice and stops.** The precedent is verbatim in this repository —
`QuotationService::convertToInvoice()` "deliberately stops at draft… issuing stays the deliberate act it
already is", and `quotation.invoice_id` is what prevents a second conversion. Copy both.

**One invoice line per deduction group, never one per contract item.** A four-hundred-item bill would
otherwise produce a four-hundred-line invoice with uniform tax treatment, and the client's accounts
department reconciles against the certificate anyway. So a receivable certificate produces:

| Line | Amount | Account |
|---|---|---|
| Work executed to 30 Jun per IPC-007 | +30,700,000 | contract revenue |
| Less retention @ 10% (cl. 14.3) | **−2,140,000** | **retention receivable — an asset** |
| Less advance payment recovery (cl. 14.2) | −5,350,000 | advance payment received — a liability |
| Less deduction, NCR-0012 (cl. 14.6) | −850,000 | contract revenue |

**Invoice gross with retention as an asset, never net.** Retention is money earned and contractually
owed, merely not yet payable. Invoicing net understates revenue and turnover by up to a tenth for the
whole life of the job, and then makes the release invoice look like revenue recognised in a period when
no work happened — precisely the misstatement an audit looks for. `invoice_lines.account_id` exists for
exactly this, described in its own migration as the posting override for non-product lines.

**One prerequisite in another module, and it is a real latent bug rather than a nicety.**
`InvoiceService::saleEntryLines()` flips the leg for a negative line — its comment explains that booking
it as a negative credit "would be dropped by `postSystemEntry`'s filter and leave the entry short by that
amount". `purchaseEntryLines()` does **not** do this, and `postSystemEntry()` filters legs to those with
a positive debit or credit. So **a negative line on a purchase invoice is silently dropped** and the
imbalance is absorbed by the balancing fixer into whichever leg it lands on. Nothing exercises it today
because no current caller writes one; the subcontract certificate of §12 is the first thing that will.
It is a three-line change in Invoicing plus a test, and it belongs **before** the payable path is built,
not discovered during it.

Tax sits on the gross line only, which is the right treatment for retention as a timing difference in
most regimes — and is a **jurisdiction question to confirm rather than a settled one**, alongside the FBR
e-invoicing fields that then apply to a certificate invoice like any other.

### 10.5 The printed forms, under Dompdf

The engine is chosen at runtime — Browsershot where Node is present, Dompdf otherwise — and the view
receives `$pdfEngine` with per-engine override partials included in the head. So every construction form
is authored once and renders acceptably under Dompdf and beautifully under Browsershot; the fallback is a
stylesheet, never a different document.

G702 is easy: a two-column header and a nine-line numbered summary, and the existing `dompdf-invoice`
partial already solves the flex-header problem with block-level children at 49%.

**G703 is the hardest page in this plan** — eleven columns, landscape, paginating over dozens of pages —
and the answer is **server-side pagination**: `table-layout: fixed` with explicit percentage widths, the
lines chunked in PHP at roughly twenty-two rows a page, each chunk its own table inside its own
page-break div, with a *brought forward* row at the top and a *carried forward* subtotal at the bottom of
every page.

That is not a workaround; it is what a printed bill of quantities has always looked like, and it buys
four things Dompdf cannot otherwise give: `page-break-inside: avoid` on a row is unreliable and chunking
removes the dependency; `<thead>` repetition is version-sensitive and **fails silently**, so the header
simply does not appear on page two of a forty-page continuation sheet and nobody looks until the client
does; "page 3 of 7" needs no engine feature when it is printed from the chunk index, where Browsershot
would need a header template and Dompdf a canvas callback and `PdfDocument` exposes neither; and the
output is **byte-comparable across engines**, which matters because a certificate that paginates
differently depending on whether the server happens to have Node installed is a certificate that cannot
be reissued identically — and reissuing identically is the entire point of a certificate.

Beyond that: `DejaVu Sans`, which ships with Dompdf and is already used, and no webfont; long
descriptions truncated to a character budget with a continuation row, because Dompdf's `text-overflow`
support will not save you; minimal shading, because half these forms still travel by photocopier; and the
currency stated once in the column header rather than in every cell.

**One legal note that belongs in the view's docblock, not in a commit message.** AIA G702 and G703 are
copyrighted documents whose reproduction the AIA licenses. Reproduce the **content and column
structure** — which is the de facto industry information set — under our own layout, titled *Application
and Certificate for Payment* and *Continuation Sheet*, and never as "AIA Document G702". Put the reason
where the next person to make it look more like the real thing will read it first.

FIDIC prescribes the *content* of clause 14.6 and not a layout, so an interim certificate is a portrait
summary plus an annexed measurement schedule — which means **one Blade component serves both standards**,
with the column set selected by `contract_standard` and the identical chunking underneath.

## §11 Retention is a ledger, not a balance

`construction_retention_movements`: `contract_id`, `payment_certificate_id` (nullable — held movements
name a certificate, releases often do not), `kind`
(`held|released|forfeited|substituted_by_bond|reinstated|adjusted`), `stage`
(`interim|first_release|final_release|early_release`), a **signed** `amount`, `basis_gross` and
`rate_applied` snapshotted so the movement is reproducible, `cap_reached` (why a movement is less than
rate × basis), `due_on` computed at the time of writing, `released_on`, `invoice_id`,
`certificate_deduction_id`, `security_id` for a bond that substituted for cash, `reason` and
`approved_by`.

This is consistent with the computed-not-stored rule rather than an exception to it: **the balance is
still computed** as the sum of movements. What is stored is the *events* — and they genuinely are not
derivable from certificates the moment any of the following happens, all of which are ordinary: a bond
substitutes for cash; sectional taking-over releases early on part of the works, which FIDIC 14.9
explicitly contemplates; part is forfeited against uncorrected defects; the parties agree a one-off
adjustment. Those are decisions, not arithmetic.

Release rules, one function with a branch:

- **`fidic_two_stage`** — the first release fires when `practical_completion_date` is set and pays out
  `retention_first_release_pct` (50 by default) of the balance; the second fires at
  `practical_completion_date + defects_period_days`, computed and never stored, or earlier if the
  Performance Certificate is issued.
- **`aia_substantial`** — the first release at substantial completion pays the balance **less a
  punch-list holdback**, conventionally a multiple of the open value of items flagged as affecting
  completion; the remainder releases at final completion. Guarded on the field module: without it the
  holdback is zero and the release is full, **with the reason stated on the movement** rather than
  silently.
- **`single_stage` / `custom`** — one or N stages with typed percentages and trigger events.

**`RetentionService` is the only writer.** Certificates never write movements directly, and a nightly
command reconciles the sum of movements against the latest certificate's `retention_to_date` and
notifies rather than throws. Two write paths, one forgotten, and nobody reads both registers in the same
week — that is how the two figures come to disagree by an amount neither party can explain at final
account.

**NEC4 absorbs into this model later without a schema change**, which is worth knowing before someone
asks. Compensation events are variations with that origin; the quotation-and-assessment shape is already
`quoted_amount` / `assessed_amount` / `approved_amount` with the time pair beside it, and the Project
Manager's own assessment is simply an assessed amount differing from the quoted one; Option X16 retention
is a two-stage release with different percentages; the shorter payment periods are the two day-count
columns. The one thing that would not have absorbed is Option A's activity schedule, which pays only for
*completed* activities and admits no percentage — which is why `measurement_input` carries a `milestone`
value now, unused, rather than needing a migration later. What would genuinely be new is NEC4's early
warning register, and that is a small new table rather than a change to any of this.

## §12 Subcontracts, and compliance that blocks

Everything in §8–§11 with `side = payable`, plus three things that only exist downward.

**Compliance documents.** `construction_compliance_documents`: `contact_id`, a nullable `contract_id`
(some obligations are company-level and some contract-specific), `kind` — general liability, workers'
compensation, professional indemnity and contract works insurance; conditional and unconditional lien
waivers, progress and final; certified payroll; prequalification; trade licence; tax registration; bond;
safety plan; method statement; training matrix — `scope` (`company|contract|period`, because a lien
waiver is per payment period and an insurance certificate is per policy period), `period_start`,
`period_end`, **`covers_certificate_id`** (the waiver against *this* payment), `reference`, `issuer`,
`issued_on`, `effective_from`, `expires_on`, `amount_covered`, `received_on`, `verified_by`,
`verified_at`, the override trio `waived_by` / `waived_at` / `waiver_reason`, `document_id`, and
`expiry_notified_at_days`.

`construction_compliance_requirements` says which kinds are required, per contract or as a company-level
template, and what each one `blocks` (`none|certification|payment|both`) with a `grace_days`.

**Status is computed and never stored.** This is the most dangerous silent failure on the payable side: a
row with a stored `status = 'verified'` and an `expires_on` three months in the past pays a subcontractor
with no cover, and the screen says everything is fine. Derive it from the dates against the date being
asked about. `expiry_notified_at_days` is copied straight from `employee_documents`, whose own migration
explains it — *"which threshold has already been warned about, so the next run only notifies when the
answer changes"* — and the daily command follows `EmployeeDocument::daysUntilExpiry()`, which is signed
rather than clamped because "expired forty days ago" is a different problem from "expires in forty days".

**Compliance blocks certification, in the service, not the form.** Two reasons. A rule enforced only in a
Filament form is a rule that a queue job, a console command or any future API bypasses in complete
silence — and the checklist is explicit that workflow transitions belong in services with the action as a
thin wrapper. And blocking at *certification* rather than at *payment* is the difference between "you may
not yet certify this" and "here is an approved payable in the ledger that finance cannot pay" — the
second is a worse state, because the liability already exists and the stuck payment has no owner. The
override is a permission plus a mandatory reason recorded on the certificate: a system with no override
is a system people work around with a spreadsheet, and then the register is decorative.

**Back-charges.** `construction_back_charges`: the subcontract, the job, `kind`
(`materials|labour|plant|cleanup|rework|damage|ncr_rectification|schedule_recovery|attendance|welfare`),
a `source` morph to the NCR or punch item or diary entry, `description`, `amount`, `markup_percent`,
`total_amount`, `status` (`draft|notified|disputed|agreed|applied|withdrawn`), **`notified_on`** with its
notice document, the dispute fields, and `applied_certificate_id` — which is to say it becomes a
certificate deduction row of kind `back_charge`. `notified_on` matters more than it looks: almost every
subcontract requires notice before a back-charge may be deducted, and an incurred-but-unnotified
back-charge is money the company will not get and does not yet know it has lost.

## §13 Schedule, milestones and extension of time

**Store the programme; never solve it.** Build activities with baseline, planned and actual dates,
percent complete, stored predecessors, milestones and delay events. Import Primavera XER and P6 XML and
MS Project XML into the same tables, keyed on an `external_id`. Do not build a forward and backward pass,
critical path, float calculation, resource levelling or a Gantt editor.

Contract administration needs exactly four things from a programme: a milestone with a contractual date,
so extension of time and liquidated damages have something to move; planned against actual and percent
complete, so a schedule index and a look-ahead exist; somewhere to hang a delay event; and an activity
identifier that an RFI, a submittal and a variation can point at. None of those needs critical-path
calculation.

Building CPM is not the expensive part — **keeping it in step with P6 is.** Every contractor large enough
to be running FIDIC already owns P6 or Asta, and the *contractual* programme lives there because that is
what was submitted and accepted. A second scheduler here that disagrees with the submitted programme is
worse than no scheduler at all: it manufactures a number the quantity surveyor will quote in a claim and
the planner will not recognise, and the disagreement stays invisible until an adjudication. Conversely,
storing nothing means extension of time has no baseline, the look-ahead has no source, and no schedule
index can be computed. So predecessors are stored so an imported network round-trips and a look-ahead can
show what is blocking, **and no date is ever calculated from them**; `is_critical` and `total_float_days`
are *imported*, not derived, and the model's docblock says so in its first paragraph.

`construction_activities`: `job_id`, `external_id`, `source` (`manual|p6_xer|p6_xml|msp_xml|asta`),
`wbs_node_id`, `parent_id`, `code`, `name`, `activity_type`, `is_contract_milestone`, `baseline_start`
and `baseline_finish` (the *accepted* programme), `planned_start` and `planned_finish` (the current one),
`actual_start`, `actual_finish`, durations, `percent_complete`, `budgeted_value`, `total_float_days`,
`is_critical`, `responsible_contact_id`, `milestone_payment_item_id` (a milestone that gets paid),
`ld_applies` (a milestone that costs money when missed), `baseline_revision` and `data_date`.

`budgeted_value` is deliberately **not** reconciled to contract items. The programme and the bill are two
different decompositions of the same job, and forcing agreement between them produces a fiction that
somebody then has to maintain.

`construction_delay_events`: `job_id`, `contract_id`, `reference`, `title`, `description`,
`cause_category` (employer risk, contractor risk, neutral, force majeure, weather, variation, late
information, access, utility, statutory, strike, unforeseen conditions), `occurred_on`,
**`notice_required_by`** computed at creation as `occurred_on + contract notice days`, `notice_given_on`
with its document, `particulars_due_by` and `particulars_submitted_on`, `claimed_days`, `awarded_days`,
`cost_claimed`, `cost_awarded`, `status`, the determination fields, a nullable `variation_id` where the
time was granted through one, and `concurrent_with_delay_event_id` — because concurrency is the whole
argument in most extension-of-time disputes. Whether an event is time-barred is **computed** from the
notice dates, never stored.

**The notice clock is the most valuable thing in this section.** A due-date with a notification attached
is worth more commercially than the entire programme: a valid claim lost to a missed notice is the single
most common way a contractor donates money, and it fails in absolute silence. Build `notice_required_by`
and its daily notification before anything else here.

## §14 Earned value, to ANSI/EIA-748

The control account is the intersection of §2 — job, WBS node and cost code — which is where budget,
scope and actuals meet, and it is what makes earned value computable rather than decorative.

`construction_progress_measurements`: `job_id`, `wbs_node_id`, `cost_code_id`, `period_start`, `method`
(`units_completed|incremental_milestone|weighted_steps|percent_complete|level_of_effort`),
`quantity_completed`, `quantity_total`, `percent_complete`, **`earned_value`** — the budget at completion
for that element multiplied by percent complete, computed at measurement time and **frozen**, because the
budget moves when a revision is approved and last month's earned value must not — plus `measured_by`,
`measured_on` and `locked_at`.

Everything else is derived at read: `CV = EV − AC`, `SV = EV − PV`, `CPI = EV / AC`, `SPI = EV / PV`,
`ETC = EAC − AC`, `VAC = BAC − EAC`, and an estimate at completion by whichever of the three standard
methods the forecaster chose — actual plus a manual cost to complete, actual plus remaining budget where
the variance is judged atypical, or budget over CPI where it is judged typical. **The report shows which
method each line used**, because "the forecast went up" and "somebody changed the method" are different
facts and only one of them is news.

**Earned value is measured physically and never derived from cost.** If earned value comes from cost then
it equals actual cost, the cost performance index is exactly 1.00, and every job in the system reads
*precisely on budget* forever. This is the commonest way an earned-value implementation becomes
decorative, and it is a silent failure of the purest kind — every number is present and none of them
means anything.

Two measurements coexist and they are not the same thing, which is worth stating because someone will
otherwise try to merge them: a **claim line** measures a *contract item* and produces revenue; a
**progress measurement** measures a *control account* and produces earned value against budget. They are
reconciled through the WBS node they share, never by forcing one to be the other. A remeasured contract
where the two disagree is not a bug — it is the margin.

**An unphased budget cannot produce a schedule variance.** The report must say *"schedule performance
unavailable: the budget is not time-phased"* rather than showing zero. A zero that means "no data" is the
archetypal silent failure in this whole family of metrics.

## §15 Document control, to ISO 19650

ISO 19650 asks for four things: every information container has a unique identifier and a standard name;
every container carries a **suitability status** saying what it may be used for; revisions are controlled
so a superseded one is not in use; and containers move through **work in progress → shared → published →
archived** with an approval gate between the states.

`construction_documents` is the register: `job_id`, `information_container_id` (the assembled name,
unique per job), then the naming fields **each as its own column** so the identifier can be both
re-assembled and validated — `project_code`, `originator_code`, `functional_code` (volume or system),
`spatial_code` (level or location), `form_code` (drawing, specification, method statement, calculation,
schedule, model), `discipline_code`, `container_number` — plus `naming_convention_id`, `title`,
`description`, `document_type`, **`cde_state`**, `current_revision_id`, `originator_contact_id`,
`wbs_node_id`, `location_id`, `is_contractual`, `confidentiality`, and `superseded_by_document_id`.

**The naming convention is a per-project record, not a hardcoded list.** ISO 19650 mandates the *fields*;
every project issues its own code lists for what goes in them. Hardcoding the codes makes the module
unusable on job number two.

`construction_document_revisions`: `revision` (`P01`, `C02`, `A`, `3`), **`suitability_code` as a string
against a per-job code list rather than an enum** — S0–S7 and A1–A5 and B1–B5 is the UK ISO 19650-2 list,
while AIA-land issues "for construction", "for approval", "as-built", and an enum here fails on the
second project — `cde_state_at_issue`, `status`, `reason_for_issue`, `issued_on`, `issued_by`,
`received_on`, the file fields, **`file_hash`** (a sha256, which is the only reliable answer to "is this
the same drawing" and the thing that catches a re-issue with no changes), `scale`, `sheet_size`,
`drawn_by`, `checked_by`, and the approval gate — `approval_status`, `approver_id`, `approved_at`,
`approval_comments`.

`construction_transmittals` with their items and **recipients** — each recipient carrying a role of
action, information or approval, and `notified_at`, `acknowledged_at`, `acknowledged_by_name` and
`chased_at`. The acknowledgement record is the point: a transmittal nobody acknowledged is a drawing
somebody will later say they never received.

The state machine is enforced in a service, never in a form. A revision may not move to *shared* while
its approval is pending, and **published is the only state a construction-issue drawing may be in** — an
inspection, an RFI answer or a punch item referencing a work-in-progress drawing is a defect waiting to
be built.

### 15.1 What is realistic without a BIM viewer, stated bluntly

Worth building: the register, the naming fields with validation, revision history, suitability codes, the
four-state machine with its approval gate, transmittals with acknowledgement, in-browser preview of PDFs
and images (a PDF renders natively in an iframe, which is free), file hashing, and an unambiguous current
revision.

Not to be attempted here: IFC parsing, model federation, clash detection, 3D viewing, drawing markup and
redlining, DWG rasterisation, or reading a sheet number off a title block. Each is a product rather than
a feature, and each has a mature commercial answer the customer already owns.

The honest middle: a `model` document type exists so the register is *complete* — the IFC or RVT file is
stored as a container with its metadata and its hash, and people download it into whatever viewer they
have. The application never opens it, and the interface says so rather than showing a broken preview.

### 15.2 Storage, and one thing that is advisory rather than enforced

Use the existing pattern — `FileUpload` on the `public` disk with `TenantStorage`, streamed through
`TenantFileController`, which checks tenant membership and path safety. That disk is deliberately not
web-served: `config/filesystems.php` leaves the symlink mapping empty on purpose, and its comment says
why. Do not invent a second storage path.

Two deltas worth writing down rather than discovering. `TenantFileController` checks **company membership
and nothing else** — there is no per-document policy hook — so a `restricted` confidentiality on a tender
build-up or an incident investigation naming an injured person is **advisory, not enforced**: any user of
the company holding the URL can stream it. Either say so on the field's helper text, or scope a Core
change to add a policy resolver — as a Core change, deliberately, not smuggled in behind a construction
migration. And the Lifecycle precedent's 8 MB upload cap is far too small for a drawing set: make it
configuration, defaulting to something like 50 MB, and state plainly that the browser upload path is not
how a two-gigabyte point cloud arrives — those come by other means and are registered here by reference.

## §16 Field operations

### 16.1 The daily site log

`construction_daily_logs`, **unique on `(job_id, log_date)`** — the constraint is the feature, because two
site diaries for one day is how a dispute starts. Weather morning and afternoon, temperature, rainfall,
wind, `working_conditions` (`workable|partially_disrupted|stopped`) and **`weather_hours_lost`** — those
last two, not the free text, are what a weather-based extension of time is actually made of — then work,
delays, instructions received, visitors, and safety, quality and environmental observations, with
`submitted_by` and `approved_by`. **Approval locks the row.** An editable site diary is not evidence.

Its children are where the value is:

- **Manpower** by contact and trade, with headcount, hours and overtime — which feeds dayworks pricing,
  the manpower histogram, and the exposure-hours denominator of §17.
- **Plant**, with hours **working, idle and breakdown** kept apart, because idle against working is what
  a standing-time claim is made of and one combined hours column loses it entirely.
- **Deliveries**, with docket number, received-by, purchase order reference, contract item, and an
  `is_materials_on_site` flag — **the link that makes G703's "materials presently stored" column
  defensible rather than asserted.**
- **Events** — delay, instruction, visitor, stoppage, inspection, incident — each with times, hours lost
  and a responsibility of employer, contractor or neutral, and a nullable link to the delay event of §13.
- **Photos**, in their own table rather than as CDE containers. A site photo has no revision, no
  suitability code and no approval, and forcing thousands of them into the ISO 19650 register creates
  junk containers and buries the drawings the register exists for. There is a *promote to register*
  action for the handful that become as-built evidence.

### 16.2 RFIs

`construction_rfis`: `job_id`, `contract_id`, `rfi_number` (per job, no gaps), `subject`, `question`,
`proposed_solution`, `discipline`, `location_id`, `drawing_document_id` (which drawing it is about),
`specification_reference`, `activity_id` (which programme activity it blocks), `raised_by`, `raised_on`,
`required_by`, **`ball_in_court`** as a role *and* `ball_in_court_contact_id` as a person — both,
deliberately, because the useful report is "seventeen RFIs sitting with the Architect" and the useful
email goes to a named individual — `status`, **`cost_impact_flag`** and **`time_impact_flag`** as
`none|possible|yes` with nullable estimates beside them, the answer fields, and `variation_id` for the
RFI that became a change.

Flags rather than amounts at raise time, because at raise time nobody knows, and forcing a number
produces a column of zeros that later reads as "no impact" when it meant "not yet assessed".

Days open, overdue and response time are all computed.

### 16.3 Submittals

`construction_submittals`: `spec_section` (the MasterFormat section, which is the register's natural key),
`title`, `type` (product data, shop drawing, sample, mock-up, calculation, certificate, test report,
O&M manual, warranty, as-built, method statement, material approval, qualification),
`responsible_contact_id`, then the lead-time fields that are the whole point of the register —
`required_on_site_date`, `fabrication_lead_days`, `procurement_lead_days`, `review_period_days`,
`buffer_days` — plus the actual dates, `status`, `revision`, `document_id`, `activity_id` and
`is_long_lead`.

**The submit-by date is computed** by working backwards from the required-on-site date. Typed, it goes
stale the day the programme moves, and a stale submit-by date is worse than none.

`construction_submittal_reviews` is a **row per round**, with reviewer, dates, result, comments and
turnaround. A submittal that has been round three times is a schedule risk, and a single status column
loses that fact completely.

### 16.4 Punch and snag lists

`construction_punch_lists` (pre-handover, handover, defects, client, internal) and
`construction_punch_items` with reference, description, trade, location, drawing, grid reference, dates,
priority, responsible contact, before and after photographs, `cost_to_rectify`, a `back_charge_id`, and
**`affects_practical_completion`** — the flag §11's AIA holdback reads.

`construction_punch_inspections` records each re-inspection attempt with its result. "Closed after three
failed re-inspections" is a different fact from "closed first time", and a single closed-at column loses
it — the same reasoning `tickets.reopened_count` already gives in its own migration: counted rather than
inferred from status history.

### 16.5 Locations — one small tree, shared by five subsystems

`construction_locations`: `job_id`, `parent_id`, `code`, `name`, `type`
(`site|building|block|level|zone|room|grid|chainage|structure`), `sort`.

Build it. Punch items, diary photos, inspections, NCRs and incidents all need to say *where*. Five
free-text location columns is five spellings of "Level 3 East", and the report that matters most before
handover — *every open item in this room* — becomes impossible to write.

## §17 Quality, health, safety and environment

### 17.1 Inspection and test plans, to ISO 9001

`construction_itps` is the plan; `construction_itp_activities` are its rows: `sequence`,
`activity_description`, `reference_standard`, `acceptance_criteria`, `inspection_method`, `frequency`,
`record_form`, **`point_type`** (`hold|witness|review|surveillance|monitor`) and `notice_hours`.

That distinction is the entire reason an ITP exists: a **hold** point means work may not proceed past it;
a **witness** point means a party is invited and work may proceed if they do not attend; a **review**
point is documentation only. Collapsing them into a checkbox turns the document into a formality.

`construction_itp_activity_parties` is a pivot rather than a single column, because "who must attend" is
exactly the question a hold point answers, and one column cannot say *the Engineer witnesses, a
third-party laboratory verifies, the Employer approves*.

`construction_inspections` is the record: the ITP row it came from (nullable — ad-hoc inspections exist),
reference, location, activity, contract item, the `point_type` **snapshotted at request time**, the
request, notice, scheduled and inspected dates, `status`, who inspected and who witnessed and
`witness_attended`, `result_notes`, a nullable `ncr_id`, and **`released_hold_point`** with who released
it and when. The whole function of a hold point is that work may not proceed past it; a hold point that
releases nothing and blocks nothing is a checkbox with extra steps.
`construction_inspection_checks` is the check sheet — expected value, actual value, unit, pass or fail.

### 17.2 Non-conformance, CAPA and close-out

`construction_ncrs`: `ncr_number`, `raised_by`, `raised_on`, `severity` (`minor|major|critical`),
`category`, `source`, `inspection_id`, **`itp_activity_id`** — the traceability back to the ITP row that
the standard actually asks for — location, contract item, WBS node, activity, `responsible_contact_id`,
`description`, `requirement_breached`, **`disposition`**
(`rework|repair|use_as_is|reject_and_replace|concession_requested`) with a `concession_reference`, which
is ISO 9001's control of nonconforming output and is the field that decides whether money changes hands.

Then CAPA, deliberately as separate fields: `root_cause` with its `root_cause_method`,
`corrective_action` with owner and due and done dates, and `preventive_action` with its own pair.
ISO 9001:2015 dropped preventive action as a clause and every construction client's quality manual still
demands both; merging them produces NCRs whose "preventive action" restates the fix.

Close-out points at the **re-inspection** — `verified_by`, `verified_on`, `verification_inspection_id` —
which is what makes a closure evidence rather than an assertion.

Then the money: `cost_impact`, `deduct_from_payment`, `deduction_amount`, `deduction_certificate_id`,
`back_charge_id`.

**An NCR never deducts automatically.** It *proposes*; the certification service **offers** the deduction
as a row on the certificate that a human confirms and signs for. FIDIC 14.6 permits the Engineer to
withhold; it does not require it. A deduction appearing on a certificate that nobody decided on is the
fastest available route to a dispute, and it will be the contractor's dispute, because the client's copy
has already left the building. The precedent is recorded in this repository in
`ModuleBoundaryTest`'s own commentary on `final_settlements.payslip_id`: a nullable column recording
which existing path paid a settlement, which the settlement itself never writes — *a proposal, not a
posting*, visible in the import graph.

### 17.3 Incidents, to ISO 45001

`construction_incidents`: `incident_number`, `kind` — and **near miss is a first-class kind rather than a
checkbox**, because near-misses reported per lost-time injury is the leading indicator that predicts the
next one — through unsafe act, unsafe condition, first aid, medical treatment, restricted work, lost-time
injury, fatality, property damage, environmental, fire, security, dangerous occurrence and occupational
illness.

`occurred_at` is a **datetime**, not a date, because shift timing is half the analysis; `reported_at`
sits beside it and the reporting delay is computed and is itself a safety metric. Then location, the
activity being performed, `injured_person_type`, a nullable `employee_id` guarded on Employees, and
`injured_person_name` as **free text that must work on its own** — a subcontractor's labourer is not in
this system, and pretending otherwise loses the record entirely. Then `is_lost_time`, `days_lost`,
`restricted_days`, treatment, body part, injury type, agency, the cause and investigation fields, and the
authority-reporting fields, with witnesses and photographs as children.

### 17.4 One actions table for all of it

`construction_actions` is polymorphic over NCRs, incidents, inspections, toolbox talks and audit
findings: description, action type, assignee as user or contact or plain name, due date, priority,
status, completion and verification.

Every QHSE object generates the same record — somebody must do something by a date and somebody else must
verify it. Four separate action tables produce four "overdue actions" reports that never agree, and the
safety manager's one genuinely useful screen — everything overdue, from every source, in one list —
becomes a four-way union nobody maintains.

### 17.5 Permits, toolbox talks and the induction register

`construction_permits`: `permit_number`, `type` (hot work, confined space, working at height, excavation,
electrical isolation, lifting operation, road closure, live services, demolition, radiography, night
work, diving, pressure testing), description, location, activity, **`valid_from` and `valid_to` as
datetimes** — a permit is time-boxed, and an expired-but-open permit is the failure mode that kills
people — requester, number of persons, a `details` JSON for the genuinely type-specific fields (gas
readings, isolation certificate reference, rescue plan, wind limits) with the shared fields staying real
columns so they can be queried, the issue and acceptance stamps, `status`, suspension fields, and
close-out with `area_made_safe`. **An extension is a new row** pointing back at the one it extends;
overwriting `valid_to` destroys the record of what was authorised when.

`construction_toolbox_talks` with attendees, and `construction_site_personnel` as the induction and
competency register with `construction_competencies` beneath it — training, licences, certifications,
medicals, authorisations, each with an expiry and the same `expiry_notified_at_days` transition pattern.
In both tables a person's **name must work without an employee record**, because most attendees on most
sites are a subcontractor's labourers.

### 17.6 Indicators, and the denominator nobody has

Lagging indicators are computed on a report page and never stored: lost-time injury frequency rate,
total recordable incident rate, severity rate, accident frequency rate, and the near-miss ratio that
crosses into leading. **The rate base must be printed on the face of the report** — a frequency rate
without its base is a number that gets compared against a competitor's figure computed on a different
one, and 1,000,000 against 200,000 is a factor of five with both called "the standard".

Leading indicators: near-miss reports per period, toolbox talks delivered and attended, inspections
completed against planned, permits closed on time, overdue actions, induction coverage, and the
proportion of hold points released at the first attempt.

**Exposure hours are the denominator and nobody has them.** The source is the sum of daily-log manpower
hours, which means the safety indicators are guarded on the field module — and this is a genuine silent
failure rather than a graceful degradation: with no diary the denominator is zero and the frequency rate
renders as `0.00`, which reads as a perfect safety record and actually means nobody filled anything in.
The page must say **"insufficient exposure data"** and refuse to render a rate. The mirror-image failure
is double counting the same people from the diary *and* from Timesheets, which halves every rate; the job
names one source and the report prints which one it used.


## §18 The module boundary

Five registry keys, because a module here is a commercial boundary and not a folder. A contractor buying
site management, document control and safety while keeping its books in another system is a real and
common customer; so is one buying nothing but job costing. Selling this as one module quotes every
prospect for the whole thing.

```
construction             jobs and sites, locations, the WBS, the cost-code library, the
                         ISO 19650 document register and transmittals   requires: nothing

construction_costing     the job-cost ledger, budgets and forecasts, EVM, procurement and
                         commitments, labour, plant, materials, reconciliation and WIP
                                                        requires: construction, accounting

construction_contracts   the head contract and its BoQ/SOV, variations, progress claims,
                         certificates, the retention ledger, subcontracts and compliance
                                                                   requires: construction

construction_field       the site diary, RFIs, submittals, punch lists, the programme and
                         delay events                              requires: construction

construction_qhse        ITPs and inspections, NCRs and CAPA, incidents, permits to work,
                         toolbox talks, induction and competency   requires: construction
```

**`construction` requires nothing, and that is this plan's one architectural decision.** The precedent is
stated outright in the registry for CRM — *"requires NOTHING, and that is the plan's one architectural
decision… CRM owns its own `leads` table and must be sellable to a company that has bought neither
Invoicing nor Accounting"* — and the argument here is stronger, because it is what the self-contained
decision in §1 actually means. Requiring Accounting on the spine would undo it in the registry.

**`construction_costing` requires `accounting`, genuinely.** The whole of §4 is reconciliation to the
books, cost codes map to `accounts`, WIP is a journal entry and the period close posts. Job costing with
no books is a spreadsheet, and this design would be almost entirely dead code. The precedent is
`personal_finance`, which requires Accounting for a weaker reason and whose plan records that removing
the requirement was the wrong shape.

**`construction_contracts` does not require `invoicing`, and this is the sharpest fork in the section.**
The tempting precedent points the other way: `quotations` requires Invoicing because *"a quote whose whole
point is becoming an invoice, and which can never convert, is a PDF generator"*. Copying that reflexively
would be wrong, because **a payment certificate is not a quote**. A certificate is itself a contractual
instrument: it starts the payment period, the client's surveyor countersigns it, an adjudicator reads it,
and under FIDIC its issue is an obligation of the Engineer whether or not anybody raises a tax invoice.
Large contractors run certification in the commercial department and invoicing in finance, frequently on
different systems. So without Invoicing the *Raise invoice* action is absent, `certificates.invoice_id`
stays null, and the certificate register — with its retention ledger, its variation history and its
printed forms — is still the whole deliverable. The honest cost: retention held has no ledger account
until Invoicing is licensed, so the register is a contractual record rather than a financial one, which
is acceptable because retention held is a fact about the contract regardless of where the books are kept.

**The document register lives in the spine, not in the field module.** RFIs reference drawings;
submittals *are* documents; transmittals carry contract notices; ITPs reference approved-for-construction
drawings; compliance certificates and variation instructions are containers. In the field module, both
Contracts and QHSE would need it as a coupling — and worse, a customer who bought contracts and quality
but not site operations would have no register at all, which makes both of those half-useless.

### 18.1 Guarded rather than required

| Module | What it adds | Absent |
|---|---|---|
| `invoicing` | the certificate's *raise invoice* action, the supplier-bill allocation queue | certificates stop at certification; `invoice_id` stays null |
| `inventory` | site stores, stock-tracked issues, FIFO issue cost | direct-to-site costing only; issue lines carry a typed rate |
| `employees` | manager, surveyor and site agent; employee labour | `construction_workers` carries everyone and `created_by` answers ownership — exactly `crm -> employees` |
| `timesheets` | importing timesheet entries as labour records | site sheets only, which is the primary path anyway |
| `payroll` | absorbing burden against actual payroll cost | burden is charged and never absorbed, and §4 reports the gap in words rather than balancing |
| `projects` | `construction_jobs.project_id` | the field is absent and the column stays empty — exactly `invoicing -> projects` |
| `construction_field` | dayworks pricing, the AIA punch-list holdback, the safety exposure denominator | the dayworks action is absent, the holdback is zero **with the reason on the movement**, and the safety report says "insufficient exposure data" rather than rendering a rate |
| `construction_qhse` | the NCR deduction picker on a certificate | the picker is absent, and an NCR never deducts on its own anyway |

Each absent makes the module smaller and never broken, which is what `ModuleDegradationTest` exists to
assert. The two exceptions to "smaller, never broken" are named above rather than left implicit: a
zero holdback and a zero exposure denominator both *look* like healthy numbers, so both say so in words.

### 18.2 The rest of the wiring

`docs/new-module-checklist.md` is the authority and this plan restates none of it. What is specific here:

- **Directory casing: `ConstructionQhse`, never `ConstructionQHSE`.** `ModuleMap::moduleFor()` matches
  `App\Modules\([A-Za-z0-9]+)\` and runs `Str::snake()` on it, and `Str::snake('ConstructionQHSE')` is
  `construction_q_h_s_e`, which is not a registry key — so `moduleFor()` returns null. The checklist's own
  table then says what happens: a resource using the trait throws, which is loud and fine, but **a class
  with its own `canAccess()` is silently ungated, and so is `Gate::before`**. `ModuleBoundaryTest` uses
  the same regex, so the boundary lint would stop seeing the module too. Five modules, one capitalisation
  rule, decided once.
- **A `construction` company profile** in `config/company_profiles.php` — *Construction / Contracting*,
  business type, licensing `accounting, invoicing, inventory, employees, payroll, attendance, leave,
  lifecycle, crm, quotations` and all five construction keys, closed under `requires`. Without one,
  `CompanyProfileTest` fails the build, which is the point. Worth noting and **not** building yet: the
  Engineer or Architect practice that *certifies* rather than claims is already accommodated by the
  `side` enum — their fee agreement is receivable and they administer the contractor's contract as a
  third party — and is a second profile later, not a second schema.
- **A separate `ConstructionAccountsSeeder`**, not an extension of `ChartOfAccountsSeeder`. Adding a
  dozen construction accounts to every bookkeeping company's chart is noise, and the profile mechanism
  exists for exactly this. New codes: materials on site, contract assets, retention receivable, goods
  received not invoiced, accrued subcontract costs, retention payable, contract liabilities, provision
  for foreseeable losses, contract revenue, job cost by type, and the three credit-normal recovery
  accounts — plant internal hire recovery, labour burden absorbed, over/under absorption.
- **A `ConstructionAccounts` support class** copied in shape from
  `app/Modules/Accounting/Support/PayrollAccounts.php`, including its error messages, which name the
  settings page *and* the seeder because "account 5100 cannot accept entries" names neither the caller
  nor the fix.
- **Reference data is a licensing question before it is an engineering one.** MasterFormat is CSI's,
  Uniclass is NBS's, NRM is RICS's, ICMS is the ICMS Coalition's. Ship the **structure** — a tree the
  customer populates — plus a CSV import, and seed only what is genuinely publishable: the fifty
  MasterFormat division titles, an elemental UniFormat set, and the ICMS Level 2/3 spine. **Confirm the
  redistribution terms before any code list ships**, alongside the questions
  `docs/fbr-digital-invoicing-plan.md` already parks for an advisor. Ten thousand seeded rows per tenant
  is also a table nobody will ever prune.
- **`ModuleMap`** gains roughly sixty models across the five keys, every resource, page and widget, and
  the permission groups. Morph aliases take the legacy `App\Models\{Basename}` form —
  `'App\Models\ConstructionCostEntry' => …` — which `ModuleCoverageTest` asserts unconditionally with no
  exemption for classes that never lived there; the checklist §4 records this being hit for real. And
  every **plain-column** morph write goes through `ModuleMap::alias()` in a mutator, because
  `enforceMorphMap()` does not cover those: `certificate_deductions.source_type`,
  `construction_actions.actionable_type`, `variations.source_type`, `back_charges.source_type`,
  `cost_entries.source_type` and anything this suite writes into `journal_entries.source_type`.
- **Permission groups** named once and forever, because the `permissions` table has no unique index and
  the seeder matches on name *and* group — so regrouping one later creates a second row with the same
  name while existing roles keep pointing at the first, and nothing reports it. Regrouping after release
  is a data migration, not a seeder edit. Small tables ride on their parent's group, following the Leave
  precedent that eight more permission names for two tables nobody navigates to is eight more rows in
  every role form for no decision anybody makes separately. The non-CRUD names are the ones that matter,
  and each is a separate decision made by a separate person: `ConstructionCertificateCertify`,
  `ConstructionCertificateInvoice`, `ConstructionVariationPrice`, `ConstructionVariationApprove`,
  `ConstructionRetentionRelease`, `ConstructionComplianceOverride`, `ConstructionNcrClose`,
  `ConstructionInspectionRelease`, `ConstructionPermitIssue`, `ConstructionDocumentPublish`,
  `ConstructionDailyLogApprove`, `ConstructionPeriodForceClose`.
- **Navigation, and it is bigger than it looks.** `NavigationDomains::DOMAINS` has six domains and every
  group must be claimed by one or it is unreachable. Construction gets a **seventh domain** rather than a
  fold into Finance: this suite is more resources than Finance and Sales combined, and folding it in
  reproduces exactly the flat-thirteen-groups problem that class was written to solve — quite apart from
  making the module read as an accounting add-on. Groups `Construction`, `Contracts`, `Site`, `QHSE`;
  three of the four exceed `NavigationTree`'s own six-entry threshold for "a scroll rather than a menu",
  so `Contracts` branches into head contract and subcontracts, `Site` into daily, queries and close-out,
  and `QHSE` into quality and safety. `Construction` stays flat at four entries, because a branch holding
  two things is a click in front of a list rather than a shorter one. **The map must stay exhaustive**:
  an item in a mapped group that no branch claims keeps its original label, which no domain then owns,
  and it disappears from the menu — `NavigationTreeTest` is the only thing that sees it.
- **Scheduled work** in each module's `routes/console.php`, licence-guarded inside the command with model
  classes as class constants: `construction:accrue`, `construction:reverse-accruals`,
  `construction:reconcile`, `construction:close-period`, `construction:rebuild-paths`, plus the
  notification runs — compliance and competency expiry, delay-event notice due dates, retention becoming
  releasable, and the retention register reconciliation of §11.
- **`ModuleBoundaryTest::KNOWN_COUPLINGS`** gains one entry per module, and `construction_*` →
  `construction` is deliberately **not** among them: it is a declared `requires` and `allowedTargets()`
  recurses into requirements, so listing it is noise that the stale half of the test would then have to
  tolerate. Note the trap rather than discovering it —
  `test_the_recorded_debt_does_not_hide_a_licence_dependency` asserts the **exact array in exact order**
  for each module in its own guarded list, so the two cross-sibling money paths must be kept in step in
  two places. Three entries must **not** be added, and each refusal is a design constraint worth keeping:
  no `'invoicing' => [… 'construction']`, which is what forces the allocation queue of §5 to exist as its
  own screen rather than as a job picker on the invoice form; no `'core' => [… 'construction']`, because
  the account map belongs on a Construction settings page and `core -> accounting` already exists for
  having made that mistake once; and no `'accounting' => [… 'construction']`, because a job-cost summary
  widget registers in its own plugin where it belongs.

### 18.3 Scale, and the one materialised total

A five-year job is five hundred cost codes, sixty periods and a hundred thousand cost entries. The
four-column report is four grouped queries, never an accessor per row — and the trap is worth naming
because it will otherwise be written by accident: a `getStateUsing()` that sums entries per row is five
hundred queries on a five-hundred-row report. `docs/retail-stores-pos-plan.md` names the same trap and
the sharpest part of that note applies here — **the resources smoke test creates only a user, so every
table it renders is empty and it waved two 500s through.** The job cost report needs a query-budget test
that renders **with rows**, written before the page.

Both trees carry a materialised `path`, so a subtree filter is one index range scan rather than the
`childrenRecursive` pattern `Account` uses, which issues a query per depth level — fine for a two-level
chart of accounts, not fine for a six-level WBS rendered per row.

The one exception to computed-not-stored: `construction_period_cost_summaries`, written once by the
period close for a period that by §3.4's own rules can never change again. Drift is impossible by
construction rather than by discipline — and the test that makes that a claim rather than a hope picks a
random closed period, recomputes it from the entries and asserts equality.

## Phases

Each phase is shippable and leaves the application working. The order is not negotiable in three places:
Phase 0's Invoicing fix before any payable path, the ledger before anything that reports on it, and the
document register before the modules that reference drawings.

- **Phase 0 — Decide, fix, then measure.** The decisions that cannot be reversed cheaply, all of which
  now have to be made before code. The `stock_locations` fork with `docs/retail-stores-pos-plan.md` (§6),
  which is a one-way door and belongs in *that* plan's Phase 0 as much as this one's. The classification
  redistribution licensing question (§18.2). Construction as a seventh navigation domain. The directory
  casing rule. And the one genuine prerequisite in another module: **the negative-line sign flip in
  `InvoiceService::purchaseEntryLines()`, with a test** (§10.4) — a three-line change that is latent
  today and silently drops the retention line the moment §12 exists. Then a query-budget test for the job
  cost report before the page exists. **Ends with:** the answers written back into this document, and a
  failing-then-passing test in Invoicing for a bug nothing currently triggers.

  > **Started 2026-08-17. The Invoicing fix is done** — `purchaseEntryLines()` now mirrors
  > `saleEntryLines()`'s leg flip, covered by `PurchaseInvoiceNegativeLineTest` on both the base and
  > foreign-currency paths. It corrected the risk entry: the failure was a hard refusal, not a silent
  > absorption. See the Risks section.
  >
  > **All four decisions are now made (2026-08-17).** Recorded here because Phase 0's whole deliverable is
  > the answers written back into this document.
  >
  > **1. Casing: `App\Modules\ConstructionQhse`.** Verified rather than reasoned —
  > `Str::snake('ConstructionQHSE')` returns `construction_q_h_s_e`, which matches no registry key, so
  > `moduleFor()` returns null and every class with its own `canAccess()` is silently ungated.
  >
  > **2. Stock location: `stock_locations`, owned by Inventory.** Adopting §6's recommendation, and the
  > decision is *free right now* — neither this plan nor the retail one has been built, `stock_movements` has
  > no location column of any kind, and no backfill has happened, so the one-way door is still open. It will
  > not stay open: whichever plan starts first walks through it. `stock_movements.stock_location_id`,
  > backfilled once; `stores.stock_location_id` in retail and `construction_jobs.stock_location_id` here;
  > neither module depends on the other. The alternative — `store_id → stores` — makes a building site either
  > a fake store row carrying till settings and a POS registration number, or a second nullable dimension,
  > which is the on-hand-wrong-per-location-right-in-total failure §6 names. **Written into
  > `docs/retail-stores-pos-plan.md` §0.1 as well**, because that is where the migration belongs and because
  > two plans each assuming the other will raise it is how neither does. The `stock_movements.type` enum
  > expands once, in the same migration, for both plans: `issue`, `return`, `transfer`, `waste`,
  > `count_adjustment`.
  >
  > **3. Reference data: ship the structure, seed nothing proprietary.** The licensing question is *not*
  > answered here and must not be — MasterFormat is CSI's, Uniclass NBS's, NRM RICS's, ICMS the Coalition's,
  > and nobody on this side of the code can clear redistribution terms. What is decided is the shape that
  > makes the question **non-blocking**: Phase 1 ships the cost-code tree, the ICMS *mapping* columns and a
  > CSV import, and **zero seeded code lists**. A customer who owns a licence loads their own file. Seed
  > packs per standard become a later, separate deliverable, each gated on written terms for that standard —
  > so the advisor question runs in parallel with Phase 1 instead of in front of it. This also disposes of
  > the ten-thousand-rows-per-tenant objection by not creating them.
  >
  > **4. Navigation: a seventh domain.** Adopting §18.2, with the claim checked: Finance today is **24
  > classes across three groups** (Accounting 14, Invoicing & Inventory 7, Audit & Taxes 3) and Sales is 8.
  > Construction's four groups — three of which need branching — would push Finance past fifty classes and
  > seven groups, which is the flat-many-groups problem `NavigationDomains` was written to solve. The cost is
  > bounded and worth stating: `rail()` omits a domain whose groups are all empty, so a company that never
  > buys construction still sees six icons and nothing changes for it.
  >
  > The query-budget test waits on Phase 2, since there is no report to budget yet.
- **Phase 1 — The spine.** `construction` module wiring end to end — registry, profile, plugin, provider,
  `ModuleMap`, policies, permissions, the navigation domain and its branches — then jobs, locations, the
  WBS, the cost-code library with its ICMS mapping, and the ISO 19650 document register with revisions
  and transmittals. No costing, no contracts. **Ends with:** all eight `Module*` tests green before a
  single domain table carries data, and a surveyor can build a job, a work breakdown and a coded bill.
- **Phase 2 — The cost ledger.** `construction_costing`: cost entries, batches, periods, manual entry,
  reversal, and the cost report computed live. **Ends with:** cost can be recorded and reported by hand,
  and the invariant of §3.2 holds under a test that reverses and re-books.
- **Phase 3 — Budget, forecast and earned value.** Budget versions and the baseline, forecast runs,
  progress measurements, EVM, the four-column report. **Ends with:** the report that sells the module,
  and a schedule variance that says "unavailable" rather than zero on an unphased budget.
- **Phase 4 — Contracts, receivable side.** `construction_contracts`: contracts and items, variations,
  claims, certificates, deductions, the retention ledger, and the printed forms. **Ends with:** the two
  worked examples of §8.4 reproducing identically under both PDF engines — which is the assertion, not
  the illustration.

  > **Built 2026-08-18, except the invoice hand-off** — named in the status block above with the reason it
  > was left. What landed: the contract and its schedule with execution freezing the scheduled values;
  > variations with `agreed()` and `forecast()` and a source-level test asserting no bare
  > `where('status', 'approved')` survives anywhere in the module; claims and certificates as two
  > documents, cumulative, with the previous figures snapshotted rather than joined; every deduction a row
  > with retention, advance recovery and previously-certified computed; the retention ledger with the
  > release rules as one function with a branch, a zero AIA holdback that states its reason in words, and a
  > nightly reconciliation that warns rather than throwing; the printed certificate, chunked in PHP so
  > the document is the same under either PDF engine, with §8.4's two worked examples asserted against
  > their stated figures and 22,360,000 coming out of the FIDIC one; and §10.4's hand-off, which produces
  > that same 22,360,000 as a **draft** invoice of four lines — the work gross to contract revenue,
  > retention to a *receivable asset*, advance recovery against the liability it created, and the NCR
  > deduction back against revenue — with `previously_certified` deliberately not a line, because it is how
  > a cumulative certificate expresses a period figure rather than a deduction to bill.
- **Phase 5 — Procurement.** Requisitions, commitments and their variations, goods receipts, invoice
  allocations, the allocation queue screen, three-way match, relief. **Ends with:** open commitment is
  provable per cost code and closing a purchase order with a balance has an author and a reason.

  > **Built 2026-08-18.** —
  > `ConstructionCommitmentTest` (26 tests), `ConstructionRequisitionTest` (24),
  > `ConstructionGoodsReceiptTest` (21), `ConstructionInvoiceAllocationTest` (19) and
  > `ConstructionThreeWayMatchTest` (24) — **Phase 5 complete**, 114 tests. The allocation table and its queue
  > answer what §5 calls the single most likely silent failure in the module; the match is computed with only the
  > acceptance stored, and its tolerances are config overridable by settings. The commitment half is both halves
  > of the exit condition: open commitment is `line.amount − Σ reliefs` over issued orders
  > with every relief naming its cause, and closing writes a `close_out` relief with `closed_by` and a
  > mandatory reason. Three decisions worth carrying forward:
  >
  > - **Approved is not committed.** Only `issued` and `partially_relieved` put money on the four-column
  >   report, because an approved order the supplier has not been sent can still be withdrawn with a phone
  >   call. Two buttons, two permissions.
  > - **§3.5's `committed` column stopped being an em dash.** It was `null` rather than `0.00` for two
  >   phases precisely so that "no procurement module" could never be read as "no orders placed" — and
  >   filling it in was one method on `CostLedger` plus one on `ForecastService`, with no figure restated.
  >   The Phase 3 test that asserted the null now asserts the zero, and says why it changed.
  > - **Ordered-against-received is not a match variance while the order is open.** The first `ThreeWayMatch`
  >   compared them, which made every undelivered order read as a total short delivery and every staged one as a
  >   partial — a report wrong on nearly every line, which is the state that teaches people to ignore it. The
  >   two cases are genuinely indistinguishable from the documents: 36 t against 40 is a short delivery *or* the
  >   first of two loads. What settles it is closing the order, which §5 already makes an act with an author and
  >   a reason, so until then the difference is open commitment — reported once, by the register that owns it,
  >   and shown on the match as a note rather than a variance. What the match judges is invoiced against
  >   received, both in quantity and in value, which is what a supplier's own two documents can settle.
  > - **An invoice for goods that *were* received was eating the unreceived balance.** The unreceived-balance
  >   clamp is a ceiling and cannot tell what a particular invoice covers, so an invoice for the 36 t delivered
  >   was relieving the 4 t that never came, closing the order as though the shortfall had been dealt with. The
  >   intent now lives in `InvoiceAllocationService`, which knows what has been invoiced: relief is the excess of
  >   cumulative invoiced over cumulative received. Withdrawing an allocation gives back **what it actually
  >   relieved**, which is not always what it was for.
  > - **A negative invoice relief could not be recorded at all.** The unreceived-balance clamp that implements
  >   §5's double-relief rule ran on every invoice relief, so withdrawing an allocation — `min(-10m, 0)` then
  >   `<= 0 → return null` — silently gave back no commitment, leaving an order relieved for money nobody was
  >   being charged. The clamp now applies in the forward direction only. Same shape as the requisition status
  >   that could not reverse: a rule written for one direction quietly blocking the other.
  > - **The site-store path is refused rather than half-built.** A goods receipt does the two things it can —
  >   relieves the order, accrues the cost at order rate — and refuses a line destined for a store with a
  >   message naming the missing `stock_locations` and telling the user to receive it direct instead. Accepting
  >   it would cost the material as though it had been stocked, and materials-on-site would be wrong with
  >   nothing saying so, which is §18.1's rule about a healthy figure hiding an absence.
  > - **A requisition's `ordered` status has to be able to reverse.** `refreshOrderedStatus()` first treated it as
  >   terminal, which meant an order cancelled after the request was fully ordered could never put the request back
  >   on the buyer's queue — the exact failure the demand document exists to prevent, a need nobody is chasing with
  >   nothing on any screen showing it. Only `cancelled` and `rejected` are terminal; everything else is derived
  >   from the order lines and must be able to move both ways.
  > - **`Commitment::relievedTotal()` had to qualify its column.** It sums a `hasManyThrough` where both
  >   joined tables have an `amount`; SQLite refused the query outright, which was the good outcome. MySQL
  >   would have been entitled to pick either, and picking the line's amount would have made every order
  >   read as fully relieved the moment anything was received against it.
- **Phase 6 — The payable side.** Subcontracts and their terms, subcontract certificates, compliance
  documents with certification blocking, back-charges. Gated on Phase 0's Invoicing fix. **Ends with:**
  a certificate that refuses to certify against expired insurance, and an override that records who and
  why.

  > **6a built 2026-08-18 — the exit condition is met.** — `ConstructionComplianceTest` (30 tests).
  > `construction_compliance_documents` and `construction_compliance_requirements`, `ComplianceService`, the
  > block inside `CertificationService::issue()`, the override trio on the certificate, both registers, the
  > `construction:check-compliance` warning and its notification. §12's two shapes both hold: a certificate
  > against a lapsed general liability policy refuses with the document named and the lapse counted in days,
  > and the override records the user, the time and a mandatory sentence against **that** certificate only.
  >
  > Five decisions worth carrying forward:
  >
  > - **There is no status column, anywhere.** Not in the migration, not on the model, not on the register's
  >   rows. Every status is derived from the dates, against the date being asked about. §12 named the stored
  >   one as the most dangerous silent failure on the payable side and the answer was to make it unstorable.
  > - **Judged on the valuation date, never on today.** A June certificate issued in August is tested against
  >   June's cover. Asking about today would refuse a payment for work that was properly covered when it was
  >   done — a refusal nobody can act on, because the past cannot be re-insured.
  > - **Requirements are a template plus per-contract overrides**, merged with the contract winning. This is
  >   where the one real bug of the phase lived: `Eloquent\Collection::merge()` re-keys by primary key and
  >   returns `array_values`, so merging two collections keyed by `kind` threw the keys away. Every requirement
  >   then reported `missing` while its document sat in the table — the exact shape of failure §12 is about,
  >   arrived at from the opposite direction. `->toBase()` before merging, with the reason in the code.
  > - **A document is not compliance.** Unverified, insufficiently covered and out-of-period documents all
  >   exist and all fail. Filing and verifying are the same permission on purpose: split, the register fills
  >   with documents nobody has read, which is what `verified_at` exists to distinguish.
  > - **The warning exists so the refusal never has to happen.** 60/30/14/7/1 days, once per threshold,
  >   mailed to whoever maintains the register rather than whoever certifies — the person who can chase an
  >   insurer is the person who files the certificates, and mailing the approver offers them only the
  >   override. A first warning delivered as a refused certificate on payment-run day is too late.
  >
  > Two grants, deliberately split: **Accountant** files and verifies (`View`, `Update`); **Manager** holds
  > `ConstructionComplianceOverride`, which both certifies past a block and waives a requirement for good.
  >
  > **6b built 2026-08-18.** — `ConstructionBackChargeTest` (38 tests). `construction_back_charges`,
  > `BackChargeService`, the register with its state-machine actions, and the `back_charge` deduction row written
  > through `CertificationService::addDeduction()`.
  >
  > Four decisions worth carrying forward:
  >
  > - **Notice before deduction, and the un-notified list is a query.** §12's sentence — an incurred-but-unnotified
  >   back-charge is money the company will not get and does not yet know it has lost — becomes
  >   `unnotifiedTotal()`, a filter, and the sidebar badge. `apply()` refuses a draft outright and the refusal says
  >   why: deducting without notice is a payment the subcontractor can recover, so the company would have spent the
  >   money twice.
  > - **Nothing applies itself**, following §16.5's rule for NCRs. `offerFor()` returns what a certificate *could*
  >   take and a human applies one. There is a test that recomputes a certificate three times and asserts no
  >   back-charge appeared — written so a later reader does not take the absence for an oversight and wire it in.
  > - **Draft computes, notice freezes**, the same rule the certificate keeps. `recompute()` is a no-op on anything
  >   but a draft, and after notice the only way the figure moves is `agree()`, whose settled amount sits *beside*
  >   the notified total rather than over it: "notified 240,000, settled at 180,000" is the fact final account needs,
  >   and one column loses half of it. Agreeing above the notified figure is refused — that is a new charge and needs
  >   its own notice.
  > - **Un-applying returns the charge to notified, never to draft.** Notice cannot be unserved, and a charge sent
  >   back to draft would be re-notified with a later date, moving it inside a contractual window it had already
  >   left.
  >
  > **What 6b deliberately does not do is credit the job's cost report.** The cost was recorded when the company
  > incurred it and the recovery reaches the books through the certificate's invoice (§10.4). Whether the job should
  > also show the recovery against the code that carried the cost is §4's question, and §4's reconciliation is Phase
  > 11 — writing a cost credit from here as well would post the same money twice with nothing disagreeing. Stated in
  > the service docblock and in the help page rather than left as a silence.
  >
  > `ConstructionBackChargeApply` is separate from `Update` on the certificate's own asymmetry: raising and notifying
  > is the surveyor's administration, deducting is the act that gets adjudicated. `RoleGrantsTest::EXPECTED` moved to
  > 39 / 117 / 145 / 165 across 6a and 6b together.
- **Phase 7 — Labour and plant.** Workers, trades, dated rates, labour records, burden and its
  absorption, plant items and logs, internal hire recovery, and the timesheet import behind its guard.
- **Phase 8 — Materials.** `stock_locations` in Inventory, the movement-type enum expanded once for both
  plans, material issues and returns, materials on site.
- **Phase 9 — Site operations.** `construction_field`: the daily log and its children, RFIs, submittals,
  punch lists, activities, delay events, and the P6 and MS Project import. **Ends with:** the delay-event
  notice clock and its notification live before anything else in the phase, because it is the piece that
  pays for the rest.
- **Phase 10 — QHSE.** `construction_qhse`: ITPs and inspections with real hold-point release, NCRs with
  CAPA and close-out, the one actions table, incidents, permits, toolbox talks, the induction register
  and the indicators. **Ends with:** an NCR that proposes a deduction and never applies one, and a safety
  page that refuses to print a rate it cannot compute.
- **Phase 11 — Reconciliation, WIP and close.** The GL posting service, accruals and their reversal, the
  reconciliation report and its command, WIP snapshots, the period close, and the period summaries.

Phase 11 last is uncomfortable and is still right — it needs every source type to exist before it can
prove anything. But **§4's assertion test must be written incrementally from Phase 5 onward, one source
type at a time.** A reconciliation written at the end against eight source types that were built without
it in mind is a reconciliation that will not balance, and nobody will know which of the eight is wrong.

## Risks

Every risk here produces correct-looking output. That is the selection criterion: the ones that throw
will be found.

- **The two ledgers drift and nobody notices.** The structural risk of the whole plan, and the price of
  §3's decision. It does not announce itself; it accumulates. Mitigation is the whole of §4 and
  specifically the five mechanisms in §4.3 — a scheduled reconciliation, a close that refuses, an
  override with a reason, **no plug entry ever**, and a feature test per source type.
- **A purchase invoice posted with no allocation.** The general ledger is perfectly right and the job is
  under-costed, so every margin flatters and no report shows an error. The most likely silent failure in
  the module. Mitigation: an allocation queue that is a screen people work from, and an always-rendered
  section of the reconciliation report showing zero rather than being hidden.
- **Retention netted against cost instead of held as a liability.** Job cost understated by five to ten
  per cent for the life of the job, self-correcting at release, so the margin merely looks good until it
  does not. Mitigation: the full certified value is cost and the retention is a balance-sheet row, tested
  both ways, on both sides of §11 and §12.
- **Burden charged and never absorbed; internal plant charged with no recovery credit.** Same shape,
  different account: the two ledgers diverge by exactly the recovery, growing monthly, and the profit and
  loss is simply missing a credit. The fleet looks free and every job looks expensive.
- **Earned value derived from cost.** Then it equals actual cost, the cost performance index is exactly
  1.00, and every job in the system reads *on budget* forever. All the numbers are present and none of
  them means anything. Mitigation: earned value comes from a physical measurement or the page says it
  cannot be computed.
- **A provisionally priced variation counted in the certified contract sum.** One bare
  `where('status', 'approved')` that forgets `is_price_provisional`, and the certificate certifies money
  nobody agreed. Mitigation: two named scopes, and no bare status filter anywhere in the module.
- **A journal entry reversed in Accounting without its mirrored cost entry.** A cross-module hole no code
  path closes, because an accountant can do it from another panel. Only §4.2's third cause finds it.
- ~~**A negative purchase line silently dropped.**~~ **Fixed in Phase 0, and the risk was mis-stated.** It
  did not fail silently: `purchaseEntryLines()` put a negative amount in `debit_amount`, `postSystemEntry()`
  dropped the leg, and the bill threw `Entry is not balanced: debits 1000000.00 != credits 900000.00`. The
  balancing fixer never saw it — `absorbRounding()` runs inside `translateDocument()`, *before* the filter,
  and only for a foreign-currency invoice. So the bug was a hard refusal on both paths rather than a wrong
  number, which is the better of the two failures. Mirrored the leg flip from `saleEntryLines()`;
  `PurchaseInvoiceNegativeLineTest` covers base and FX, and asserts gross = net + retention.
- **A stored compliance status with a past expiry.** Pays a subcontractor with no insurance while the
  screen says everything is fine. Mitigation: status is computed from dates; only the notification
  threshold is stored.
- **A safety frequency rate of 0.00 that means nobody filled in the diary.** A perfect safety record and
  an empty denominator are the same number. Mitigation: refuse to render, print the rate base, and name
  the exposure source.
- **A voided certificate stranding the next one's `previously_certified` snapshot.** The following
  certificate quietly under- or over-pays by the difference and the total still looks plausible.
  Mitigation: a certificate may not be voided while a later one exists — the correction is a further
  certificate, which is the discipline invoices already keep with credit notes.
- **An expired-but-open permit to work.** The one risk in this document that is not about money.
  Mitigation: `valid_to` is a datetime, expiry is a status the register computes, and an extension is a
  new row rather than an edited one.
- **`stock_movements` gaining `store_id` before `stock_location_id`.** Then a building site is either a
  fake retail store or a second nullable dimension, and on-hand becomes wrong at every location and
  correct in total — the hardest class of wrong to see. ~~Mitigation: raise it against the retail plan's
  Phase 0, in writing, before either module is built.~~ **Done 2026-08-17: decided in favour of
  `stock_location_id` and written into that plan's §2.1 and Phase 2.** The risk is not closed, only moved —
  it now depends on whichever plan builds the migration honouring it, so **Phase 8 here and Phase 2 there
  are the same migration** and the second one to arrive must find the column already present rather than add
  its own.
- **`Str::snake('ConstructionQHSE')`.** Returns a key that is not in the registry, `moduleFor()` returns
  null, and every class with its own `canAccess()` is silently ungated — a construction cost report
  reachable by a company that never bought the module. Mitigation: the casing rule in §18.2 and
  `ModuleCoverageTest`.
- **A plain-column morph write without `ModuleMap::alias()`.** The fully-qualified class name goes into
  the column, and the day the class moves the query stops matching with no error at all. Five columns in
  this suite are exposed; §18.2 names them.
- **A resource in a mapped navigation group that no branch claims.** It keeps its declared label, no
  domain owns that label, and it vanishes from the menu — reachable only by URL and the palette.
  `NavigationTreeTest` is the only thing that sees it.
- **Scope creep from eighteen sections.** This document describes a construction product inside an
  accounting application, and Phases 4, 5, 9 and 10 are each the size of a plan in this folder. The
  module split of §18 is the defence: five commercial boundaries, each sellable alone, with the phase
  boundaries drawn so that stopping after any of them leaves something coherent to sell.

## Suggested first slice

**Phases 0–3.** The spine, the cost ledger, and the four-column report — budget, committed, actual,
forecast, per cost code, rolling up a work breakdown. That is the smallest thing a contractor will pay
for, it is the thing no accounting package does, and it answers the question the request opens with: what
has this job cost against what it was sold for.

Phase 4 alone is the other viable first slice, and which one comes first is a sales question rather than
an engineering one. A contractor who is losing arguments about certificates wants §8–§11 before anything
else; a contractor who is losing money quietly wants §3–§4. They do not depend on each other in either
direction, which is the point of the split.

Everything from Phase 9 onward is genuinely optional per customer. **No contractor buys all five
modules**, and the plan is built so that none of them is in the way of the others.

One honest note on size, in the spirit of the retail plan's: with field operations, document control and
QHSE in scope this is not a feature, it is a construction product inside an accounting application, and
it is the largest thing in this folder. The four-column cost report is the part that earns the rest.
