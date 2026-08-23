# More Reports, From Every Module — Plan

**Status:** Phases 0.1, 0.2, 0.5, 1.1–1.6 landed — see [What landed](#what-landed); the rest outstanding
**Created:** 2026-08-14
**Covers:** coded reports (Phases 1–3), the pane's remaining gaps (4), dashboard charts (5), a report
builder (6), per-user dashboard layouts (7), scheduled and emailed reports (8)

Goal: make the Reports hub answer questions about the whole application rather than about three
modules of it, put the same answers on the dashboard at a glance, and then let somebody assemble a
report this plan did not think of. There are 22 modules and 17 reports, and every one of those reports comes from
Accounting, Invoicing or Payroll. Seventeen modules have no report at all — attendance, leave,
timesheets, CRM, support, recruitment, quotations, inventory, lifecycle, advances, expenses,
performance, projects, campaigns, billing, employees and MPR — and several of them already contain the
computation a report would need.

That last point is what shapes the order below. The first half of this plan is not mostly about
*writing* reports; it is about *reaching* work that is already written, tested, and called from nowhere.

The order then matters for a second reason. Charts (Phase 5) are fed by the same services as the
reports, so they are cheap once those exist and dishonest if they re-derive their own figures. And the
builder (Phase 6) is bounded by a registry of what is reportable — which should be *extracted* from the
thirty reports that got built, not guessed at before any of them exist.

## Not doing

- **A BI/warehouse integration.** Every figure in this application is one query away in the same
  database. Exporting to an external tool would add a copy that can disagree with the ledger, which is
  the one thing these reports must never do.
- **Reports for modules whose data has no aggregate worth reading.** `Mpr` (one PDF per record) and
  `Core` settings are examples. A report per module is not the target — a report per *question* is.
- **A SQL console, cross-dataset joins, or a formula language in the builder.** The builder (Phase 6) is
  deliberately bounded: it assembles a report from a *declared* dataset's columns and filters, and
  nothing else. Raw SQL would walk straight through tenancy, module gating and row-level access; a join
  the registry has not declared is how a payroll figure ends up beside an unrelated employee's name; a
  formula language is a programming language with no tests. A question needing two subjects at once is a
  coded report, and Phase 6.7 says how to tell.
- **Charts inside the builder.** Phase 5 owns charts and Phase 6 owns rows and totals. A built report
  that could also draw itself as a pie chart would need an axis model, a colour story and a legend on
  top of everything else in Phase 6, and the two features would then disagree about what a period is.
- **Free-form dashboard resizing.** Phase 7 lets a widget be moved, hidden, and set to one of three
  widths. Arbitrary pixel resizing needs a grid engine, a breakpoint story and a migration for every
  widget that ever changes shape; three widths need none of that and answer the same complaint.
- **Report delivery anywhere but email.** SFTP, Drive, S3 and webhooks are each a credential store and a
  failure mode. Phase 8 emails, and links to the live report; anything else is a later plan.
- **Per-recipient row filtering in a schedule.** "Send each manager only their own team's rows" sounds
  like one option and is a rendering pass per recipient with a re-authorisation each time. Phase 8 sends
  one rendered report to a list, authorised as one owner — see Phase 8.2, which is the whole security
  model. Per-recipient scoping is a different feature and should be named as one.

## Facts verified against this codebase (not assumed)

**Report-grade computation exists and is unreachable.** Each of these is implemented, has tests, and
is called from no page, widget, or command that a user can reach:

- `GeneralLedgerService::generalLedger($from, $to)`
  (`app/Modules/Accounting/Services/GeneralLedgerService.php:119`) — **no caller in `app/`,
  `resources/` or `tests/`**. Only `accountLedger()` (line 14) is used, by the single-account register.
- `Crm\Services\PipelineReports` — six aggregates: `byStage()` (:36), `forecast()` (:64),
  `winLoss()` (:95), `rotting()` (:130), `activity()` (:171), `attainment()` (:199). The only
  consumer in `app/` is `SalesTargetResource`. The forecast is covered by
  `tests/Feature/CrmPipelineTest.php:302` including the rule that a stored rate does not move.
- `Support\Services\TicketService::breaches()` (:120) and `performance($from, $to)` (:138) — no
  callers. Both are tested (`tests/Feature/CrmSupportAndCampaignTest.php:187`).
- `Timesheets\Services\TimesheetService::utilisationFor($employee, $year, $month)` (:147) and
  `planVersusActual()` (:184) — no callers. Both tested (`tests/Feature/TimesheetTest.php:264,280`).
- `Lifecycle\Services\DocumentExpiryCheck::due()` (:26) — one caller, the console command
  `Lifecycle/Console/Commands/CheckDocumentExpiry.php`. So expiries are emailed and cannot be listed.
- `Accounting\Services\LoanService::generateSchedule()` (:58) — rendered only inside one loan's
  `ScheduleRelationManager`; there is no portfolio view.
- `Accounting\Services\ScheduledTransactionService::due()` (:64) / `outstandingFor()` (:79) and
  `SubscriptionBillingService::due()` (:50) — used by their own runners, by no report.
- `Inventory\Services\InventoryValuationService::onHand()` (:16), `stockValue()` (:25),
  `averageCost()` (:39) — used by `InventoryService` and `InvoiceService` when posting, never to
  answer "what is on the shelf and what is it worth".

**The explorer machinery is now generic, so a report is cheap.** `ReportPane` draws four shapes —
`statement`, `ledger`, `table`, `file` (`app/Modules/Accounting/Support/ReportPane.php:57`) — and a
report declares its own filters (`ASKS`, :84), its own grid and numeric columns, and its own record
row (`footer`). Adding one is: a page class, one line in `Reports::SECTIONS`
(`app/Modules/Core/Filament/Pages/Reports.php:77`), one line in `ReportPane::KINDS`, and one adapter
method. The month picker, the `?asOf=` date, the URL round-trip and the sticky record row come for
free.

**Two existing tests make a half-added report fail rather than hide.**
`ReportsHubTest::test_every_page_hidden_from_the_sidebar_is_linked_from_the_hub` fails for a page that
sets `$shouldRegisterNavigation = false` without a hub entry, and
`ReportPaneTest::test_every_report_in_the_hub_is_drawn_in_the_pane` fails for a hub entry with no
`KINDS` line. This is the guard rail the phases below rely on: neither of them has to be remembered.

**Module gating is per page and already uniform.** Every report page uses `BelongsToModule` and gates
on `moduleIsAvailable()` plus `ReportView` (e.g.
`app/Modules/Payroll/Filament/Pages/TaxSummary.php:42-49`), and `Reports::sections()` filters through
each page's own `canAccess()`. A report for an unlicensed module therefore disappears from the hub,
the sidebar column and the pane with no further work — and `ReportsHubTest` already proves it for
payroll.

**The dashboard is Filament's stock page with no widgets of its own, and each module discovers its
own.** `AdminPanelProvider` registers `Filament\Pages\Dashboard::class` (:208) and passes
`->widgets([])` (:210); every widget arrives through a plugin's `discoverWidgets()` (e.g.
`app/Modules/Accounting/AccountingPlugin.php:30`). So a dashboard filter has to come from replacing
that page with one of ours — no widget can be told about a period that the page does not hold.

**The widget conventions to follow already exist.** Five widgets ship today: `StatsOverviewWidget`
(`AccountBalancesOverview`, `OperationsOverview`), `ChartWidget` (`CashFlowChart`,
`PayrollByEmployeeChart`) and a plain `Widget` (`ReceivablesPayablesOverview`). Every one uses
`App\Filament\Concerns\WidgetBelongsToModule` and defines its own `canView()` — the trait's comment
records why: widgets gate on `canView()`, not `canAccess()`, so a disabled module's charts otherwise
keep rendering. Two of the five set `$isLazy = true`; the other three do not, and on a dashboard of
fifteen widgets that difference is the page's load time.

**`FilamentWidgetsSmokeTest` names its five widgets literally**, exactly as
`FilamentReportPagesSmokeTest` names its eight pages. Both want to enumerate the panel instead, or every
widget added below renders in nobody's test until somebody remembers it.

**Drag-and-drop needs no new dependency.** `filament/support` bundles SortableJS and exposes it as an
Alpine directive — `x-sortable`, `x-sortable-item`, `x-sortable-handle`, with an `onEnd` hook
(`vendor/filament/support/resources/js/sortable.js:1-25`). `package.json` carries only `laravel-echo`,
`puppeteer` and `pusher-js`, so Phase 7 adds nothing to it and reuses the directive Filament's own
reorderable tables use.

**Scheduling in this application is per module, per tenant, and already has a shape to copy.**
`routes/console.php` says scheduled work belongs to the module that owns it and carries its own licence
guard; `app/Modules/Projects/routes/console.php` is the worked example — one `everyMinute()` entry that
dispatches only the rows whose own interval says they are due, rather than a schedule entry per row.
Commands are `TenantAware` (spatie multitenancy) so `handle()` runs once per company, and
`App\Console\Concerns\SkipsDisabledModules` skips the companies without the module. Both need cron
*and* a queue worker, and that file says so out loud because the failure is otherwise silent.

**Rendering a document and emailing it is already done once, correctly.**
`App\Notifications\PayslipIssued::toMail()` renders the PDF and attaches it with `attachData()`
(:68-70), through a templated mail when the company has a template. `PayslipDeliveryService::send($payslip,
$resend)` owns the decision to send, and `payslips.sent_at` is what stops a second copy going out
(`2026_08_03_140000_add_sent_at_to_payslips_table.php`). Phase 8 is that pattern with a schedule in
front of it — and the queue timeouts it depends on are already ordered correctly in `config/queue.php`
(`retry_after` 360 > worker timeout 300 > PDF timeout), which is the fix that stopped duplicate payslip
emails.

**A saved-view mechanism already exists, and it is most of the builder's persistence.**
`App\Filament\Concerns\HasSavedViews` saves a list page's filters, columns, sort, search and grouping
as a named `TableView` — favourited, shared (`is_public`, `is_global`), with the user's default applied
on mount, scoped to the company by a global scope, and keyed on `ModuleMap::alias()` so a view survives
its resource moving between directories (`app/Modules/Core/Models/TableView.php:20-34`). What it cannot
do is make a *report*: no totals that reconcile, no period, no comparison column, no record row. Phase 6
is that gap, not a new persistence layer.

**The fiscal year is 1 July – 30 June and periods must go through `ReportPeriod`.**
`ReportPeriod::toDate()`/`previous()`/`months()`
(`app/Support/Reporting/ReportPeriod.php` — it was in Accounting until Phase 1.2 moved it) exist because
`Carbon::startOfYear()` reported six months of trading as twelve. Every new period report uses them; none
calls `startOfYear()`.

**The data behind the new reports, confirmed in the tenant schema:**

| Report needs | Table / columns |
|---|---|
| Payroll register | `payslip_pay_components` (payslip_id, pay_component_id, amount) — `2026_08_04_130000_create_pay_component_tables.php` |
| Attendance register | `attendance_days` (status, worked_minutes, overtime_minutes, late_minutes) — `2026_08_13_101000` |
| Leave liability | `leave_entitlements` (opening/accrued/carried_in days), `leave_days.settled_payslip_id` — `2026_08_12_141000`, `2026_08_13_160000` |
| Unbilled WIP | `timesheet_entries` (minutes, is_billable, approved_at, locked_at, project_id) — `2026_08_13_120000` |
| Stock valuation | `products` (reorder_level, valuation_method, inventory_account_id), `stock_movements` (remaining_quantity, total_cost) — `2026_07_16_120000` |
| Asset register | `fixed_assets` (purchase_cost, accumulated_depreciation, salvage_value, useful_life_months, disposed_at) — `2026_07_15_100123` |
| Bank reconciliation | `bank_statements`, `bank_statement_lines`, `journal_entry_lines.reconciled_at` — `2026_07_15_120000-120002` |
| SLA compliance | `tickets` (opened_at, first_responded_at, resolved_at, reopened_count, satisfaction_rating), `ticket_categories.sla_*_minutes` — `2026_08_13_190000` |
| Hiring funnel | `applications.stage`, `interviews.outcome`, `offers` (issued_at, responded_at, status) — `2026_08_13_140000` |
| Quote conversion | `quotations` (valid_until, accepted_at, invoice_id, supersedes_id) — `2026_08_13_180000` |
| Assets in hand | `issued_assets` (returned_on, value, fixed_asset_id) — `2026_08_13_130000` |
| Advances outstanding | `advances` (total_amount, monthly_instalment, status), `advance_recoveries` — `2026_08_01_140000` |
| Revenue by project | `invoices.project_id` — `2026_08_07_120000` |
| Consent register | `consents` (subject_type/id, channel, state, recorded_at) — `2026_08_13_200000` |

## Phase 0 — The shared groundwork

Nothing here ships a report; it removes the friction from the thirty-odd that follow.

1. **A new section for the hub.** *Done, 2026-08-23 with Phase 1.2.* `Reports::SECTIONS` has six sections
   and the new reports do not fit them: "People & payroll", "Operations", "Sales & pipeline" are the three
   the list below wants. Section order is the reading order in both the hub and the sidebar column, so
   decide it once — which is why all three are declared empty in `ReportCatalogue` rather than appearing
   when a module happens to boot.
2. **A `matrix` kind in `ReportPane`.** *Answered 2026-08-23 with Phase 1.4, and the answer is no kind —
   see [What landed](#what-landed).* Three of the highest-value reports (payroll register,
   attendance register, plan-versus-actual) are an *employee × column* grid with a totals row and a
   totals column, which `table` can render but not total per column beyond one footer row. Either
   extend `table` with a `column_totals` flag or add a kind. Decide before writing the payroll
   register, because it is the shape three reports share.
3. **A `period` filter alongside `month`.** Several new reports are read for a month *or* a quarter
   (`ASKS` currently offers account/budget/search/month). Add `period` — from/to through
   `ReportPeriod` — rather than letting each report invent its own date pair.
4. **Extend `ReportPaneTest`'s coverage loop** to assert every new report's payload shape as they land;
   it already loops the catalogue, so this is free once the reports are registered. *Confirmed free,
   2026-08-23: the five CRM reports arrived in that loop with no change to it. Its "not empty" floor was
   raised from 17 to 23 to match, so a report that stops being registered fails it too.*
5. **Make `FilamentReportPagesSmokeTest` enumerate the hub rather than a hand-written list.** It names
   eight page classes literally, so a new report page renders in nobody's test until somebody remembers
   to add it. `Reports::linkedPages()` is the list it should loop — one change, and every report added
   below arrives with a render test. Do this before Phase 1, not after.

## Phase 1 — Surface what is already computed

Highest value per hour of work in this plan, because the computation and its tests exist. Each item is
a page + hub entry + pane adapter, with **no new business logic**.

1. **General Ledger** — `generalLedger($from, $to)`, rendered as the `ledger` kind: every account,
   its entries in date order, opening → movement → closing. The one report an auditor asks for first,
   and the only reason it is missing is that nothing ever called the method. Drill-through to the
   account register already exists (`ReportPane::drillable()`).
2. **CRM pipeline set** — *done, 2026-08-23.* Five reports off `PipelineReports`: *Pipeline by Stage* (`byStage`),
   *Sales Forecast* (`forecast`, weighted at the stored rate), *Win/Loss* (`winLoss`), *Rotting Deals*
   (`rotting`, a table of opportunities with days since last activity), *Target Attainment*
   (`attainment`). Five reports, one service, no new logic. `activity()` belongs in the same section
   as a sixth if the owner filter is worth exposing.
3. **Support SLA & Performance** — *done, 2026-08-23.* `TicketService::performance($from, $to)` as the report and
   `breaches()` as its exception list: response and resolution against each category's
   `sla_*_minutes`, by assignee, with reopened count and satisfaction. Both methods are tested and
   unused.
4. **Timesheet Utilisation** — *done, 2026-08-23.* `utilisationFor($employee, $year, $month)` across every employee for a
   month: billable, non-billable, capacity, percentage. `planVersusActual()` is the second report, and
   the `matrix` decision from Phase 0.2 applies to both.
5. **Documents Expiring** — *done, 2026-08-23, though not off `due()` — see [What landed](#what-landed).* `DocumentExpiryCheck::due()` as a table: employee, document kind, number,
   expiry, days remaining. Compliance-critical and currently only ever emailed.
6. **Loans Outstanding** — *done, 2026-08-23.* `LoanService::generateSchedule()` aggregated across loans: principal
   outstanding, interest to come, the next twelve months' instalments. Footed against the loan
   liability accounts.
7. **Cash Commitments (90 days)** — `ScheduledTransactionService::due()/outstandingFor()`,
   `SubscriptionBillingService::due()` and recurring invoices in one forward-looking table: what will
   hit the bank, when, and whether it is already raised. Nothing in the application answers this today.

## Phase 2 — The reports that reconcile to the ledger

These are the ones that catch real errors, and they are the reports this application can produce that
a standalone HR system cannot: the ledger is in the same database, so each report can *prove* itself
against the accounts. Every one of them carries a record row that ties to a ledger balance — which is
the assertion its test should make, not the row count.

1. **Payroll Register** — employee × pay component for a month, with a total per component and per
   employee, footed against the payroll journal for that run. The single most-asked-for payroll report;
   today only per-payslip views exist. Component definitions carry `account_id`, so the reconciliation
   is per column rather than in aggregate.
2. **Leave Liability** — unused entitlement × daily rate per employee, as at a date. This is an
   accrual that belongs in the accounts and is currently in nobody's figures. `LeaveBalance::for()`
   gives the breakdown; `LeaveYear::windowFor()` gives the window, which differs per employee on an
   anniversary basis and is exactly the kind of thing a hand-built report gets wrong.
3. **Unbilled WIP** — approved billable timesheet entries with no billing run, by project and
   customer, at their rate. A balance-sheet figure that is invisible today; also the report that shows
   revenue being lost to unbilled time.
4. **Stock on Hand & Valuation** — per product: quantity, average cost, value, reconciled to that
   product's `inventory_account_id`; plus below-`reorder_level` and no-movement-in-N-days as flags on
   the same rows rather than as separate reports.
5. **Fixed Asset Register & Depreciation Schedule** — cost, accumulated depreciation, net book value
   per asset, reconciled to the asset and accumulated-depreciation accounts, with the next twelve
   months' charge from `DepreciationService`'s own method. A standard note to the accounts.
6. **Bank Reconciliation Statement** — statement balance → unpresented cheques and deposits → ledger
   balance, from `BankReconciliationService::ledgerBalance()` and `reconciled_at`. Asked for at every
   year end.
7. **Employee Advances Outstanding** — advances less recoveries per employee, with the instalment and
   the months remaining. A receivable from staff; feeds final settlement, so a wrong figure leaves the
   company out of pocket.
8. **Expense Claims** — by status, employee and period; reimbursed through payroll versus pending,
   where the pending total is an accrued liability.

## Phase 3 — Operational reports, per module

Lower value each than Phase 2 but cheap, and each is the report the people who use that module ask
for. Ordered by how often that has come up.

1. **Monthly Attendance Register** — employee × day grid with present/leave/absent, and LOP, late and
   overtime totals per employee. `AttendanceMonth` already computes `paidDays()`, `lossOfPayDays()`
   and `overtimeHours()` per employee, so this is the company-wide aggregation of an existing figure —
   and the same figure payroll prorates on, which makes disagreement between the two visible.
2. **Hiring Funnel & Time to Hire** — applications by stage per vacancy, offer acceptance rate, days
   from applied → offer → joining, and open-vacancy ageing.
3. **Quotation Conversion** — issued → accepted → invoiced with win rate, plus quotes expiring inside
   14 days (`valid_until`) and superseded versions excluded from the rate.
4. **Revenue by Customer / Project / Product** — one report with a dimension filter, gross and net of
   credit notes. `invoices.project_id` exists and nothing reports on it.
5. **Credit Notes Issued** — a tax-sensitive list with commissioner approval status; only visible
   per invoice today.
6. **Headcount Movement & Turnover** — joiners and leavers per month from `employee_job_history` and
   `employees.leaving_date`, with turnover percentage and average tenure.
7. **Assets in Employees' Hands** — `issued_assets` not returned, by employee, with value; ties to the
   asset register and to settlement recovery.
8. **Final Settlements** — composition per leaver: notice recovery, encashment, gratuity, advance and
   asset recoveries, net.
9. **Onboarding / Offboarding Progress** — checklist items overdue by owner role, from
   `employee_checklist_items.due_on`.
10. **Consent Register** — consent state per contact per channel with source and date. This is
    compliance evidence, not marketing statistics, which is why it belongs with the reports.
11. **Campaign Performance** — sends, failures and reasons per campaign.
12. **Review Cycle Progress** — reviews and goals complete per cycle, one-to-ones held.
13. **Environment Health & Incidents** — checks failed and incidents per project over a period; the
    existing widgets are point-in-time and this is the history.

## Phase 4 — What the pane still lacks

Deferred from the 4c work and worth doing once the catalogue is larger, because each one pays off per
report:

1. **Export the open pane** — PDF and CSV. Twenty new reports make "I need this in a spreadsheet"
   twenty times more likely. One implementation on the pane rather than per report — and the one Phase 8
   sends, which is why that phase waits for this one rather than growing a second renderer.
2. **Comparison periods beyond the previous year** — previous month, previous quarter, budget.
3. **Negatives in parentheses**, and a company-wide preference for it. Accountants read `(1,250)`.
4. **Keyboard navigation of the report list** — arrow keys and type-ahead, once the list is 35+ rows.
5. **A saved view per report** — the filters somebody uses every month, kept. The URL already carries
   the whole state, so this is storage rather than plumbing.

## Phase 5 — The dashboard

The reports answer "show me the rows and prove the total". The dashboard answers "how are we doing",
and today it answers it for accounting, invoicing, payroll and projects only — nothing on the screen
knows about people, pipeline, support or utilisation.

**The rule that makes this safe: a widget is fed by the same service as its report.** Not a second
query that happens to agree. `PipelineReports::forecast()` behind the forecast chart *and* the forecast
report; `TicketService::performance()` behind both the SLA chart and the SLA report. A widget and a
report that disagree about a number is worse than either alone, because the person who spots it cannot
tell which to believe — and this is a real risk here, not a hypothetical: the aggregates already exist
and it would be quicker to re-derive each figure inline.

1. **A dashboard page of our own**, replacing `Dashboard::class` in `AdminPanelProvider`, carrying a
   **period filter** (this month / this quarter / financial year to date / a custom range through
   `ReportPeriod`) in the URL, so a dashboard someone links to opens on the period they meant. Widgets
   read the page's filter; none of them keeps its own idea of "now". Where the retail plan lands, the
   store scope is the second filter on the same page (`docs/retail-stores-pos-plan.md` §7).
2. **People** (Employees, Attendance, Leave): headcount with joiners and leavers this month; present /
   late / on leave today; leave requests awaiting a decision; documents expiring in 30 days (the same
   `DocumentExpiryCheck::due()` as Phase 1.5).
3. **Sales** (CRM, Quotations): pipeline by stage as a funnel; weighted forecast against target
   attainment; quotations expiring inside 14 days. All three off `PipelineReports` and
   `QuotationService`.
4. **Service** (Support, Timesheets): SLA compliance this month with breaches outstanding now; billable
   utilisation this month; unbilled WIP value. Off `TicketService::performance()`/`breaches()` and
   `TimesheetService::utilisationFor()`.
5. **Money** (Accounting, Invoicing): revenue against expenses over twelve months; the five largest
   debtors with days overdue; cash committed in the next 90 days (Phase 1.7's own figures).
6. **Inventory**: stock value, count below reorder level, and — once Phase 2.4 exists — the same
   valuation the report states, from the same service.
7. **Every widget**: `WidgetBelongsToModule` plus its own `canView()` gating on module *and*
   permission; `$isLazy = true` without exception, so the dashboard renders and the panels fill in;
   `$sort` set deliberately so the order is money → sales → service → people rather than discovery
   order — that order becomes the company default a user may depart from in Phase 7 — and no polling.
8. **A cache for the expensive ones**, with the tenant in the key. `docs/page-load-performance-plan.md`
   is explicit about the failure mode here — caching across requests without the tenant in the key is a
   cross-tenant leak — and a five-minute TTL on a twelve-month revenue series is the difference between
   a dashboard and a report that runs fifteen times a day per user.
9. **Guards**: extend `PanelPerformanceTest` with a dashboard query ceiling (it is the most-loaded page
   in the panel), and make `FilamentWidgetsSmokeTest` enumerate the panel's registered widgets rather
   than a hand-written list of five.

## Phase 6 — The report builder

**Last on purpose.** Its whole safety and most of its usefulness come from a *dataset registry*, and the
right way to arrive at that registry is to extract it from the thirty reports Phases 1–3 actually
built — the columns that keep recurring, the filters people keep needing, the periods that matter.
Designed before those exist, it would be a guess about which questions people ask, and the guess would
be encoded in a schema that saved reports then depend on.

What it is: a screen where somebody assembles a report from a **declared** dataset — pick columns,
filters, a grouping, what to total — saves it under a name, shares it, and reads it in the same pane as
every built-in report, with the same record row and the same export.

1. **The dataset registry** (`App\Support\Reporting\Dataset`), one declaration per reportable subject:
   journal lines, invoices and their lines, payslips and their components, employees, stock movements,
   timesheet entries, tickets, opportunities, leave days. Each declares its label, its **module**, the
   **permission** it needs, its base query *through the Eloquent model* so every global scope and
   tenancy applies, and then the columns (label, type, whether it groups, whether it aggregates, how it
   resolves) and the filters it offers. **The registry is the boundary**: no raw SQL, no table it has
   not named, no relation it has not declared.
2. **Row-level access is inherited, not re-implemented.** The base query goes through the same access
   filters the resources use (`EmployeeAccess`, and `StoreAccess` if retail lands), so the builder can
   never be the way around scoping. The test that matters: a non-privileged user building a report over
   payslips sees their own rows and their downline's, and nobody else's.
3. **Definitions stored like saved views**, deliberately: `report_definitions` with `company_id`,
   `user_id`, `name`, `description`, `dataset`, a `state` json (columns, filters, group by, aggregates,
   sort, period), `is_public`, `is_global`, `is_default`, `icon`, `color` — the same shape as
   `table_views`, including normalising the dataset key through `ModuleMap::alias()` so a class that
   moves does not break saved reports. `HasSavedViews` is the working example of every one of those
   decisions.
4. **Rendered through `ReportPane`.** A built report is a `table` (or the `matrix` of Phase 0.2) with a
   `footer`, so it inherits the sticky header, the record row, the URL state and Phase 4's export
   without knowing they exist. It appears in the hub in a **Custom** section beside the coded reports,
   which is also the answer to "where do I find the one I made".
5. **Cost guards, stated rather than discovered.** A mandatory period filter or an explicit row cap;
   `LIMIT` enforced on the rendered query; aggregation pushed into SQL rather than grouping a hundred
   thousand rows in PHP; and a refusal — "this report asks for too much, narrow the period" — in place
   of a timeout. A builder is the one feature in this plan whose cost the *user* chooses, so the
   ceiling has to be the application's.
6. **Permissions**: `ReportBuild` to create and share, `ReportView` still governs reading, and sharing
   `is_global` needs an administrator permission of its own. A company-wide custom report over payslips
   is a payroll leak, and it is one careless toggle away.
7. **What it does not do** is in Not doing above, and the sharpest one is worth repeating here: a
   question that needs two subjects joined is a coded report. The builder's answer to it is a clear
   refusal, not a join it cannot secure.

## Phase 7 — Per-user dashboard layouts

Phase 5 gives everybody the same dashboard in a deliberate order. This makes that order the *default*
rather than the only arrangement — a bookkeeper wants receivables first, a store manager wants stock, and
neither wants to scroll past the other's charts every morning.

The design decision that makes this survivable is the first item, and everything else follows from it.

1. **A layout is a partial override, never a list of widgets.** Store an *order* map and a *hidden* set,
   then resolve: take the widgets this user may see, apply the order to the ones named, append anything
   the layout does not mention. A stored array of "the widgets I have" means every widget added after a
   user saved their layout is invisible to them forever, and that is precisely how layout features come
   to be hated — the person who arranged their dashboard is the person who never sees a new chart.
2. **A company default plus a personal override.** An administrator sets the arrangement everyone starts
   from; a user may depart from it and reset back to it. This keeps what was valuable about one shared
   dashboard — a company where nobody can be told "look at the third chart" has lost something — while
   letting people who use one module all day put it first.
3. **Stored in the `table_views` shape**, because that shape has already been argued out:
   `dashboard_layouts` with `company_id`, `user_id` (null = the company default), a `state` json (order,
   hidden, spans) and timestamps, company-scoped by a global scope. Widgets are keyed on
   `ModuleMap::alias()`, not on the class name, for exactly the reason `TableView::setResourceAttribute()`
   does it (`app/Modules/Core/Models/TableView.php:20-27`) — a widget that moves between directories must
   not orphan every saved layout.
4. **A layout can never reveal a widget `canView()` refuses.** Resolve the visible set *first*, then
   order it. Said explicitly because the tempting implementation — read the layout, instantiate what it
   names — is a module-gating bypass that would survive the module being switched off. Unknown keys are
   dropped on read, and a hidden widget that the user has lost access to is simply gone.
5. **Widths, not resizing:** one of half / two-thirds / full per widget, mapped to `$columnSpan`. Three
   choices need no grid engine and answer the actual complaint, which is that a stats row does not
   deserve the same space as a twelve-month chart.
6. **The interaction is Filament's own.** `x-sortable` with a drag handle on each widget header,
   persisting on `onEnd` through a Livewire call — SortableJS is already bundled in `filament/support`, so
   this adds no dependency and behaves like the reorderable tables people already use here.
7. **Guards.** A test that registers a *new* widget and asserts it appears for a user who has a saved
   layout (item 1's regression, and the one that matters); a test that a widget whose module is disabled
   stays absent even when a layout names it; and a reset that restores the company default. An admin
   action to push the default to everybody is worth having and must ask first — it discards arrangements
   people made.

## Phase 8 — Scheduled and emailed reports

The reports and the dashboard both require somebody to come and look. This sends the report to the people
who would otherwise ask for it: the aged receivables every Monday, the payroll register the day after a
run, the SLA summary on the first of the month.

Cheap by this point, and only by this point: Phase 4 renders the export, Phase 6 stores the definition,
and the delivery pattern already exists (`PayslipIssued` + `PayslipDeliveryService` + `sent_at`).

1. **What a schedule is.** `report_schedules`: the report — a coded report's key *or* a Phase 6 definition
   — its filter state as json (the same state the URL carries, so "the schedule" and "the link" are the
   same thing), a period rule (`this month`, `last month`, `financial year to date`), a format
   (PDF / CSV / both), a cron expression with a timezone, recipients, `is_active`, and the owner.
2. **The security model, which is the whole of this phase.** An emailed report leaves the application's
   authorization behind: nobody has to log in to read it, and nothing in the app records who saw it. So —
   **the render runs as the schedule's owner**, whose access decides what the rows are; **the recipient
   list is re-authorised at send time, not at schedule time**, because a person whose role changed or who
   left the company is the ordinary case and the schedule would otherwise keep posting to them for years;
   **external recipients need their own permission** and are recorded on every delivery. A schedule whose
   owner loses access to the report is suspended, not silently rendered with fewer rows.
3. **Periods go through `ReportPeriod`.** "Monthly on the 1st" for a company whose year starts 1 July is
   exactly the case that made `ReportPeriod` necessary, and the resolved period is also the idempotency
   key in item 4 — so getting it wrong is not a cosmetic error but a double send.
4. **One delivery per period, whatever the queue does.** `report_deliveries` with
   `unique(schedule_id, period_key)`, plus status, rendered_at, sent_at, recipient list and error. This is
   the `payslips.sent_at` / `SubscriptionBillingService::alreadyBilled()` pattern, and it is not optional:
   a queued render that exceeds its timeout is retried by design, and without this the retry emails the
   report a second time.
5. **The schedule entry is one line, per module.** A `reports:deliver` command in the reports module's
   own `routes/console.php`, `TenantAware`, `SkipsDisabledModules`, running every fifteen minutes and
   dispatching only the schedules whose cron says they are due — the `CheckEnvironmentsHealth` shape, so
   a thousand schedules still need one entry. It needs cron and a worker, and the file should say so.
6. **Rendering is Phase 4's export in a job**, with the PDF engine's existing per-engine template
   overrides. The queue timeouts are already ordered correctly in `config/queue.php`; a report large
   enough to exceed them is a report to cap, not a timeout to raise.
7. **The email**, through `EmailTemplate` where the company has one, with the file attached exactly as
   `PayslipIssued` does it — **and a link to the live report in the body**, so a recipient who wants to
   drill in lands in the application and is authorised there. A size cap, with the attachment replaced by
   a link when it is exceeded: a 40 MB PDF does not fail in this application, it fails at somebody's mail
   server, hours later, silently.
8. **A delivery log people can read** — a report of the reports: what went out, to whom, when, and what
   failed. Retries are bounded and the owner is notified after repeated failure, because a scheduled
   report that quietly stopped arriving is worse than one that was never set up: everybody assumes the
   silence means nothing happened.

## What landed

**2026-08-23 — the loan book (Phase 1.6), and the first report that reconciles.**

- **The schedule was rendered in exactly one place**, the relation manager inside a single loan, so a
  company could read any one amortisation table and could not answer "what do we owe". The plan's words:
  "there is no portfolio view."
- **It states what the schedules say beside what the accounts say, and names the gap.** Phase 2 is the phase
  of reports that reconcile and its rule is stated there — "post entries, run the report, assert the record
  row equals the ledger balance … a row-count assertion proves nothing here". This is a Phase 1 item that
  can already make that claim, because the schedule and the ledger are in the same database, so it makes it.
  Two tests: one posts a drawdown and two instalments and asserts the two figures are *identical*; the other
  posts a 50,000 repayment by hand and asserts the report notices and names it.
- **That difference is the report's most valuable figure**, which is why the second tile is the ledger
  balance rather than a note. Schedules and liability accounts drift for real reasons — an instalment paid
  outside the application, a manual entry, a loan restructured without rebuilding its table — and the report
  cannot know which side is right, so it states both. Agreement is also said out loud, because two
  similar-looking numbers with no comment invite a reader to decide for themselves whether it matters.
- **`GeneralLedgerService::balancesFor()` is new and batched.** `balanceAsOf()` is two queries for one
  account — the shape that cost the general ledger 136 queries before Phase 1.1 rewrote it — and there is no
  reason to reintroduce it one report at a time. The instalments are one query for the whole book too:
  `scheduledOutstanding()`, `totalInterest()` and `nextDue()` are a query each and stay for the per-loan
  screen, where the count is one.
- **Registered through `ReportRenderers` rather than as another arm of `ReportPane`'s `match`**, which is how
  Accounting's older eleven are drawn. The newer path gives the page, the date and the module gate for free
  and keeps the pane from growing a method per report; `for()` asks `ReportRenderers` first, so both routes
  reach one closure.
- **Three faults in my own fixtures, each of which would have made a test pass while proving nothing:**
  `recordInstalment()` posts only where a second approver is *not* required, and this environment requires
  one — so the schedule advanced while the ledger stood still and the report was right to say they
  disagreed; the record row was compared exactly against a column of individually-rounded cells, which
  cannot tie to the paisa (the footer states the true total, because a footer agreeing with the screen and
  disagreeing with the ledger is the wrong one to be right about); and the gating test ran as an
  Administrator, who has `ReportView`, so it asserted that an open gate was shut.
- Worth knowing and stated in the help: an **inactive** loan is off the report *and* its liability account
  is out of the comparison, so a deactivated loan with a balance still in the accounts will not show as a
  difference here.


**2026-08-23 — Documents Expiring (Phase 1.5), built on a method that had to be written first.**

- **This phase says "`due()` as a table" and `due()` is the wrong method**, which is worth recording because
  the mistake would have shipped looking correct. `due()` is the *notification* query: it suppresses a
  document once its threshold has been warned at, so a daily job does not mail the same warning for thirty
  days. A report built on it shows fewer documents the more reliably the reminders go out — emptiest on the
  company that has been most diligent, and silent about why. `DocumentExpiryCheck::expiring()` is the
  listing: everything inside the window, warned about or not. The first test in
  `LifecycleReportsTest` asserts both halves — that the reminder query is silent for a row the report
  still shows — so the distinction cannot quietly collapse later.
- **The window is the widest configured reminder threshold**, not a number in this file. So the report
  covers exactly the population the mail watches, and a company that widens
  `lifecycle.document_expiry_thresholds` widens both at once instead of owning a report that disagrees with
  its own email. The status bands are named off the same config, so a company warning at 90 days reads a
  *Within 90 days* band.
- **Expired documents are listed, counted in their own tile, and stated as an overdue count.** `-50` and
  `50 ago` are the same figure with opposite readings, and the bare negative invites the wrong one. An
  expired visa is also not a warning that stops being true, which `due()` already says about its smallest
  threshold and a listing has even less excuse to drop.
- **Two absences stated rather than blanked:** a document with no number recorded reads *Not recorded*,
  because a blank cell in a compliance list looks like a fault in the report; and the document kind is
  humanised, because `cnic` is a column value and not something to read.
- Worth knowing and stated in the help: this report can only be as complete as what has been entered. A
  document that *should* carry an expiry and has none recorded appears nowhere, and no report can find it.


**2026-08-23 — the timesheet reports (Phase 1.4), and Phase 0.2's `matrix` question answered.**

- **Phase 0.2's answer is that no `matrix` kind is needed.** That phase proposed one on the grounds that
  `table` "can render but not total per column beyond one footer row". Looking at it, `table` already does
  both halves: `columns` is an array, so the columns can be data, and the footer row *is* the per-column
  total — a totals column on the right is one more column the report computes. A second kind had nothing to
  add and would have been a second place to fix a drill-through or a sticky header.
- **What `table` genuinely could not do was be wider than the screen.** `.fi-explorer-statement` is
  `overflow: clip`, for its rounded corners, so a twelve-project matrix was **silently cut off** — no
  scrollbar, no hint, columns simply absent. That is the bug behind the request. `ReportShapes::table()`
  takes a `wide` flag, the shared partial wraps a wide table in a scroll container, and every wide report
  in Phases 2 and 3 gets it for free. Declared rather than measured, and conditional, because the wrapper
  re-parents `position: sticky`: inside it the header and record row stop following the reader down the
  page. A matrix trades that for columns that exist; a two-column report should not pay it.
- **Both methods answered for one employee, which is what made them unreachable rather than merely
  unused.** The question anybody asks is about the team, and asking it of `utilisationFor()` meant a loop
  over a method that reaches `AttendanceCalendar::summarise()` — which walks every day of the month doing a
  holiday lookup and a shift-pattern lookup per day. The bulk methods are three and four grouped queries
  whatever the headcount, and the test measures it: **6 queries against 953** for ten employees over three
  projects, when mutated back to the loop. That is the risk this plan named for these reports, quantified.
- **The plan asked for a capacity column and this module refuses to state one, so the report does not.**
  `utilisationFor()` returns `expected_hours` as null in every branch, and its own comment says why: a rule
  that made timesheets and attendance reconcile "would make people book the difference somewhere to make
  the screen agree, which produces worse data than the gap it closed". A report is exactly where an
  invented denominator would be read as fact. *Billable share* — what proportion of recorded time was
  billable — is the ratio the data supports, and it assumes nothing about what a month should have held.
  The report's columns are asserted, because that is where a capacity figure would have to appear.
- **The matrix shows every pairing either side knows about**, not only the ones where both exist: an
  allocation with no hours booked against it is the most interesting row on the report, and hours booked
  against a project nobody was assigned to is the second. A report showing only the agreeing pairings would
  always agree with itself.
- **The column cap says what it dropped, and the row total does not.** Twelve project columns, busiest
  first; the note names how many quieter projects lost their column. But the *Booked* column on the right
  totals every project including those — a capped report whose totals add up only the visible columns
  disagrees with the timesheet it came from and looks entirely right, which is why it has a test of its own.
- **`is_billable` is filtered loosely on purpose.** It comes back as 1/0 from MySQL and true/false from
  SQLite; a strict comparison reported every hour as non-billable on one of the two drivers.


**2026-08-23 — the SLA report and its exception list (Phase 1.3).**

- **Both methods were implemented, tested and unreachable**, which is the clearest case of this plan's
  premise in the application: an SLA that is measured and never shown is a commitment nobody can be held
  to. `Support\Support\SupportReports` turns them into *SLA Performance* — met against missed for the
  month — and *SLA Breaches*, the open tickets that have missed one or are about to.
- **A report and an exception list rather than two reports.** The first is read at a month end and quoted
  in a review; the second is read every morning, because everything on it is still fixable. Separate
  screens for that reason and not because the data differs.
- **`TicketService` gained a second grouping and two aggregates, and no clock arithmetic.**
  `performance()` grouped by category only, and the plan asked for assignee, reopenings and satisfaction
  as well. `performanceByAssignee()` shares one private aggregator with it; both return the same row
  shape. The category rows come first on the report and the assignee rows second, deliberately: the
  commitment belongs to the category — the only thing in the schema carrying an `sla_*_minutes` figure —
  so a rate per person is a diagnosis and not a ranking, and somebody working the urgent queue is measured
  against a tighter clock than somebody on the general one.
- **Two groupings in one table is an arithmetic trap, and the test is the guard.** The assignee rows are
  the same tickets again, so a total summing every row doubles every figure and still looks like a total.
  The record row adds up the category rows alone; mutating it to sum both fails four tests.
- **Three absences stated rather than defaulted:** no ratings is a dash, because an average of nothing
  reported as 0 says a team was hated when it was never asked; a month with no tickets says so rather than
  reporting nought per cent met; and an unassigned ticket in breach is named and counted in its own tile,
  because it is the worst row in the table and a blank cell reads as missing data.
- **The window is measured on `opened_at`, and that is the load-bearing choice.** An SLA is the promise
  made when a ticket arrives. Measured on resolution, every still-open ticket drops out of every month's
  figures — so the report would improve as the backlog got worse, which is the most dangerous direction
  for a service measure to lie in. Mutating the column to `resolved_at` fails six tests.
- **Both reports say "reported, not enforced" on their own faces.** The module's rule is that the clocks
  are measured and nothing acts on them, and a percentage that looks like a penalty invites somebody to
  close tickets in order to improve it. Asserted, not just written.
- Time is frozen in the test, because every breach figure is measured against `now()`: a report over a
  past month shows every ticket in it as catastrophically overdue — correctly, and uselessly for a test.

**2026-08-23 — the CRM pipeline set (Phase 1.2), Phase 0.1's sections, and the two couplings that were in
the way.**

- **Five reports, one service, and no figure computed twice.** `PipelineReports` had carried `byStage`,
  `forecast`, `winLoss`, `rotting` and `attainment` with tests since CRM shipped, called by nothing but
  `SalesTargetResource`. `Crm\Support\CrmReports` is the adapter — it chooses each report's period, states
  its figures and says what they mean, and computes none of them. The service gained two filters and no
  arithmetic, for the reason four bullets down.
- **Each report's period is derived from the one date the pane carries, and each derives a different one.**
  *By stage* and *rotting* are snapshots and take no window. *Forecast* looks **forward** to the end of that
  month, because a forecast of a period that has closed is a win/loss report. *Win/loss* looks **back**
  across the fiscal year through `ReportPeriod` — 1 July, not 1 January, and the test puts a deal in the
  month where the two answers differ. So Phase 0.3's `period` filter is still not needed, and still
  outstanding.
- **`ReportPeriod` moved to `app/Support/Reporting/`.** CRM using it from Accounting would have bought a
  `crm -> accounting` edge for a date pair, and `crm` requires nothing by design (`crms-plan.md` §1). It
  imports Core and Carbon only, and Support, Timesheets and Lifecycle would each have bought the same edge
  for the same reason in 1.3–1.5. Same call `ReportShapes` got in packaging §8, one class along.
- **`NoReportPane` refused every report, including the ones it could draw.** It is the pane a company with
  no accounting module gets, and it answered `false` to `supportsReport()` unconditionally — so a CRM-only
  company saw the five reports listed in the hub and could open them one page at a time and never in the
  explorer. It now consults `ReportRenderers`, which is host-level, and still refuses everything of
  Accounting's. Exactly the coupling `ReportPane::supports()` shed a fortnight ago, in the class nobody
  looked at next.
- **A report page is now four declarations.** `TaxSummary` and `GeneralLedger` are ~110 lines each, and
  five more of those differing in a title and an icon was four copies too many:
  `App\Support\Reporting\ModuleReportPage` holds the date, the key, the payload and the gate, and each of
  the five declares a title, an icon, a sort and its own `HelpAction` literal — the last of those because
  `HelpCoverageTest` reads each page's own source, and is right to.
- **The page and the pane draw one payload through one set of partials.** The pane's tiles and table markup
  are now `filament/partials/report-tiles` and `report-table`, included by the hub and by the shared page
  view both. The payload equality is asserted per report, and so is the sharing of the markup — a page free
  to render its own footer would pass the first assertion while showing a different report.
- **`PipelineReports` gained two filters, and no arithmetic.** The forecast's stage rows were the whole
  open pipeline sitting under a total for one month — a reader adding the Weighted column would have got a
  different figure from the tile above it, and every other number on the page would then be in doubt. So
  `byStage()` takes an optional closing window and `forecast()` an optional pipeline, and the report reads
  one pipeline and one window through both halves. That is a filter on an existing aggregation rather than
  a new figure, which is the line Phase 1's "no new business logic" is drawing; shipping rows that do not
  add up to their own total would have been the plan's own "plausible number that is wrong". The report is
  footed now, so the column and the tile are asserted equal rather than hoped equal.
- **Three defects the tests found, all of them mine and all invisible to a passing render:** the rotting
  list read `->name` on a model whose column is `title`, so every deal was listed as a blank; three tiles
  omitted `accent` and took the page down with an undefined key; and the forecast named its currencies only
  when it had collected two distinct codes, which is silent on the case that matters — one converted deal
  among a page of local ones, where the local ones store no code at all.
- **The help doc claimed row-level scoping that does not exist.** CRM uses `EmployeeAccess` to filter the
  owner *picker* on the lead form and nowhere else, so these reports show every deal in the company. The
  doc now says that, and says the consequence: somebody who should not see the whole pipeline should not
  have `ReportView`.
- **Phase 0.1 done:** *Sales & pipeline*, *People & payroll* and *Operations* are declared in
  `ReportCatalogue`, empty, after the six financial sections. Empty ones are dropped, so a company without
  CRM sees no heading rather than an empty one — and the order is a decision rather than a consequence of
  `bootstrap/providers.php` order.

Not done from Phase 0: the `matrix` kind and the `period` filter. Neither was needed for these five; each
should still land with the first report that needs it. Phase 0.4 turned out to need nothing — the five
arrived inside `ReportPaneTest`'s existing loop, as it predicted.

**2026-08-16 — the General Ledger (Phase 1.1), and the smoke-test fix from Phase 0.5.**

- **`GeneralLedgerService::generalLedger()` had to be rewritten before it could be surfaced.** Phase 1's
  premise is "no new business logic", and that held for the figures but not for the access pattern. The
  method looped every account calling `accountLedger()` — two aggregate queries for the opening balance
  plus one for the lines — costing **136 queries against the 44-account seeded chart to return two
  ledgers**, nearly all of it discarded by the filter at the end. A real chart is 200+ accounts. It is now
  one query for the period's lines, one grouped query for openings, and the arithmetic in PHP: **6
  queries, same result.**
- **The equivalence test is the specification, and it found its own limit.** `GeneralLedgerTest` asserts
  the batched result equals `accountLedger()`'s account by account and line by line. But the rewrite left
  both paths sharing one `ledgerFor()`, so flipping the sign convention leaves that test **green** —
  proved by mutation, not assumed. The two direction tests carry what equivalence cannot: an opening
  balance that must not appear as a line, and a credit-normal account that must climb on a credit.
- **The `ledger` kind is now general.** It hard-coded three columns for the trial balance, which is why
  the general ledger could not reuse it. A ledger now states its own `grid` and `numeric` and its rows are
  cells, exactly as a table does — one renderer for both, and the trial balance's payload changed shape.
- **Two things tried and reverted on looking at the render:** the account code on every line (already in
  the section heading, and it landed in the *date* column), and a per-line drill into that account's
  register (circular — the general ledger *is* that drill-down).
- **Phase 0.5 done:** `FilamentReportPagesSmokeTest` named eight page classes literally; it now enumerates
  `Reports::linkedPages()`, so every report added from here arrives with a render test and a permission
  test. Verified by mutation — breaking the new page fails that test by name.

Not done from Phase 0: the hub sections for the new reports, the `matrix` kind, and the `period` filter.
None was needed for this report; each should land with the first report that needs it.

## Risks

- **A report that disagrees with the accounts is worse than no report.** Everything in Phase 2 states
  a figure that also exists in the ledger, and the failure mode is a plausible number that is wrong.
  Mitigation, and it is not optional: each Phase 2 report's test asserts the reconciliation — post
  entries, run the report, assert the record row equals the ledger balance for the accounts behind it.
  A row-count assertion proves nothing here.
- **Per-row queries.** These reports are loops over employees, products and projects, and this is
  exactly how `/payroll-runs` and `/tax-rates` came to run ~100 queries a page. The lesson from that
  fix applies: aggregate in the query (`withSum`, `withCount`, one grouped query), and pin it with a
  test that renders **with rows** — `FilamentResourcesSmokeTest` creates only a user, so every table
  it renders is empty and waved both 500s through.
- **Reports that need an unlicensed module.** A cross-module report (revenue by project needs
  Projects; unbilled WIP needs Timesheets *and* Billing) must gate on *every* module it reads, not
  just the one it lives in. `BelongsToModule::moduleIsAvailable()` checks one; these want an explicit
  check per dependency, and `ModuleGatingTest` is where that belongs.
- **The sidebar column is now 17 links and will be 35+.** It currently fits without scrolling at
  1000px, which was the point of the flyout work. Past ~24 links per domain it will not, and the
  answer is the category rows becoming collapsible rather than the column scrolling: a navigation
  column that scrolls hides its own contents, which is the reason the rail flyouts break into columns
  at a row budget instead (`app/Support/NavigationDomains.php:282-290`).
- **A widget that disagrees with its report.** The single most damaging outcome in Phase 5, and the most
  likely, because re-deriving a figure inline is quicker than routing a widget through a service. Once a
  chart and a report disagree, every other number on both screens is in doubt. Mitigation: each widget's
  test asserts its headline figure **equals** what the corresponding report states, off the same
  fixture — not that it renders.
- **The dashboard becomes the slowest page in the panel.** Fifteen widgets, each a few aggregates, on
  the page everybody opens first. `docs/page-load-performance-plan.md` fought this exact battle over
  eleven sidebar badge counts. Mitigation is not optional here: `$isLazy` on everything, a query ceiling
  in `PanelPerformanceTest`, and a tenant-keyed cache on the twelve-month series. And note the leak that
  plan warns about — a cache key without the tenant is cross-tenant data.
- **The builder as a way around access control.** This is the risk that would matter most and it is
  entirely a design question, answered in Phase 6.1–6.2: the registry declares what is reportable, and
  the base query runs through the model, so scopes, tenancy and row-level filters apply by construction
  rather than by remembering. A `whereRaw` anywhere in that path is the bug to review for. The test
  suite should include an *attempted* leak — a non-privileged user's report over payslips — rather than
  only the happy path.
- **The builder as an unbounded query.** A user can ask for every column of every journal line since
  inception. Phase 6.5's caps are the answer, and the honest part is refusing rather than timing out:
  the failure has to arrive as a sentence about narrowing the period.
- **A saved layout hiding new work.** The failure mode of Phase 7 if item 1 is got wrong: a user who
  arranged their dashboard in March never sees a chart added in June, and nobody notices because it looks
  normal to them. The test that registers a new widget and asserts it appears for a user with a saved
  layout is the mitigation, and it should be written before the feature.
- **A layout resurrecting a widget the user may not see.** Reading the layout and instantiating what it
  names is the obvious implementation and it bypasses `canView()`, which is where module gating and
  permissions live. Resolve visible-first, always; a test with a disabled module and a layout that names
  its widget is the proof.
- **An emailed report is an authorization bypass with no audit trail.** The sharpest risk in this plan.
  Once a PDF is in a mailbox it can be forwarded to anyone, and the application will never know. Phase 8.2
  is the mitigation and it is a design rather than a checkbox: render as the owner, re-authorise recipients
  at send time, an explicit permission for external addresses, and every delivery recorded. Reviewers
  should treat any change that loosens one of those four as a security change.
- **Double sending.** A render that exceeds its timeout is retried by design — that is exactly the
  mechanism that once sent payslips twice, and the fix was ordering `retry_after` above the worker
  timeout. Phase 8.4's unique key per period is the second half of that lesson, and the test asserts a
  replayed job sends nothing.
- **Silent schedule death.** Cron stops, the worker dies, an owner loses access — and the report simply
  does not arrive, which everybody reads as "there was nothing to report". Health checks already run every
  minute for the installation; a schedule that has missed its window should surface in the delivery log
  and notify its owner rather than waiting to be noticed.
- **Custom reports outliving their datasets.** A saved definition references columns; a later refactor
  renames one; the report breaks quietly for whoever saved it. `table_views` has this exposure today and
  handles it by aliasing the resource. Definitions want the same treatment plus a validation pass on
  read: an unknown column is dropped with a notice, never silently ignored.
- **Scope.** Thirty-odd reports, a dashboard and a builder are not one piece of work. Phase 1 is self-contained and ships value on
  its own; Phase 2 items are individually shippable; Phase 3 can be picked over indefinitely. Nothing
  here needs to land as a set.

## Suggested first slice

If only one phase is taken: **Phase 1**, in the order listed. Seven reports (eleven counting the CRM
set individually) with no new business logic, all of it already tested, and the General Ledger alone
answers a question the application currently cannot.

If a second: **Phase 2 items 1, 4 and 5** — payroll register, stock valuation, asset register. Those
three are the ones an accountant asks for by name at year end, and all three reconcile.

**Phase 5** can be taken at any point after Phase 1 and is the most *visible* work in this plan — the
dashboard is the first screen anybody opens, and it currently says nothing about people, pipeline or
service. It is also the cheapest way to get value from Phase 1 twice, since every chart is fed by a
service that a report already uses.

**Phase 7 is small and can follow Phase 5 immediately** — it is one table, one resolver and Filament's
own sortable directive. **Phase 8 should follow Phase 4**, because it renders what Phase 4 exports; taken
before that, it would grow a second rendering path that then has to be unified.

**Phase 6 should not be pulled forward.** Not because it is hard, but because its registry is the
distilled form of everything Phases 1–3 teach about which columns and filters people actually need.
Built first, it is a guess with a schema attached, and saved reports are the worst place to keep a
guess.
