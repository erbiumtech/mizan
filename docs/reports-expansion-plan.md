# More Reports, From Every Module — Plan

**Status:** Phases 1–4 complete; Phase 5's five widget groups all landed (5.1–5.6, plus the 5.9 enumeration); 5.7, 5.8 and the 5.9 query ceiling, then Phases 6–8, outstanding. Phase 0's `period` filter (0.3) is **superseded** — 5.1's `DashboardPeriod` is that filter, on the page that needed it.
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
7. **Cash Commitments (90 days)** — *done, 2026-08-23.* `ScheduledTransactionService::due()/outstandingFor()`,
   `SubscriptionBillingService::due()` and recurring invoices in one forward-looking table: what will
   hit the bank, when, and whether it is already raised. Nothing in the application answers this today.

## Phase 2 — The reports that reconcile to the ledger

These are the ones that catch real errors, and they are the reports this application can produce that
a standalone HR system cannot: the ledger is in the same database, so each report can *prove* itself
against the accounts. Every one of them carries a record row that ties to a ledger balance — which is
the assertion its test should make, not the row count.

1. **Payroll Register** — *done, 2026-08-23.* Employee × pay component for a month, with a total per component and per
   employee, footed against the payroll journal for that run. The single most-asked-for payroll report;
   today only per-payslip views exist. Component definitions carry `account_id`, so the reconciliation
   is per column rather than in aggregate.
2. **Leave Liability** — *done, 2026-08-23, and it is the one Phase 2 report that ties to nothing — see [What landed](#what-landed).* Unused entitlement × daily rate per employee, as at a date. This is an
   accrual that belongs in the accounts and is currently in nobody's figures. `LeaveBalance::for()`
   gives the breakdown; `LeaveYear::windowFor()` gives the window, which differs per employee on an
   anniversary basis and is exactly the kind of thing a hand-built report gets wrong.
3. **Unbilled WIP** — *done, 2026-08-23.* Approved billable timesheet entries with no billing run, by project and
   customer, at their rate. A balance-sheet figure that is invisible today; also the report that shows
   revenue being lost to unbilled time.
4. **Stock on Hand & Valuation** — *done, 2026-08-23.* Per product: quantity, average cost, value, reconciled to that
   product's `inventory_account_id`; plus below-`reorder_level` and no-movement-in-N-days as flags on
   the same rows rather than as separate reports.
5. **Fixed Asset Register & Depreciation Schedule** — *done, 2026-08-23, and it is the one report in
   Phases 1 and 2 that needed new business logic — see [What landed](#what-landed).* Cost, accumulated
   depreciation, net book value per asset, reconciled to the asset and accumulated-depreciation accounts,
   with the next twelve months' charge from `DepreciationService::schedule()`. **The sentence above used to
   say "from `DepreciationService`'s own method", and the service had no such method** — every method it had
   posted journal entries, so the forecast had to be written before the report could be. A standard note to
   the accounts.
6. **Bank Reconciliation Statement** — *done, 2026-08-23, and it found that this application cannot
   complete the reconciliation it describes — see [What landed](#what-landed).* Statement balance →
   unpresented cheques and deposits → ledger balance, from `BankReconciliationService::ledgerBalance()` and
   `reconciled_at`. Asked for at every year end. **"Asked for at every year end" assumed a report over
   completed statements, and it cannot be one:** `complete()` requires the statement balance to equal the
   ledger balance exactly, so a statement carrying an unpresented cheque can never be closed and a closed one
   has nothing to reconcile. The report is about the open ones.
7. **Employee Advances Outstanding** — *done, 2026-08-24.* Advances less recoveries per employee, with the instalment and
   the months remaining. A receivable from staff; feeds final settlement, so a wrong figure leaves the
   company out of pocket.
8. **Expense Claims** — *done, 2026-08-24.* By status, employee and period; reimbursed through payroll versus pending,
   where the pending total is an accrued liability.

## Phase 3 — Operational reports, per module

Lower value each than Phase 2 but cheap, and each is the report the people who use that module ask
for. Ordered by how often that has come up.

1. **Monthly Attendance Register** — *done, 2026-08-24.* Employee × day grid with present/leave/absent, and LOP, late and
   overtime totals per employee. `AttendanceMonth` already computes `paidDays()`, `lossOfPayDays()`
   and `overtimeHours()` per employee, so this is the company-wide aggregation of an existing figure —
   and the same figure payroll prorates on, which makes disagreement between the two visible.
2. **Hiring Funnel & Time to Hire** — *done, 2026-08-24.* Applications by stage per vacancy, offer acceptance rate, days
   from applied → offer → joining, and open-vacancy ageing.
3. **Quotation Conversion** — *done, 2026-08-24.* Issued → accepted → invoiced with win rate, plus quotes expiring inside
   14 days (`valid_until`) and superseded versions excluded from the rate.
4. **Revenue by Customer / Project / Product** — *done, 2026-08-24, as three groupings rather than a filter — see [What landed](#what-landed).* One report with a dimension filter, gross and net of
   credit notes. `invoices.project_id` exists and nothing reports on it.
5. **Credit Notes Issued** — *done, 2026-08-24.* A tax-sensitive list with commissioner approval status; only visible
   per invoice today.
6. **Headcount Movement & Turnover** — *done, 2026-08-24. The column is `left_on`, not `leaving_date`.* Joiners and leavers per month from `employee_job_history` and
   `employees.leaving_date`, with turnover percentage and average tenure.
7. **Assets in Employees' Hands** — *done, 2026-08-24. Both ties are real; the value column *is* the settlement recovery — see [What landed](#what-landed).* `issued_assets` not returned, by employee, with value; ties to the
   asset register and to settlement recovery.
8. **Final Settlements** — *done, 2026-08-24. Lists leavers rather than settlements, which is what makes an unbuilt one visible — see [What landed](#what-landed).* composition per leaver: notice recovery, encashment, gratuity, advance and
   asset recoveries, net.
9. **Onboarding / Offboarding Progress** — *done, 2026-08-24, as a progress report rather than an overdue list, because two of its three findings are never late — see [What landed](#what-landed).* checklist items overdue by owner role, from
   `employee_checklist_items.due_on`.
10. **Consent Register** — *done, 2026-08-24. The "not marketing statistics" clause was the specification — see [What landed](#what-landed).* consent state per contact per channel with source and date. This is
    compliance evidence, not marketing statistics, which is why it belongs with the reports.
11. **Campaign Performance** — *done, 2026-08-24. The skip count is the report; one metric was deliberately not built — see [What landed](#what-landed).* sends, failures and reasons per campaign.
12. **Review Cycle Progress** — *done, 2026-08-24. "Complete" turned out to be the whole question — see [What landed](#what-landed).* reviews and goals complete per cycle, one-to-ones held.
13. **Environment Health & Incidents** — *done, 2026-08-24, and the history is thirty days long — see [What landed](#what-landed).* checks failed and incidents per project over a period; the
    existing widgets are point-in-time and this is the history.

## Phase 4 — What the pane still lacks

Deferred from the 4c work and worth doing once the catalogue is larger, because each one pays off per
report:

1. **Export the open pane** — *done, 2026-08-24. One grid from three shapes; the CSV deliberately undoes the display formatting — see [What landed](#what-landed).* PDF and CSV. Twenty new reports make "I need this in a spreadsheet"
   twenty times more likely. One implementation on the pane rather than per report — and the one Phase 8
   sends, which is why that phase waits for this one rather than growing a second renderer.
2. **Comparison periods beyond the previous year** — *done, 2026-08-24, except budget, which `BudgetVsActual` already is — see [What landed](#what-landed).* previous month, previous quarter, budget.
3. **Negatives in parentheses** — *done, 2026-08-24. Applied in the views, never in the CSV — see [What landed](#what-landed).* and a company-wide preference for it. Accountants read `(1,250)`.
4. **Keyboard navigation of the report list** — *done, 2026-08-24. The search box is the type-ahead; the rows stay buttons — see [What landed](#what-landed).* arrow keys and type-ahead, once the list is 35+ rows.
5. **A saved view per report** — *done, 2026-08-24. The date is deliberately not saved — see [What landed](#what-landed).* the filters somebody uses every month, kept. The URL already carries
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

1. *done, 2026-08-24 — and it cost the reports hub's icon column, see [What landed](#what-landed).* **A dashboard page of our own**, replacing `Dashboard::class` in `AdminPanelProvider`, carrying a
   **period filter** (this month / this quarter / financial year to date / a custom range through
   `ReportPeriod`) in the URL, so a dashboard someone links to opens on the period they meant. Widgets
   read the page's filter; none of them keeps its own idea of "now". Where the retail plan lands, the
   store scope is the second filter on the same page (`docs/retail-stores-pos-plan.md` §7).
2. *done, 2026-08-25 — and a fixture found a real bug in one of the widgets, see [What landed](#what-landed).* **People** (Employees, Attendance, Leave): headcount with joiners and leavers this month; present /
   late / on leave today; leave requests awaiting a decision; documents expiring in 30 days (the same
   `DocumentExpiryCheck::due()` as Phase 1.5).
3. *done, 2026-08-24 — and the funnel is the one widget the period must not filter, see [What landed](#what-landed).* **Sales** (CRM, Quotations): pipeline by stage as a funnel; weighted forecast against target
   attainment; quotations expiring inside 14 days. All three off `PipelineReports` and
   `QuotationService`.
4. *done, 2026-08-25 — two of this item's three instructions were followed in spirit and not to the letter, see [What landed](#what-landed).* **Service** (Support, Timesheets): SLA compliance this month with breaches outstanding now; billable
   utilisation this month; unbilled WIP value. Off `TicketService::performance()`/`breaches()` and
   `TimesheetService::utilisationFor()`.
5. *done, 2026-08-24 — three widgets, three different readings of the page's period, see [What landed](#what-landed).* **Money** (Accounting, Invoicing): revenue against expenses over twelve months; the five largest
   debtors with days overdue; cash committed in the next 90 days (Phase 1.7's own figures).
6. *done, 2026-08-25 — Phase 2.4 exists, so this is that valuation; the sharp end was the reorder rule, see [What landed](#what-landed).* **Inventory**: stock value, count below reorder level, and — once Phase 2.4 exists — the same
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

**2026-08-25 — the inventory widget (Phase 5.6). All five of Phase 5's widget groups are now in.**

- **The item's condition is met: Phase 2.4 exists, so this is that valuation.**
  `InventoryReports::summary()` reads `InventoryValuationService::valuationForAll()` — the same call behind
  the Stock on Hand report.
- **The sharp end was not the valuation but the reorder rule, and it was already a bug once.** A reorder level
  of nought means there is *no* level rather than a level of nought: the column defaults to `0`, so treating it
  as a threshold flagged every product that had merely been sold out, since `0 <= 0`. Phase 2.4's own tests
  caught that in the report. A widget re-deriving the flag would have reproduced it — so the rule is now
  `InventoryReports::reorderLevelFor()` and `isBelowReorder()`, extracted, with the report's inline copy
  replaced by a call to them. The report's eighteen tests still pass on the extraction, which is the point of
  doing it that way round.
- **Two loops, one set of rules, and a test that pins their agreement.** `summary()` walks products
  separately from `stockOnHand()` rather than sharing one pass: sharing would mean the report walking its
  products twice per render, or the dashboard depending on the shape of a table's rows. What is shared is every
  *rule* — the reorder threshold, staleness, the valuation itself — and a test asserts the widget's stock value
  equals the report's own tile rather than a literal.
- **Stale stock sits beside the reorder count**, because they are opposite problems that a total hides: one is
  stock about to run out, the other stock nobody has touched in ninety days, and a stock value made mostly of
  the second is a very different figure from one made mostly of the first. A product that has never moved
  counts as stale, which is the report's reading and its reason — treating "no history" as fresh would hide
  every product somebody set up and forgot.
- **A product with a level and no movements at all is counted.** It has no row in `stock_movements`, so a
  summary built from the valuation alone would never see it — and it is exactly what a reorder flag is for.
- **The fixture had to go through `InventoryService`.** `stock_movements.type` is NOT NULL and a purchase also
  posts to the ledger, so the hand-built row I reached for first was both invalid and unrepresentative of
  anything the application does. Fourth fixture correction of this session, and the same lesson each time: the
  model's own API knows things a plausible insert does not.

**2026-08-25 — the people widgets (Phase 5.2). Four figures across four modules, and a fixture that found a
real bug rather than a fixture bug.**

- **`LeaveRequest`'s column is `from_date`, and the widget said `start_date`.** An absent Eloquent attribute
  reads as null rather than erroring, so the "leave already started while nobody answered" figure was
  *silently always nought* — the sharpest row in the queue, permanently empty. Every structural assertion
  passed; it took a fixture insert failing on a NOT NULL to expose it. That is the third time this session a
  wrong column name has been invisible until something asserted a value, and the first time the widget rather
  than the test was wrong.
- **Two service methods were added rather than querying in the widgets**, each in the class that owns the
  concept. `HeadcountReports::summary()` sits beside the Headcount Movement report's own loop and reuses its
  `headcountAt()`, which matters more here than anywhere in Phase 5: that helper compares date *strings*
  because `left_on` is a date cast and a boundary is an instant, and getting it wrong once already reported
  200% turnover for a month in which one person of one left. A widget with its own `whereNull` would have
  reproduced the bug instead of inheriting the fix.
- **`AttendanceRegister::daySummary()` is one grouped query, not the register's grid.** `forMonth()` needs a
  row per employee per day; a dashboard wants four numbers about one day, and running the grid to get them is
  the per-row shape this plan's own risk list names.
- **Unmarked attendance days are on the dashboard, because they are the finding.** A day nobody recorded is
  not a day nobody worked — the register's note leads with them for that reason — and "12 present" for a
  company of thirty with eighteen absent from every figure reads as an attendance problem rather than a
  recording one. Half days and home working count as present: one is a shorter day, the other a different
  desk, and neither is an absence. Lateness is counted from the days somebody attended, so a late arrival is
  present *and* late rather than a status of its own.
- **Leave uses the model's `pending()` scope and no new service method**, and that is deliberate: the scope
  *is* the shared definition of "awaiting a decision", so a widget writing `where('status', 'pending')` would
  be a second copy that a fourth status would break. The oldest wait is named beside the count, because a
  queue of five is a different problem from one request sitting for a month and a count cannot tell them apart.
- **Documents already expired are counted apart from documents expiring.** Both come back from
  `DocumentExpiryCheck::due()` — including the part a fresh query would get wrong, that an expired document
  keeps being reported because "an expired visa is not a warning that stops being true" — and folding them
  together would put a lapsed work permit in the same figure as one with three weeks left.
- **Two more widgets ignore the period, and say so on the stat.** The leave queue and the expiring documents
  are facts about now; filtering them by the dashboard's span would hide the oldest requests and the nearest
  expiries, which are exactly what they exist to surface. Three widgets now ignore the filter and each says
  so, because two thirds of the dashboard *does* move with it and a reader would otherwise assume they all do.

**2026-08-25 — the service widgets (Phase 5.4). Two of this item's three instructions were followed in spirit
rather than to the letter, and both departures are the interesting part.**

- **The item names `TimesheetService::utilisationFor()`; this uses `utilisation()`.** The named method answers
  for *one* employee and reaches `AttendanceCalendar::summarise()`, which walks every day of the month doing a
  holiday and a shift-pattern lookup per day. The service's own docblock says what looping it over a company
  costs: "hundreds of queries for one screen — the exact fault `docs/page-load-performance-plan.md` was
  written about, and the risk `docs/reports-expansion-plan.md` names for these reports by name". The
  company-wide `utilisation()` is three queries a month whatever the headcount, and is what the Timesheet
  Utilisation report already uses. Following the plan literally here would have built the thing the plan
  elsewhere warns about.
- **The item asks for "billable utilisation"; this reports billable *share*.** `TimesheetService` refuses to
  state capacity and gives the reason: `expected_hours` is null in every branch because a rule making
  timesheets and attendance reconcile "would make people book the difference somewhere to make the screen
  agree, which produces worse data than the gap it closed". A dashboard percentage against an invented
  denominator would have undone that decision quietly, on the one screen where a figure reads as fact.
  Billable share needs no assumption about what somebody's month should have held, and there is a test that
  the widget says nothing about capacity at all.
- **`utilisation()` answers per month, so the widget sums every month the period touches.** A quarter is three
  calls, a financial year to date at most twelve — bounded and small. Showing one month under a label reading
  "this quarter" would have been the easy version and the wrong one. Headcount is counted across the whole
  span, so somebody who booked in January and not February is one person rather than two halves.
- **The SLA widget's two halves read the period differently, and the plan's own wording is why.** Compliance
  is "this month" — a rate over a window. Breaches are "outstanding **now**", and `breaches()` takes no
  arguments at all, because a breach outstanding in March and since resolved is not something to act on today.
  So one stat moves with the filter and one does not, and the widget says so on the stat rather than leaving a
  reader to assume they match.
- **Resolution, not response, is the headline.** `performance()` reports both, and a company that answers
  within the hour and fixes nothing has met its response commitment and failed its customer.
- **Two of my fixtures passed for the wrong reason, from one cause.** `Ticket`'s category key is `category_id`
  and I wrote `ticket_category_id`, which mass assignment dropped silently — so the tickets were
  *uncategorised*, had no SLA, and therefore counted as compliant. One test asserted 100% and got it; another
  looked for a breach and found none. The compliance test now creates a ticket that genuinely blows its
  commitment and asserts the category came out right, so a null category cannot satisfy it. Separately,
  `timesheets.require_approval_to_bill` defaults to **true**, so unapproved time is correctly excluded from
  WIP — my entries had no `approved_at` and every WIP figure was nought.

**And the dashboard's size ceiling moved, 300 to 350 — the sanctioned case rather than the formality.**

- `PanelPerformanceTest`'s comment draws the distinction itself: "raise a ceiling for markup a new screen
  legitimately adds; never raise one to make a page that got heavier for no reason pass." Phase 5 replaces the
  dashboard with five groups of widgets; eight of them put it at 304.7 KB against 300.
- **Unlike the reports hub, there is nothing per-item to reduce.** The hub's fix was real — 30.4 KB of inline
  icons became 6.2 KB of sprite references — but a widget's cost *is* its Livewire component, and the only way
  to render fewer bytes is to render fewer widgets.
- Measured: 304.7 KB, 17 widgets registered, 23.7 KB of Livewire snapshots — about 1.4 KB all-in per widget.
  Phase 5's remaining groups are roughly six more, so ~313 KB; 350 leaves room for about twenty-six widgets
  beyond the plan, which is deliberately more than it needs so this stays a ratchet.
- Recorded in the test for whoever needs the bytes back: **88.6 KB of the dashboard is inline SVG**, most of it
  the domain rail's flyouts. That is where the fat is, not the widgets.

**2026-08-24 — the sales widgets (Phase 5.3), including the one widget the period filter must not touch.**

- **The funnel is unwindowed on purpose, and it is the only widget on the dashboard that ignores the
  period.** `PipelineReports::byStage()` accepts a closing window and the forecast report passes one, but the
  service's own comment says why a funnel must not: "a deal with no expected close date is not 'closing
  outside the window', it is unforecastable — and it belongs in the unwindowed pipeline view". Filtering a
  funnel by the dashboard's period would silently drop every deal nobody has dated, which are the ones most in
  need of attention. A test proves it by putting one dated deal outside the period and one undated deal in the
  same stage and asserting both are counted.
- **Three widgets, three readings of one filter, and now four across the dashboard.** A *window* for the
  forecast, an *as-at* for target attainment, an *origin* for the expiring quotations, and *ignored* for the
  funnel. Each one is a property of the question, not a preference — and the money group added a fifth
  reading, the twelve-month series that uses the period only as its endpoint.
- **Attainment takes an as-at while the forecast beside it takes a window, and the asymmetry is deliberate.**
  `attainment()` finds the targets *covering* one date, because a target is a period of its own — somebody's
  quarter — so "which targets are live" is a question about a moment. Handing it a range would mean choosing
  an end arbitrarily.
- **Attainment is reported as a count, not an average.** One salesperson at 200% with three at 40% averages
  to 80% and describes nobody. And targets with no number on them are excluded from the denominator, because
  `attainment_pct` is null there and counting them would report a company as behind on targets nobody set.
- **`QuotationService::expiringWithin()` was added rather than querying `Quotation` in the widget**, which is
  the rule applied to a query that did not exist yet. It is the mirror of `expireLapsed()` — the same three
  conditions with the comparison reversed — so a widget with its own query would have been a second definition
  of "live but lapsing", and the first status added to the ladder would have made them disagree. Both ends are
  inclusive: a quote lapsing *today* is the most urgent of the set, and the widget says "lapses today" rather
  than "in 0 days".
- **A fixture that made a test pass for the wrong reason.** `weightedAmount()` reads the *deal's*
  `probability_pct`, not its stage's — the stage's is the default a user is offered. A fixture leaving it null
  weighted every deal at nought, which every structural assertion tolerated until one asserted an amount.
  It is now explicit in the helper, with the reason.
- **CRM and Quotations had no widget discovery at all**, so both plugins gained `discoverWidgets` and both
  manifests a `widgets` table — the same shape the report pages needed in Phase 3.

**2026-08-24 — the money widgets (Phase 5.5), and three different right answers to one filter.**

- **Every figure comes from the service behind its own report**, which is Phase 5's rule and the reason it is
  written into the section header. `RevenueAndExpensesChart` calls
  `FinancialReportService::profitAndLoss()` — the Profit & Loss report's service; `LargestDebtorsList` calls
  `InvoiceService::outstandingReceivables()` — the Aged Receivables report's; `CashCommittedOverview` reads
  `App\Support\CashCommitments`, Phase 1.7's registry. Each test asserts the widget against its service
  rather than against a literal, so the pair cannot drift.
- **The plan warned that re-deriving inline would be quicker, and it was.** Summing `total - paid` over
  invoices for the debtor list is three lines against an aggregation over a service's return. The rule is
  what stopped it, and the aggregation is why the top debtor here is by construction the figure the ageing
  report shows for that contact.
- **All three read the dashboard's period differently, and each difference is a decision rather than an
  inconsistency:**
  - the **chart** lets the period choose where the twelve-month series *ends*. Honouring a one-month period by
    drawing one bar would destroy the widget rather than filter it — twelve months is the shape it exists to
    show. Its final column is capped at the period's end, so early in a month it is the month *so far* rather
    than a whole month padded with a future nobody has traded in.
  - the **debtors list** treats it as an as-at, because ageing is a balance and not a span. Read for last
    quarter it says who owed then.
  - the **commitments** widget treats it as the *origin* of a forward ninety days, because that window looks
    ahead. Read at a year end it answers "what is committed for the ninety days after June", which is the
    question somebody asks there.
- **A debtor is a contact, not an invoice**, and days overdue is the *worst* of their invoices rather than an
  average. A customer with one invoice ninety days late and nine current ones is a ninety-day problem;
  averaging reports them as nine days late, which is the number that gets them left alone. A contact whose
  credit notes cancel their invoices is not a debtor at all, and would otherwise take one of the five places
  from somebody who is.
- **Out and in are kept apart, with the net stated.** A commitment registry carries both directions — a
  recurring sales invoice is money coming in — and adding them gives a figure that is neither what the company
  owes nor what it expects. The net is on the widget because that is what somebody opens it for, rather than
  arithmetic left across two stats a centimetre apart.
- **The sort order is banded, ten apart**: money 10–19, sales 20–29, service 30–39, people 40–49, inventory
  50–59. So a group can gain a widget without renumbering its neighbours. The nine widgets that predate this
  phase still sit on 0–8 and therefore render above; placing them in the bands is Phase 5.7's own job and is
  noted rather than half-done here.
- **`RevenueAndExpensesChart` is twelve `profitAndLoss()` calls and is the widget Phase 5.8's cache exists
  for.** It is stated in the class rather than left to be discovered: until 5.8 lands it is the most expensive
  thing on the page, and it is `$isLazy` so the dashboard renders without waiting for it.

**2026-08-24 — the dashboard page and its period filter (Phase 5.1), plus 5.9's widget enumeration.**

- **The period reaches widgets through `getWidgetData()`**, which Filament spreads into every widget's mount
  properties. So a widget declares `public ?string $periodFrom` and is *handed* the span rather than deriving
  it — which is item 1's actual requirement, "none of them keeps its own idea of 'now'". Widgets get the
  resolved **dates**, not the period name: twenty widgets each resolving "this quarter" is twenty chances to
  resolve it differently, a centimetre apart on one screen.
- **Filament's own filter form was not used, because the plan asks for the URL.** `HasFiltersForm` keeps
  filter state in a schema, which is not linkable, and "a dashboard someone links to opens on the period they
  meant" needs `#[Url]`.
- **Every period ends today, and only its start moves.** A dashboard answers "how are we doing", and nobody
  has revenue from the rest of the month — so "this quarter" is the quarter so far rather than a quarter two
  thirds empty. A custom range is the exception, because naming both ends means both ends.
- **A test found a real bug in the quarter arithmetic.** `FiscalYear` *enforces* a 30 June end and says why —
  "a company joining part-way through gets a shorter year ending on the same date" — so a company that joined
  in November runs 1 November to 30 June. Counting three-month blocks forward from *that* start gave it
  quarters beginning in November, February and May, while its accounts and every report treat the quarters as
  July–September and so on. Counting back from the fixed year end fixes it, then clamping to the year's start,
  because that company has no October to report.
- **`DashboardPeriod` is deliberately not `ReportComparison::currentRange()`**, which looks like it would do.
  That answers "the three months up to this date", because a comparison must be the same length as the thing
  compared; this answers "the quarter we are in". They agree only when today is a quarter end.
- **Phase 0.3's `period` filter is now superseded rather than outstanding.** It was written as a pane filter;
  the page that actually needed a period was the dashboard, and this is it.
- **5.9's first half landed here** because every later widget commit depends on it:
  `FilamentWidgetsSmokeTest` now enumerates `Filament::getWidgets()` instead of naming five. A hand-written
  list covers the widgets somebody remembered, which is the set least likely to be broken — and Phase 5 adds
  a widget group per module, so the list would have been wrong on its first commit. It carries a floor of
  five, because an enumeration that silently found nothing would be the most reassuring test in the suite and
  the least informative.

**And the reports hub lost its per-report icons to keep a ceiling — the interesting part of this commit.**

- `PanelPerformanceTest`'s size budget failed at **366 KB against 360**, from Phase 4's additions. Its own
  comment predicted this and forbade the easy fix: "the remaining plan needs ~60 KB the ceiling does not have,
  so the hub's card markup is what should give way next, not this number. Raising it again would be the
  formality this comment warns about."
- Measured rather than guessed: the 51 rows were **70.3 KB, of which 30.4 KB was inline heroicons** — 8% of
  the whole page in icons nobody navigates by.
- **A sprite only helps because the icons now repeat.** The reports' own navigation icons were all *distinct*,
  so fifty-one `<symbol>`s would have saved nothing. Collapsing to one icon per section — nine — is what makes
  `<use>` worth having, and it is a genuine change to what the screen shows: a row's icon says which section
  the report is in rather than being the report's own. The report's navigation icon is untouched on its own
  page and in the sidebar.
- Result: **369 → 348.5 KB**, row icons 30.4 → 6.2 KB, rows 70.3 → 47.5 KB. Roughly 12 KB of headroom at
  ~930 bytes a row, which is a dozen more reports rather than the two the old markup allowed.
- The sprite defines **every** section's symbol, not the visible ones: the list is filtered by section and by
  search and re-renders on both, and a sprite that shrank with the filter would leave a row pointing at a
  symbol that had gone — rendering blank, which reads as a broken icon rather than a filtering bug. Four tests
  pin it, including one that fails if the inline icons come back.

**2026-08-24 — a saved view per report (Phase 4.5). Phase 4 is complete.**

- **The date is not saved, and the plan's own sentence is the argument.** It asks for "the filters somebody
  uses every month" — and the date is the one thing that *changes* every month. A view holding 30 June would
  keep opening on 30 June and the person who saved it would not notice for a while, which is the worst kind
  of wrong on a report. Applying a view leaves the date alone for the same reason, and both halves have a
  test.
- **The second half of that sentence gave the design.** "The URL already carries the whole state" is not just
  a note about effort — it means the fixed-date case *already has a mechanism*, and a better one: "the balance
  sheet at 30 June" is a link somebody sends. So the saved view should be the other thing entirely. A link
  for a moment, a saved view for a habit; two mechanisms doing one job each rather than one doing both badly.
- **Applying a view never clears a filter it does not carry.** The absence of a stored account is not an
  instruction to blank an account somebody has since picked. The obvious loop — assign every key in `FILTERS`
  — would have done exactly that, and its mutation is one of the ones that dies.
- **The stored keys are an allow-list rather than "whatever the page had".** So `asOf` cannot get in, and a
  future property on the hub does not silently join everybody's existing views — which is the realistic
  version of this going wrong: somebody adds a property, saving starts storing it, and every saved view
  begins applying something it never meant to.
- **The comparison basis is normalised on save *and* on read**, because a stored row can outlive a basis. A
  view saved when some basis briefly existed would otherwise hand the pane a string it has stopped
  recognising.
- **Only eight of the fifty-one reports offer it, and finding that out corrected the tests.** A saved view
  holds a comparison basis or one of the four pickers, so the three statements and the five reports with an
  `ASKS` entry have something to remember and the other forty-three have a date and nothing else. The first
  draft of the tests used the aged receivables and passed every assertion about the model while the control
  was correctly absent from the page — the model was fine and the test was fiction.
- **Named `SavedReportView`, not `ReportView`.** `ReportView` is the permission every report is gated on, and
  a model sharing that name would make `can('ReportView')` and `ReportView::find()` read like one subject.
- **Per user, with no foreign key to `users`** — that table is on the landlord connection and a
  cross-connection constraint is not a constraint. The scope to the signed-in id is what actually separates
  people's views, so it lives in the model rather than being trusted to each caller; there are tests that
  another person's view can be neither applied nor forgotten however the id arrives.
- **Seventeen mutations, fifteen killed — and one survivor is the design working.** Making the page pass
  `asOf` into a saved view changes nothing, because the model's allow-list drops it; adding `asOf` to that
  list fails immediately. The mutation surviving is the proof that the allow-list is the guard and the page's
  omission is only politeness, which is the right way round. The other survivor is the blank-key check in
  `forReport()`, which saves a round trip the query would have answered emptily anyway.
- **`state` is JSON rather than a column per filter**, because the *set* differs per report — a column each
  would be five nullable columns of which any report uses at most one, and a new filter would be a migration
  rather than a key.

**2026-08-24 — keyboard navigation of the report list (Phase 4.4). The phase set 35 rows as the threshold;
there are 51.**

- **The rows stay native `<button>` elements, and refusing the ARIA listbox pattern was the main decision.**
  `role="option"` plus `aria-activedescendant` is the textbook answer and it would have been a downgrade
  here: it replaces button semantics a screen reader already announces correctly with a pattern that then has
  to reimplement them, and Enter and Space work on these rows only because they have always been buttons.
  Arrow keys move real DOM focus between real buttons instead. A test asserts `role="option"` is *absent*, so
  a later change to it has to argue with something.
- **The search box is the type-ahead.** The plan asks for both arrow keys and type-ahead, and a literal
  reading would have meant a second string matcher — keystrokes jumping the selection without filtering —
  giving two behaviours to one set of keys. The box is the better of the two: it filters, and it shows you
  what you typed so you can correct it. So a printable key pressed anywhere in the list goes into it, which
  delivers the phase's intent through one mechanism rather than two.
- **The way in is the way out.** Down-arrow from the search box enters the list, up-arrow off the first row
  returns to it. That is what makes "type to narrow, arrow down, Enter" a path rather than three unrelated
  controls, and it is the only reason this is faster than the mouse at 51 rows.
- **Modified keys are left alone** — Cmd+K is the command palette, and a type-ahead that swallowed it would
  break a control that already exists.
- **`focus-visible`, not `focus`.** Arrow keys move real focus, so the ring is the only thing telling
  somebody where they are in a list this long — and a mouse click should not leave one behind.
- **The behaviour is not automatically tested, and the tests say so rather than implying otherwise.** There
  is no Dusk and no Playwright in this project, so nothing can press a key and see where focus went. What
  the tests do is fail if a hook the Alpine component reads is removed, which is the realistic way this
  breaks: the markup edited for an unrelated reason and the keyboard quietly stopping.
- **One test passed for the wrong reason and was fixed.** Counting `data-report-row` across the page came to
  52 against 51 rows, because the component's own selector string contains the bare attribute name. The
  attribute now carries the report key so the count is unambiguous — and being off by one row is exactly the
  error that count exists to catch.
- **The rebuilt CSS is deliberately not in this commit.** `public/build` is tracked, and rebuilding re-hashed
  `app-*.css` as well as the theme — an asset this change did not author. The stylesheet compiles; shipping
  it needs `npm run build` committed by whoever owns the asset pipeline.

**2026-08-24 — comparison periods (Phase 4.2), and one item of the plan deliberately not built.**

- **A comparison basis shorter than the reporting period is a category error, not a shorter comparison — and
  that one observation shaped the whole class.** A profit and loss is the financial year to date. Shifting
  its range back thirty days for a "previous month" comparison puts 1 June–20 January beside 1 July–20
  February: two overlapping eight-month spans whose difference is almost entirely the same trading counted
  twice. The figure would look entirely plausible. So a month or quarter basis narrows the **current** period
  to match, and `currentRange()` exists for no other reason.
- **A balance sheet is exempt, and that is not an inconsistency.** It is an as-at rather than a period, so
  the current figure is the balance on the day whatever the basis and only the comparison date moves. Hence
  two methods — `shift()` for as-at statements and `currentRange()`/`previousRange()` for period ones —
  rather than one that would have had to lie to one of them.
- **The comparison covers the whole previous month or quarter, not the same number of days.** Twenty days
  against twenty days would be tidier and would answer a question nobody asks: what a month is worth is what
  the month came to.
- **Budget is not a basis, and this is the decision worth recording.** The plan lists it. `BudgetVsActual`
  already *is* that report — per account, Planned against Actual with the variance, and its own budget
  picker — so putting budget in the comparison slot would be a second implementation of an existing
  comparison, and a poorer one, since the statement kind has nowhere to ask which budget. This plan's own
  Phase 5 states the principle for widgets and it holds here: two paths to one number is how they come to
  disagree, and the reader who spots it cannot tell which to believe. A test asserts budget is *not* offered,
  so the decision is recorded where somebody would otherwise re-make it.
- **`ComparativeStatement::profitAndLoss()` now takes an as-at date and a basis rather than a range.** It had
  to: a month basis narrows the current period, and a range handed in from outside could not be narrowed
  without the caller knowing the rule — at which point two places would know it. One existing test asserted
  the old range-taking contract and was rewritten rather than adapted.
- **Every subtraction is `subMonthsNoOverflow`.** Plain `subMonth()` from 31 March lands on 3 March, which
  would make a month-on-month comparison at any month end quietly wrong. A mutation survived the first pass
  here for a good reason worth remembering: the test used 31 March, and three months back from March is
  December, which *has* a 31st — so that date cannot tell the two subtractions apart. 31 May can.
- **Links people kept still work.** The hub carried a boolean `?comparison=` and a saved `?comparison=0` must
  still mean no comparison; `mount()` translates it, an explicit `?compare=` wins, and the basis is
  re-normalised on every read because Livewire writes the property straight from the wire when the picker
  changes. An unrecognised basis becomes the previous year rather than none: arriving with a bad one usually
  means an old link, and answering that with a column silently removed is the worse of the two answers.
- **Eighteen mutations, sixteen killed.** The two survivors are the `startOfMonth()` snap and the overflow
  guard on the range's *start*, which are defensive: the only two bases reaching `shiftRange()` are handed a
  `from` that is already the first of a month. The guard on the *end* is load-bearing and its mutation dies.
  Documented in place rather than left to look like coverage.
- **`label()` and `isOff()` were written and then deleted.** Both were unused once the picker read `BASES`
  directly, and a public method with no caller is an invitation to drift.

**2026-08-24 — negatives in parentheses (Phase 4.3), and where a formatting rule has to live.**

- **A preference, not a default.** `(1,250)` is how a balance sheet is read and `-1,250` is how everyone who
  is not an accountant reads a figure, and this application prints both kinds of report to both kinds of
  reader. Off unless a company turns it on, so nothing changes underneath anybody.
- **Applied where a report is drawn, not where it is calculated — and that was the design decision.** All
  fifty-one reports format their own cells with `number_format()` before the payload leaves the service, so a
  preference honoured inside each of them would have been fifty-one places to forget and a sweep across
  thirty files to land it. Instead the display layer re-reads what the payload already declares: the
  `numeric` column list, which is the same thing Phase 4.1's exporter uses. One rule, five call sites, and
  the reports know nothing about it.
- **The CSV deliberately does not get it, and that is why the rewrite is in the views.** A spreadsheet reads
  `(1,250)` as text. Applied to the payload — the obvious place — it would have reached the one output where
  parentheses are actively harmful, the file somebody opens in order to do arithmetic. A test asserts the
  payload still carries `-60,000` while the page shows `(60,000)`.
- **Five things in numeric columns had to survive, and the pattern is anchored at both ends because of
  them:** an em dash meaning "does not apply" (which nearly every report uses), a bare hyphen, a date like
  `2027-02-20`, prose beginning with a negative number, and a status cell like `Draft · net differs`. An
  unanchored pattern would have made a hash of every date column in the application, and a caught em dash
  would have emptied half the cells on half the reports — looking like missing data rather than a formatting
  bug. Each has its own test.
- **A figure that rounds away to nothing is not negative.** `number_format(-0.4, 0)` is the string `-0`, so
  deciding the sign before the rounding gives `(0)` — which reads as a puzzle — or `-0`, which reads as a
  bug. Both are wrong about the same number, and `money()` and `cell()` apply the identical rule so the two
  cannot disagree about it.
- **No caching in the formatter, on purpose.** `setting()` goes through `TenantSettings`, which already holds
  one array per tenant, so a lookup per cell is an array read. A static cache in a class called from a Blade
  loop would be the classic tenant leak: the first company's preference applied to the second company's
  report in the same worker.
- **Fourteen mutations, all killed.** The setting section lives in Core rather than Accounting, because it
  governs reports in every module and Core is the one module always licensed — a preference filed behind a
  module a company has not bought is a preference it cannot reach.

**2026-08-24 — exporting the open pane (Phase 4.1). Phase 8 now has something to send.**

- **One implementation meant one normalisation.** The pane draws four kinds and three of them are shaped
  differently: a `table` is columns and rows, a `ledger` is columns and sections each with their own total,
  a `statement` is label-and-amount rows with an optional prior year. `ReportExport` flattens all three to
  one grid, and the CSV writer and the PDF template each exist once. Per-kind exporters would have been three
  writers and three templates, and Phase 8 would have had to pick one.
- **The interesting work was undoing the presentation, not the plumbing.** The pane exists to make figures
  readable — `275,000` with a separator, an em dash where a value does not apply — and both are *wrong* in a
  spreadsheet: one arrives as text in most importers, the other stops a column summing. So the CSV strips
  separators from the columns the report declares numeric and empties those dashes. It is the one place in
  this application where the display layer is deliberately reversed, and the PDF keeps the formatting because
  a PDF is for reading.
- **Only the numeric columns, and the dash is why.** In a numeric column a dash is a figure that does not
  apply and an empty cell says so properly. In a *text* column it is the pane's own wording — the serial
  number a device does not have — and unformatting every column alike would delete a value somebody chose to
  show. A surviving mutation is what made the distinction explicit.
- **CSV formula injection is a real risk here and is now handled.** Report cells carry text somebody typed: a
  project name, a checklist item, a campaign's delivery failure reason. A spreadsheet executes a cell
  beginning `=`, `+`, `-` or `@`, so that is an attack on whoever opens the export rather than on this
  application. Escaped with a leading apostrophe — **and numbers exempted**, which is the whole difficulty: a
  negative figure begins with `-`, and a careless escape would turn every loss on every report into a string.
- **A byte-order mark, which is not decoration.** Excel on Windows reads a CSV without one as the local
  codepage, and these reports are full of em dashes and middots — every one would arrive as mojibake in the
  spreadsheet most likely to open the file.
- **`ModuleReportPage::getHeaderActions()` is now `final`, and the subclass hook is `reportActions()`.** All
  thirty-three report pages declared `getHeaderActions()` themselves and returned their help button, so two
  new actions would have meant editing thirty-three files and remembering on the thirty-fourth. The base page
  now assembles the row and a report contributes to it. The help call still has to be a **literal in the
  subclass's own file** — `HelpCoverageTest` reads each page's source — which the rename preserves.
- **Both doors, because they are one payload.** The hub's pane and each report's own page render the same
  statement and there is a test per report asserting it, so an export on one and not the other would be an
  arbitrary difference between two views of one thing. A test asserts the two produce byte-identical CSV.
- **One mutation survives and is documented rather than papered over.** `UNEXPORTABLE_KINDS` cannot be the
  operative refusal for a `file` report: that payload carries no columns and no rows at all, so the
  empty-rows check would refuse it anyway and no test can tell the two reasons apart. It stays as the thing
  that would still refuse one if somebody later taught `grid()` to flatten a file. Twenty-six mutations,
  twenty-five killed.
- **The PDF reuses `reports.layout`**, which already carries the table styling every accounting report PDF
  uses and already pulls in the Dompdf override partial. Tiles are laid out as a table row rather than with
  flexbox for the same reason: Dompdf does not lay out flex, and a PDF that only renders under headless
  Chrome breaks on every machine without Node.

**2026-08-24 — environment health and incidents (Phase 3.13). Phase 3 is complete: thirteen reports, and the
hub holds 51.**

- **The plan called this "the history", and the history is thirty days long.** `ProjectEnvironmentCheck` is
  `Prunable` at `projects.health.retention_days` — thirty by default — so the checks behind an uptime figure
  are *deleted* past that horizon. Taking "over a period" at face value and offering a financial-year uptime
  column would have computed it from whatever survived pruning and presented one month as though it were
  eight. The check window is clamped to the horizon, the subtitle states both spans, and the note says the
  horizon out loud on every read.
- **Incidents are not pruned, so the two halves of the report cover different windows on purpose.** The
  alternative — quietly shortening the incident history to match the checks — would throw away the only long
  record there is. Stating two windows is less tidy and more honest, and both are tested.
- **Only *confirmed* incidents count as outages.** `ProjectEnvironmentIncident` doubles as the
  flap-suppression state: a row opens on the first failure and is confirmed once the threshold is crossed.
  `EnvironmentHealthOverview` already reads `open()->confirmed()`, so the report agrees with the widget rather
  than inventing a second definition. The unconfirmed rows are reported as suppressed blips — visible, not
  counted.
- **`uptimePercent()`'s rule was honoured rather than its code reused.** "Never render 0% for not checked yet"
  is exactly right, and an unchecked environment is the opposite of a down one. But the method counts
  backwards from `now()` and this report answers as at a date, so the rule was reimplemented over the report's
  own window and the reason is in the docblock. *Never checked* became its own standing and its own finding:
  monitored, has a URL, nothing has ever run against it.
- **"Nobody was told" is the finding neither existing widget can show.** An outage that ran while alerts were
  off or the environment was muted. Both widgets are point-in-time and a mute has usually expired by the time
  anybody looks. **Its limitation is stated rather than smoothed over**: nothing records whether an alert
  actually went out, so this reads the environment's *current* settings against a past event, and the help
  says to read it as "these would not be alerted under today's settings".
- **Twenty-nine mutations, all killed** — but two survived the first pass, both upper bounds. No fixture had a
  check or an incident dated *after* the as-at date, so an as-at report that included the future passed
  cleanly. Both now have tests. The same class of gap as Phase 3.12's missing lower bound on meetings: **the
  bound nobody thinks to test is the one on the side the fixtures never reach.**
- **Pint reformatted three committed files it had nothing to do with** — pre-existing import-order violations
  in `ProjectPolicy`, `MyProjectsOverview` and `EnvironmentIncidentManager`, none of them mine. Reverted, so
  the commit stays scoped. Worth knowing that running Pint on a whole module directory picks up other
  people's debt.
- **Projects had no report wiring**, so it gained `discoverPages`, a `pages` manifest key and a
  `registerReports()`. Filed under *Operations* beside the SLA reports: whoever reads an SLA breach wants to
  know how long production was down.

**2026-08-24 — review cycle progress (Phase 3.12), where one word in the plan carried the design.**

- **"Complete" was the whole question, and `Review` had already answered it.** Five rungs — pending, self
  submitted, manager submitted, shared, acknowledged — and only the last is a review that finished. Counting
  *shared* would report a cycle as done while half the company had not opened their review.
- **A closed cycle holding unshared reviews is the sharpest finding on the report.** Somebody wrote a review
  of a person, the cycle was closed, and the person never saw it. `Review::isVisibleToEmployee()` is
  `shared_at !== null` and its docblock explains why — "submitted is not shared", because a review is a draft
  about somebody until a manager decides to share it, so that drafting can be honest. Which means a review
  sitting at *manager submitted* looks like completed work on every other screen in the application.
- **Sharing is judged on the timestamp, not the status.** A status is a label somebody set; the timestamp is
  what decides whether the person can read their own review, and a status of *shared* with no timestamp is not
  shared. The test asserts the report against `isVisibleToEmployee()` rather than against a literal.
- **The same fact means opposite things at the two ends of a cycle.** An unshared review in an open or
  calibrating cycle is work in progress; the identical row in a closed cycle is work abandoned. Both closure
  findings are therefore raised only once a cycle is closed, and both directions are tested.
- **`missed` is a settled goal state, and that had to be honoured.** Recording a goal as missed is a decision;
  leaving it open past the end of its cycle is not a kindness but nobody having decided, which means nothing
  can be learned from it. Counting only achieved goals would have rewarded the silence.
- **One-to-ones are counted inside each cycle's own dates**, because `one_to_ones` has no cycle column — the
  only thing tying a conversation to a cycle is the date falling inside it. Two overlapping cycles each count
  the same conversation, which is right: it happened during both.
- **A goal with no cycle is charged to no cycle.** `review_cycle_id` is nullable for standing objectives, and
  attributing those to whichever cycle happens to be open would make a cycle answerable for goals nobody set
  in it.
- **Four tests failed on first run and the report was right every time.** Each fixture had reviews and no
  one-to-ones, so the *no one-to-ones* suffix fired correctly and my expected strings had forgotten it. Fixed
  by adding a conversation to those fixtures rather than by baking a second finding into the expected
  string — a test about unshared reviews should be about unshared reviews.
- **Twenty-six mutations, twenty-five killed.** The real gap was the meeting bound: the period test only had a
  conversation *after* the cycle, so removing the lower bound changed nothing. It now has one on each side of
  both ends. The survivor is the `whereIn` on cycle keys, a narrowing rather than a guard — the grouped result
  is read by cycle key, so a goal from another cycle would be fetched and never looked up. Documented as such,
  the same treatment Phase 3.11's `whereIn` got.
- **Performance had no report wiring**, so it gained `discoverPages`, a `pages` manifest key and a
  `registerReports()`. The page is `ReviewCycleProgress` because `ReviewCycleResource` already derives the
  `review-cycles` slug — the third time that collision has been caught before it became a missing route.

**2026-08-24 — campaign performance (Phase 3.11), and one metric deliberately not built.**

- **The skip count is the report.** `CampaignSend`'s own docblock set the brief: `skipped_no_consent` "has to
  be reported as a distinct figure rather than a silence — otherwise nobody can tell a campaign that reached
  nobody from one that was never sent". Every other view of a campaign shows what went out; this shows what
  did not.
- **The two skip reasons are separated, because one is a fault and the other is a success.** A recipient who
  never agreed is a list-building problem pointing at the consent register. A recipient who withdrew between
  `prepare()` and `send()` is the guard working — `CampaignSender` calls that gap "exactly when a complaint
  comes from", and the message did not go. Merging them would report a success as a fault, so the note words
  each as what it means rather than as a count.
- **The two reasons became constants on `CampaignSend`** so the report could tell them apart. They were
  sentences typed at two call sites in `CampaignSender`, and matching a sentence is not a contract: a
  reworded message would have silently emptied the split.
- **A campaign marked sent with pending rows never finished.** `send()` leaves every pending row either sent
  or skipped before marking the campaign sent, so a pending row on a sent campaign means the loop stopped part
  way — and nothing else in the application notices, because the campaign's own status says it went out.
  Pending rows on an in-flight or cancelled campaign are expected and are not flagged; both directions are
  tested.
- **A campaign with no `sent_at` is placed by `created_at`.** Without that fallback the unfinished and
  in-flight runs would be precisely the ones the report could not see, since neither has a send date.
- **The recipient-count-versus-segment metric was deliberately not built, and this is the most useful thing
  in this entry.** A recipient with no address on the channel gets no row at all — `prepare()` passes over
  them with a bare `continue`, on the stated grounds that "somebody with no WhatsApp number has not refused
  anything" — so the send count really is lower than the audience and nothing records the difference. The
  obvious fix is to re-run the segment and compare, and it would be wrong: `audienceFor()` evaluates its
  filters *live*, so it returns today's audience rather than the one that existed at send time, and every
  campaign whose segment has since gained a member would show a false shortfall. The gap is stated in the help
  instead of guessed at in the report.
- **A surviving mutation found a real trap: `failed_reason` carries skip reasons too.** `prepare()` writes the
  consent message into it, so a top-failure column that did not filter on `STATUS_FAILED` would report "No
  consent on record for this channel" as a *delivery failure*. Two tests now pin the guard in both
  directions — a skip reason is not a failure, and a failure mentioning consent is not a skip.
- **Twenty-seven mutations, twenty-six killed.** The survivor is a `whereIn` on the two known reasons, which
  is a narrowing rather than a guard: the split is read by key, so an unrecognised reason sits in the array
  unread. It stays because the table grows by one row per recipient per campaign, and the docblock says
  plainly that removing it changes no output and no test pretends otherwise.

**2026-08-24 — the consent register (Phase 3.10), where the plan's own aside was the specification.**

- **"Compliance evidence, not marketing statistics" ruled out more than it ruled in.** No opt-in rate, no
  channel comparison, no trend — and a test asserts there is no percentage anywhere on the report, note or
  tiles. A percentage invites a target, and the moment consent has a target somebody manages the number
  instead of the record.
- **The state is derived from the latest row, and the register resolves the tie exactly as
  `Consent::permits()` does** — most recent `recorded_at`, then highest `id`. That second key is not
  decoration: a bulk import can stamp a whole file with one timestamp, and a report that broke the tie the
  other way would state a permission the sender refuses to act on. The test asserts the register against
  `permits()` rather than against a literal, so the two cannot drift.
- **A true as-at, so "what did we have permission for on 30 June" has an answer.** The latest row *on or
  before* the date, and the change count is as-at too. A subject whose only rows come later is absent rather
  than shown as revoked — there was nothing on the register then, and `permits()` is explicit that no row
  means no.
- **An empty register says nobody may be contacted, not "nothing to show".** On a compliance report the
  difference matters: one is an absence of data and the other is a fact somebody about to run a campaign
  needs stated.
- **The finding is a grant with no source**, in the migration's own words: "'they agreed' is worth nothing
  without 'and here is how'". Counted on *grants only*, and the asymmetry is deliberate — removing somebody
  from a list needs no justification, so a sourceless revocation is not a finding and counting it would bury
  the grants that matter.
- **The `recorded_by` null case cannot be created through `Consent::create()`** — `booted()` does
  `recorded_by ??= auth()->id()`, so a test passing null gets the acting user stamped on it. Nulling the
  column afterwards is not a workaround: a row with no recorder only ever arises from something written
  outside a request, which is exactly what the report is reporting.
- **The section label is `Sales & pipeline`, with an ampersand.** `ReportsHubTest` caught `Sales and
  pipeline` immediately as a tenth section rather than a report filed in an existing one — the section
  registry doing precisely what it is for.
- **Campaigns had no report wiring at all before this**, so it gained `discoverPages`, a `pages` key in its
  manifest and a `registerReports()`. Phase 3.11 lands in the same module and now has somewhere to go.
- **Twenty-one mutations, all killed** on the first pass — including the four that would each have hollowed
  out the register: the as-at filter dropped, the first row winning instead of the latest, the channel
  falling out of the grouping key, and sourceless grants going uncounted.

**2026-08-24 — onboarding and offboarding progress (Phase 3.9), built against the plan's own phrasing.**

- **The plan asked for "items overdue by owner role" and an overdue list is the wrong shape for it.** Both
  `due_on` and `owner_role` are nullable, and each null is a finding an overdue-only report structurally
  cannot show: an item with no due date can never *become* overdue, so it sits outstanding forever and the
  one report somebody would look for it on is precisely the report it never appears on. Undated items
  therefore sort *above* not-yet-due ones — a finding, not a future task.
- **An item with no owner role has not been asked of anyone**, which `ChecklistItem` already worries about in
  its own docblock: a template "pointing at somebody who left is a checklist nobody owns". The cell reads
  *Nobody* rather than sitting blank, because an empty cell reads as a rendering fault instead of the state of
  the record.
- **An exit item still open for somebody who has already left is a door still unlocked.** Exit checklists are
  where access cards, accounts and keys get revoked. Nothing else in the application puts "this person has
  gone" next to "their access has not been removed", and an onboarding item for the same leaver is
  deliberately *not* counted as the same finding.
- **"By owner role" is delivered in the note and the ordering, not by making roles the rows.** Grouping under
  role headings answers whose queue is longest and loses which task, for whom — and somebody acting on this
  needs to know it is the laptop for the new starter in accounts. So the note splits the overdue count by
  role biggest-queue-first, the worst-blocked role's items rise to the top, and every row stays individually
  actionable.
- **Two mutations survived the first pass and both were the tests' fault, not the code's.** The role-split
  fixture created its largest queue first, so insertion order already matched sorted order and removing
  `arsort()` changed nothing observable; the fixture now creates the biggest queue *last*. And the
  truncation test could not tell a sorted-then-cut list from a cut-then-sorted one until a late-created role
  had to survive the cut to be named.
- **One mutation was invalid rather than surviving.** Reversing the days comparison on one side of a `<=>`
  leaves an inconsistent comparator, which is undefined behaviour in `usort` — it happened to preserve the
  order and looked like a test gap. Mutating both sides killed it immediately. Worth remembering: a
  one-sided edit to a spaceship comparison does not test anything.
- **Each row carries its own sort key** rather than being matched back to the item collection by position.
  The positional version worked and was one inserted filter away from silently pairing a key with the wrong
  row.
- **Progress is measured against the checklists that still have work**, not every checklist ever run. Diluted
  by years of finished onboardings the figure would sit near 100% permanently; against the live ones it moves.
- **The third report in a row to agree that somebody's last day is not yet a leaver**, matching
  `HeadcountReports::headcountAt()` and the correction Phase 3.7 needed. Three reports agreeing on what
  `left_on` means is worth more than each deciding for itself.

**2026-08-24 — final settlements (Phase 3.8), and a tolerance that was quietly wrong.**

- **The report lists leavers, not settlements, and that single decision is most of its value.** Every other
  view of a settlement in this application starts from a settlement that exists, so an employee who left and
  was never settled is invisible everywhere. Those rows carry no figures and sort to the top.
- **There is no ledger balance to tie to, and saying so is not a shortfall.** A settlement posts nothing —
  approving one records that a figure was agreed. The Phase 2 rule therefore does not apply, and what the
  report offers instead is three disagreements: the unbuilt settlement above, a stored net that is no longer
  the sum of its parts, and a draft quoting kit that has since moved.
- **`net_amount` is written on build and on approve, and not on edit** — while every component is editable on
  the resource form. So typing a notice recovery into a draft leaves the stored net behind, and the figure of
  record disagrees with the figures it is made of. The Net column shows the *computed* net so the row adds up,
  and the status cell carries the disagreement; a row whose parts do not sum to its total reads as a bug in
  the report rather than a defect in the record.
- **The stale-kit check runs on drafts only, on purpose.** The builder refuses to rebuild an approved
  settlement "or the agreed figure would move underneath it" — so flagging an approved one as stale would be
  arguing with the agreement. The *net differs* check does apply to approved settlements, because a stored
  figure that disagrees with its own components is a defect however it was agreed. Both directions have a test.
- **`abs($a - $b) >= 0.01` is the wrong way to compare two money figures, and a surviving mutation is what
  exposed it.** Removing the tolerance entirely broke nothing, which said the tolerance was doing no work — and
  it turned out to be doing the wrong work: float subtraction of two `decimal:2` values under-shoots, so
  1234.56 − 1234.55 is 0.009999999999990905 and a genuine one-paisa disagreement reads as *no difference*.
  Four of five sampled paisa-apart pairs failed that way. The comparison now rounds the difference to two
  places, which needs no tolerance at all. Worth carrying to any other report comparing money.
- **Payable and owed-back are two tiles, never one.** A negative settlement is legitimate — the model says so
  — and summing a positive with a negative gives a figure that is neither what the company owes nor what it is
  owed. Both are somebody's job.
- **One mutation survives and is honestly equivalent.** A bare `!== 0.0` in place of the rounded comparison
  behaves identically on this schema, because every operand is a `decimal:2` column; no test can distinguish
  them and none pretends to. The rounding stays as the form that is still right if a caller hands it an
  unrounded sum.
- **The page is `FinalSettlementsReport`, not `FinalSettlements`** — `FinalSettlementResource` already derives
  the `final-settlements` slug, and two things claiming one URL surfaces as a missing route rather than a
  clash. The same reason `ExpenseClaimsReport` carries the suffix, and the help slug is suffixed to match.

**2026-08-24 — assets in employees' hands (Phase 3.7), where both of the plan's ties turned out to be real.**

- **The value column is not *like* the settlement recovery, it is the same figure.** `unreturnedAssets()` sums
  `value` over outstanding items for one employee; this report sums the same column over the same scope for
  everybody. The test asserts the report's total against the builder's return rather than against a literal,
  so if somebody changes what a settlement charges for, the test fails and the report is wrong. That is worth
  more than a matching number: it makes the tie structural rather than coincidental.
- **An item with no value recorded prints a dash, not a nought — and that is a finding, not formatting.**
  Because the settlement sums the column, a null recovers *nothing*: the laptop is gone and the deduction is
  zero. A nought in the cell would read as kit that is genuinely worthless rather than kit nobody priced, so
  the report dashes it and the note counts them.
- **The second tie surfaced something no screen in this application puts together: a fixed asset disposed on
  the books while somebody is still holding it.** The accounts say the company no longer owns it; an
  `issued_assets` row says who has it. Either it came back and was never marked returned, or it was written
  off out of the building. Nothing else asks.
- **The leaver boundary was wrong until a test name caught it.** The test was called *the last day of
  employment is not yet a leaver* and asserted the opposite — and passed, because `hasLeft()` was `<=`.
  `HeadcountReports::headcountAt()` counts an employee whose `left_on` is the date being read, so the two
  reports disagreed about whether somebody was employed on their last day. Now strictly `<`, which is also the
  right reading here: somebody in the building today can hand the laptop back today.
- **Judged as at the date, never by `status`.** A register read for September must not mark somebody a leaver
  who resigned in December. Reading "is inactive now" would have looked identical on today's data and been
  wrong on every historical read — the same class of bug as an as-at report that filters on the current state.
- **The asset register is guarded on `accounting`, and unreadable is its own answer.** A company can disable
  the module and still hold `fixed_asset_id` values from before it did. The column then says *Not on register*
  rather than *On the register*, because the latter would assert something nothing verified. The items are
  still listed and still valued — a laptop is out whether or not the books can be read.
- **A row per item, though the plan says "by employee".** A serial number, an issue date and a days-out figure
  are properties of a thing, and somebody chasing a laptop needs to know which laptop. The holder is named on
  every row and the ordering groups by holder — leavers first, then longest out, because that is the order the
  rows need acting on.
- **Fifteen mutations, all killed**, including the four that would each have quietly emptied a finding: the
  as-at filter dropped, the leaver total never accumulating, the disposal never counted, and an unvalued item
  printing 0.

**2026-08-24 — headcount movement and turnover (Phase 3.6), and a date-versus-instant bug worth naming.**

- **Comparing a `date` cast against an `endOfMonth()` made a leaver vanish on their last day.** `left_on` is
  midnight; the month boundary is 23:59:59; `left_on >= boundary` is therefore false on the very day somebody
  left. That dropped them from the closing headcount, which halved the turnover denominator and reported
  **200% for a month in which one person of one left**. Two tests failed from the one cause. Every boundary
  comparison in the report is now on date strings, because dates are what the question is about.
- **Turnover is over the *average* of opening and closing headcount**, which is the convention and the only
  denominator that behaves at both ends: against opening, a company that halved understates its rate; against
  closing, it overstates it, or divides by nought in a month that ended empty.
- **Turnover above 100% is a real answer and is not capped.** Somebody joining and leaving inside one month
  gives 200% in a one-person company. That reads oddly and is correct — churn can exceed average headcount —
  and capping it would hide exactly the months worth looking at.
- **Two columns read different sources on purpose.** Joiners come from `date_of_joining`, because a month's
  joiners is a fact about that month and somebody re-employed has joined again. Tenure is *continuous
  service* from the first job-history row, which is the rule `FinalSettlementBuilder` already set — "somebody
  re-employed after a break has two spans and only the current one counts" — so measuring from the original
  joining date would credit the company for the gap. Both mutations fail named tests.
- **The earliest history row, not the latest.** `keyBy` keeps the last of a duplicate key, so the query orders
  descending to make it keep the first. Sorted the other way this would silently measure tenure from
  somebody's most recent promotion, which is a plausible-looking figure and wrong; there is a test for it.
- Somebody with no history and no joining date is left out of the tenure average rather than counted as
  nought years, which would drag it down for a missing record rather than a short career.
- **The plan cites `employees.leaving_date`; the column is `left_on`.** Recorded rather than silently
  corrected, because the plan's data table is otherwise reliable and a reader checking against it would look
  for a column that does not exist.


**2026-08-24 — credit notes issued (Phase 3.5), which is a compliance report rather than a list.**

- **The tax rule is the report.** A credit note may be issued against an invoice for `fbr.credit_note_days`
  (180 by default), and beyond that it needs the Commissioner's approval under rule 22. Nothing in this
  application refuses a late credit note — the window is *reported*, the way the SLA clocks are — so this list
  is the only place a reversal made without cover is visible at all.
- **The exposure is stated as money, not a count.** What matters is how much tax was reversed without cover,
  not how many documents did the reversing: one large credit note is a bigger problem than five small ones.
- **A credit note naming no invoice is not called compliant.** The window cannot be computed without the
  invoice, and "within the window" would be a guess in the company's favour on a tax question — so it reads
  *No invoice named*, is counted separately in the note, and is not added to the exposure either. Mutating it
  to "within window" fails two tests.
- **The window is read from the company's setting.** A company on a different regime has a different window,
  and judging it by the default would report an exposure that is not one — the worse of the two errors on a
  tax report. Hard-coding 180 fails a test by name.
- **The credited invoice is looked up outside the report's own period.** A credit note raised late is the case
  this report exists for, so the invoice it credits is usually older than the window being reported and often
  older than the fiscal year. Read through the query builder for two columns, so an Eloquent relation cannot
  quietly pull a whole invoice from outside the period a reader thinks they are looking at.
- Filed under *Statutory reporting* rather than with the receivables, because the question it answers is the
  tax one and that section already holds the FBR reports.


**2026-08-24 — revenue by customer, project and product (Phase 3.4).**

- **Three groupings in one table rather than a dimension filter**, which is a departure from the plan's
  wording. A picker would have to be declared in `ReportPane::ASKS` — an Accounting constant — and putting an
  Invoicing concept there is precisely the coupling Phase 1.2 removed from `supports()`. *Win/Loss* already
  stacks three groupings behind a labelled first column, and reading them together is better than switching
  between them anyway: a customer whose revenue is all on one project is a different risk from one spread
  across four.
- **The groupings must not be added together, and that is the report's most dangerous property.** A sale
  appears once under its customer, once under its project and once per product line, so summing the rows
  trebles the revenue. The record row totals the customer grouping alone, the note says so, and mutating that
  condition away fails the test by name.
- **A credit note is attributed to the invoice it credits.** The revenue was recognised against that
  customer, project and products, so the reversal belongs in the same place. In practice a credit note carries
  a customer and no project, so attributing it by its own columns would drop the reversal into *No project*
  and leave the project holding revenue that had been given back. Mutating this fails four tests.
- **`No project` and `Not a product` are rows, not gaps.** Invoicing unattributed to a project is the figure
  that makes the project grouping smaller than the customer one, so the note states the amount; and a line
  with no product — a service, a one-off — is common enough that dropping it would make the product grouping
  quietly fail to add up.
- **Issued, partially paid and paid only.** A draft is not revenue, a void one never was, and a purchase is
  cost. Each has its own test, because each is a one-word change away from being counted.
- Customer, project and product names are read through the query builder. `invoicing -> projects` is declared
  so a model import would be legal, but the report needs one column of each table — and a company that has
  switched the projects module off still has `project_id` values from before it did.


**2026-08-24 — quotation conversion (Phase 3.3).**

- **Superseded versions are excluded, and that is the report's reason for existing.** A quote revised three
  times is one opportunity, not four. Counting each version would inflate what was issued by however often
  the company negotiates and push the win rate *down* for doing the thing that wins work. Excluded in the
  query rather than filtered later, so no figure can accidentally include one — and mutating that clause away
  fails four tests.
- **Two conversions, because they fail differently.** Issued → accepted is whether the work was won; accepted
  → invoiced is whether anybody billed for it. The second is the one nothing else in the application
  surfaces, and an accepted quote with no invoice against it is revenue the company has agreed and never
  asked for. It gets its own column and comes first among the note's warnings, ahead of quotes about to lapse:
  one is a failure to bill and the other is only a deadline.
- **The win rate counts *decided* quotes**: accepted, declined, or run out of time. A quote still inside its
  validity has not been lost — the same rule as the hiring funnel's acceptance rate — but an expired one has,
  because it ran out without anybody saying yes.
- **Expiry is computed, not read from the status.** The nightly sweep is what sets `expired`, so between a
  quote lapsing and the sweep running the stored status still says `sent`. The model already computes it for
  exactly this reason — "an expired quote must not be acceptable in the meantime" — and the report follows,
  so a lapsed quote is a loss on the day it lapses rather than on the day a job notices.
- **Expiring-soon is a column on the month whose quotes are running out**, which is 2.4's reason for putting
  the stock flags on the product rows: the row is where the reader would have gone looking anyway. Drafts are
  excluded, because nobody has been given them, and already-lapsed quotes are *expired* rather than expiring.
- The period is the financial year to date through `ReportPeriod`. Read in February, a calendar year would
  drop the first seven months of the company's quoting.


**2026-08-24 — the hiring funnel (Phase 3.2).**

- **Nothing in Phase 3 reconciles, and this is the first report to say so explicitly.** There is no account
  behind a hiring funnel, so Phase 2's rule about record rows tying to balances does not apply. What replaces
  it is a discipline about not overstating what the data supports, and four decisions carry it — each a way
  the report could read as more confident than it is:
  - **Acceptance is over offers *answered*, not issued.** An offer nobody has replied to is not a refusal, and
    counting it as one makes a company that has just sent three offers look as though it lost them. One
    accepted, one declined, one outstanding reads 50% and not 33%.
  - **Time to offer is measured to `issued_at`.** A draft offer nobody has sent is not a milestone the
    candidate has reached.
  - **Time to join counts accepted offers only.** A declined offer has a joining date nobody will honour, and
    averaging it in describes a notice period that never happened.
  - **Ageing is only for vacancies still open.** A closed vacancy's age is a historical fact, and putting it
    in the same column invites the two to be averaged into a sentence nobody meant.
  Both of the first and third were mutation-checked: dividing by all offers, and counting declined joining
  dates, each fail a named test.
- **A withdrawal is not a rejection**, and is in no stage column. Somebody who withdrew left of their own
  accord; counting them beside rejections would read as the company's decision. They stay in the applications
  total, because they did apply, and the note says how many — which is what stops the stage columns looking
  as though they have lost somebody.
- **Wide, because a funnel is its stages.** Six stage columns plus four measures is past the pane's width, and
  collapsing the stages into a total would remove the only thing that makes it a funnel.
- The averages are means over however many offers a vacancy produced, which over two or three hires is a
  rough guide rather than a statistic — so the application count sits on the same row, to be read with it.


**2026-08-24 — the monthly attendance register (Phase 3.1), which needed a performance fix before it was
possible at all.**

- **`WorkPatternResolver` cached patterns per employee *per day*.** `AttendanceCalendar::summarise()` walks a
  month a day at a time, so one employee's month cost 31 queries; a company-wide register over forty people
  would have been upwards of twelve hundred. The rows do not change between two days of one month — the
  *answer* does — so an employee's assignments are now loaded once and the date resolved against them in
  memory. `first()` over the descending list reproduces the old query's "latest wins if the ranges overlap"
  rule exactly, which matters because overlap is not prevented in the schema.
- **Caching the assignments alone left 57 queries for one employee's month**, and the register's own
  query-count test is what caught it: most companies have no dated assignment, so the *default* pattern was
  the branch taken thirty-one times a month at two queries each. Memoised with `false` as the sentinel,
  because null is a real answer — a company with no pattern at all — and the two have to be distinguishable
  or the miss is re-queried every time. The register went from **326 queries to under 20** for five employees
  over a month. Payroll benefits identically; payslip generation calls `summarise()` per employee.
- **The comparison is the report's stated value and it is a payslip, not a ledger balance.** A payslip stores
  the `paid_days` it prorated on, so the register either reproduces it or has found that pay was calculated
  on a figure this calendar does not produce. The note counts the disagreements and says nothing about the
  rows that agree — thirty-one columns are already competing for space and a column of ticks earns none.
- **Read through the query builder, because naming `Payslip` here would close a cycle.** `payroll` requires
  `attendance`, and the tangled-module budget is nought. Three columns of one table, guarded on the module,
  matched on month name *and* fiscal year — a payslip has no date, and the name alone matches the same month
  of every year the company has traded.
- **`·` is not a blank cell.** An unmarked day is counted as *worked* by `paidDays()` — "a day nobody
  recorded is not a day anybody missed" — so a month full of dots reads as a good month and is really an
  unfilled one. The note counts them first, before the payroll comparison, because it is the figure that
  makes everything else on the screen untrustworthy.


**2026-08-24 — advances outstanding and expense claims (Phases 2.7 and 2.8). Phase 2 is complete.**

- **Advances: the register and the account will usually disagree, and the report's job is to say why.**
  Nothing posts an advance when it is entered — the register records that money was lent, and the ledger
  only learns of it if the payment out was booked against the advances account, while a payslip's recovery
  *credits* that account. So the note names which way round the difference falls, because the two directions
  mean opposite things: the account holding **less** is advances lent without a payment booked, and holding
  **more** is either a payment that is not an advance or a recovery recorded in the register and not in the
  ledger. Phase 2.5 found the same shape in asset cost; a register is not a posting.
- **A row per advance, not per employee**, departing from the plan's wording deliberately. The instalment
  and the months remaining belong to an advance: somebody with two on different instalments has two answers
  to "when is this cleared", and averaging them would invent a third.
- **The `advances -> accounting` coupling was bought properly**, which `ModuleBoundaryTest` demanded in
  those words: the call site is guarded, so a company without accounting gets the register and no
  comparison. Advances declares `payroll` and not `accounting` for the reason the Expenses entry beside it
  already gives — "requiring it would make the module unsellable to a company that keeps its books
  elsewhere".
- **Claims: two rules that pull in opposite directions, and both are asserted.** The rows are the *financial*
  year to date, through `ReportPeriod` — read in February, a calendar year would drop seven months. But the
  liability is a *balance*: a claim approved last March is owed just as much as one approved yesterday, so
  the headline counts claims from before the window. A report applying one rule to both figures would be
  wrong in one of them.
- **Claims cannot be reconciled, and the report says why rather than leaving an apparent omission.**
  Reimbursements post to the account `expense_reimbursement` maps, and the shipped mapping points it at the
  same code as `meal_recovery`. One account holding two unrelated flows cannot be attributed to either. The
  test asserts both the sentence *and* the premise — that the two config keys are equal — so if they are ever
  separated the test says the report can now reconcile instead of silently keeping the excuse.
- **Three mistakes of mine worth recording, all caught the same day:**
  - I **overwrote a committed help doc.** `expense-claims` is the ExpenseClaim *resource's* help — how to
    submit, decide and get reimbursed — and I wrote a report over it. Restored from git; the report's slug is
    `expense-claims-report`. Help slugs share one namespace with resources, so a new one has to be checked
    for before it is written.
  - The page was first called `ExpenseClaims`, which derives the slug `expense-claims` — already the
    resource's URL. The page's route was never defined and the hub could not link to it, surfacing as
    `Route [filament.admin.pages.expense-claims] not defined`. Renamed `ExpenseClaimsReport`.
  - I sorted the claims rows **after** formatting them, comparing "9,000" against "12,000" as text. Sorting
    now happens on the grouped collection, before anything becomes a cell.


**2026-08-23 — the bank reconciliation (Phase 2.6), and a workflow that forbids the thing being reported.**

- **`complete()` refuses any statement whose closing balance is not exactly the ledger balance, and an
  unpresented cheque is precisely a difference between those two.** So the statements that have something to
  reconcile are the ones this application will not let anybody close, and every *completed* statement
  necessarily reconciles to nil. Proved before it was written up rather than read off the code: one 500
  inflow matched, one 120 cheque written and unpresented, every statement line matched, `isFullyMatched()`
  true — and `complete()` throws *"Closing balance 500.00 does not match ledger balance 380.00"*. That is a
  textbook reconciliation being rejected as an error.
- **So the report is about open statements, which is the opposite of what "asked for at every year end"
  implied.** It is the only place the 120 is named and added up. Whether `complete()`'s rule should be relaxed
  — a reconciliation completes *with* unpresented items, that is what the statement is for — is a change to
  posting behaviour with its own tests asserting the current rule, so it is left as a finding rather than
  folded into a report. `BankReconciliationStatementReportTest` pins the refusal, so the day that rule
  changes, the test and the report's note both say so.
- **The identity, stated per account rather than in aggregate.** A bank account is debit-normal: a cheque we
  have written and the bank has not paid is a credit the ledger has made and the bank has not, so the bank
  reads *higher* by that amount, and a deposit in transit is the mirror. Bank, less unpresented, plus in
  transit, equals the books — and what survives both adjustments is real, usually a charge the bank applied
  and nobody booked.
- **Unmatched statement lines are counted, not valued.** They are the usual explanation for a surviving
  difference, but an unmatched line's amount is the *bank's* figure: folding it into the reconciliation would
  be asserting the journal entry it should have produced. So the note says how many and leaves the difference
  standing.
- **`reconciled_at` is the whole mechanism, and its absence is the definition.** Matching stamps the ledger
  line; a posted line without the stamp is by definition something the bank has not seen. Excluding a line
  clears it again, which is right — an excluded line is one nobody claims ties to the ledger — and the test
  for that asserts the ledger side goes back into "in transit".
- **The ledger figure is batched, and the equivalence is asserted against `ledgerBalance()` statement by
  statement.** `ledgerBalance()` builds an account's entire ledger to return one closing number, which is
  right for one statement on screen and wrong for a report over every bank account. It is also the figure
  `complete()` checks, so a second way of computing it could tell a company its books agree while the
  workflow says they do not. Same protection, same reason, as 2.4's batched valuation and 1.1's ledger.
- **One statement per account: the latest at or before the date.** A reconciliation is a position at a
  statement date, not at an arbitrary one, so asking for it in September gives the August reconciliation
  rather than an empty page — and each account's figures are read at *its own* statement date, which the test
  proves by putting one account on July and another on August.


**2026-08-23 — the asset register (Phase 2.5), and the method this plan said it already had.**

- **This was not a "no new business logic" item, which is what Phase 2 promised.** The plan costed the
  twelve-month charge as coming "from `DepreciationService`'s own method". The service had three methods: two
  that post depreciation and one that writes an asset off. There was no read-only projection anywhere, so the
  only way to learn what the next year's charge would be was to *book* the next year's charge —
  `runForMonth()` twelve times, auto-approved and posted. `DepreciationService::schedule()` is the method the
  plan assumed. It walks a replica of the asset forward through the model's own `monthlyDepreciation()` and
  saves nothing.
- **The test for it is the equivalence: project twelve months, then book twelve months, and assert the two
  are the same list of figures.** Declining balance is why that test earns its keep — the charge is a
  proportion of book value, so it falls every month, and a second implementation of the `2 / life` rate would
  have been free to drift from the entries it predicts with nothing to notice. Same protection, same reason,
  as the batched valuation in 2.4 and the general ledger in 1.1.
- **The cached `accumulated_depreciation` column cannot answer an "as at" question**, and every report in
  Phases 1 and 2 takes a date. A cache has no history: it holds today's total, and the migration says as
  much. Comparing it against `balancesFor()`, which *is* as at the date, would have reported the gap between
  two **dates** as a discrepancy — in the one report whose whole purpose is proving that two figures agree.
  So depreciation here is summed from the posted entries against account 1500 instead. Credits less debits
  against that account, not a memo match: the account is the definition of the figure, and
  `DepreciationService`'s own `memo like 'Depreciation%'` test would drop an asset's whole history out of the
  note the day somebody reworded a memo.
- **Which makes the cache itself checkable, and that is the third thing the note says.** Not a reconciliation
  — both figures are ours — but the register screen, the asset form and every future declining-balance charge
  are computed from the cached one, so a drift means all three are wrong. This report is the only place the
  two are ever put side by side.
- **And `accounting:rebuild-asset-depreciation` ships with it, because a figure somebody is told is wrong and
  cannot fix is half a feature.** It rewrites each asset's cached total from its posted entries, touching only
  the ones that disagree, and derives the status from the corrected figure — an asset whose cache had it
  written off as `fully_depreciated` goes back to `active` with life left in it, which is the half of the
  repair nobody expects and the reason the command prints a status column before writing. The command and the
  report both read `DepreciationService::bookedFor()`, so the thing that reports the drift and the thing that
  repairs it cannot hold two opinions about what the ledger says.
- **Both sides separately, because net book value is a subtraction.** Cost ties to each asset's own account,
  depreciation to 1500. A reader told only that the net is out by a figure does not know which half to go and
  look at: a misposted cost and a hand-booked depreciation entry read identically in the net and are found in
  completely different places. The note also says which way round the difference falls, because those are two
  different faults.
- **The commonest cost-side difference is nobody's mistake, and it is worth stating plainly: nothing posts an
  asset's cost when it is entered.** `FixedAsset` has an *optional* `journal_entry_id` and no code fills it
  in, so a company that books purchases straight to the bank has every asset in this register and none of them
  in an asset account. The note names that case as "the register carries cost the asset accounts do not"
  rather than calling it a discrepancy.
- **"As at" governs which assets exist, not only their figures.** `status` is the state *now*, so filtering on
  it would have dropped assets out of last year's note the moment somebody disposed of one this year — and
  last year's note would silently change. An asset belongs on the register when it was bought by the date and
  not disposed of until after it.
- **A month nobody ran is still to come.** Depreciation is booked by hand, from an action on the register, so
  months get missed. The projection starts at the month of the report date, or after the last month actually
  booked, whichever is later — so a missed charge stays in "still to come" instead of falling between a
  depreciation total that never included it and a forecast that starts after it. The months are asserted at
  the service level, because for a straight-line asset with life to spare the twelve-month *total* is
  identical either way: a window that had slipped a month would not show up in a total at all.


**2026-08-23 — the stocktake (Phase 2.4), and three wrong premises its own tests caught.**

- **It reconciles, and this one has something real to reconcile against**, unlike leave liability and
  unbilled WIP. Purchases debit a product's inventory account and sales take cost out of it, so the account
  and the valuation are two independent statements of one figure. The report states both and says whether
  they agree.
- **`InventoryValuationService::valuationForAll()` is new and batched**, because `onHand()`, `stockValue()`
  and `averageCost()` are per product and the middle one is two queries — 3n+ for a catalogue. It must agree
  with the three of them exactly, and the test asserts that product by product over a FIFO product, an
  average-cost one and one sold out entirely. That is the same protection the general ledger's batching got
  in Phase 1.1, for the same reason: two ways of computing one figure is a drift waiting to happen.
- **Three premises I had wrong, each found by a failing test rather than by reading:**
  - **`reorder_level` defaults to `0`, not null.** Treating nought as a threshold flagged every sold-out
    product, since `0 <= 0` — the opposite of useful, because a product with no reorder level is one nobody
    wants to be told about. Nought now means "no level".
  - **There is no such thing as stock in no account.** `InventoryService::inventoryAccountId()` falls back to
    1300 when a product names none, so the report's "unmapped products explain the difference" branch
    described a state that cannot occur. The account is now resolved through that same method — made public
    for it — rather than read off the product, so the report reconciles against the account the posting
    actually used.
  - **The "nothing to reconcile to" branch was unreachable** once the fallback was understood. Removed: an
    unreachable branch about money is worse than an absent one, because it reads as having been considered.
- **What the difference actually is, in practice: stock on deactivated products.** The rows are active
  products and deactivating one does not unpost the entries that put its stock in the accounts, so this is
  the commonest difference and it is nobody's mistake. It is added before comparing and named in the note,
  rather than reported as a discrepancy.
- **`InventoryService::accountId()` is memoised, and the report's query-count test is what noticed.** A code
  maps to an id for the life of a request; it was a query every time, which is unremarkable once per posting
  and ten identical lookups when a report resolves ten products' accounts. Per instance rather than static,
  so a test that swaps the chart of accounts gets a fresh answer.
- **Both flags share one cell**, which is the plan's instruction — "flags on the same rows rather than as
  separate reports" — because a product both below its reorder level *and* untouched for months is the case
  worth acting on. *Never moved* is distinguished from *No movement*: no history and moved-long-ago are
  opposite facts, and treating the first as fresh would hide every product somebody set up and forgot.


**2026-08-23 — unbilled WIP (Phase 2.3), and the risk list's cross-module gate turns out to already exist.**

- **A balance, not a period, and that is the load-bearing choice.** `billableFor()` answers a billing run's
  question — one project, one month — and building the report on it would have shown only the current month
  while looking entirely correct. An hour booked in March and still unbilled in August is exactly the hour
  worth seeing. `TimesheetService::unbilledWip()` is one grouped query for the whole balance; the test proves
  a five-month-old hour is on it, and that ten projects do not cost ten queries.
- **Unpriceable hours are named and left out of the value.** That is `BillableHours`' rule for invoices —
  "named, never silently dropped and never billed at a guess" — and a balance-sheet figure has more to lose
  from a guess, not less. So there are two hour figures: everything unbilled, and the part of it no rate
  could be found for. The hours count as hours because they were worked; the money states only what could
  actually be invoiced, and the note says how much is missing from it.
- **The approval rule is read, not assumed.** `timesheets.require_approval_to_bill` decides whether
  unapproved time can be billed, and a WIP figure including time that billing would refuse is a figure no
  invoice could realise. Asserted in both settings.
- **The third Phase 2 report with nothing to tie to.** Nothing posts unbilled work in progress for timesheet
  hours — construction's WIP is a different figure about a different subject — so it says "not posted to any
  account" like leave liability does. Phase 2's blanket rule now looks like it holds for the reports whose
  figure the ledger already carries, and not for the ones whose figure it does not; two of the three built so
  far are the second kind.
- **The risk list's cross-module gate needs no code, and finding that out cost a wrong turn worth recording.**
  The list asks that such a report "gate on *every* module it reads, not just the one it lives in", so this
  page was built with an `$alsoRequires` list on `ModuleReportPage`. A failing test showed the list was
  redundant: `Modules::enabledFor()` walks a module's declared requirements recursively — "a module is only
  usable when everything it declares as a requirement is usable" — so a Timesheets report is already
  unavailable when Projects is off. The machinery is gone. What remains is the one case the manifest does not
  cover — a module a report reads and its owner does not *require* — and there gating is the wrong answer
  anyway: such a module is optional to the owner, so the report should degrade rather than disappear. Unbilled
  WIP shows a customer id instead of a name without Invoicing, and a company that invoices elsewhere still
  gets its report.
- **The customer name is read through the query builder, not a model.** `Project` deliberately has no
  `Contact` relation — its own comment says declaring one "would make Projects import Invoicing for nothing" —
  and through `TenantDb` rather than `DB`, because a bare `DB::table()` builds against the *landlord*
  connection and would look for `contacts` in the wrong database. The suite cannot catch that on its own; the
  two connections coincide under test.


**2026-08-23 — leave liability (Phase 2.2), and the report that proves Phase 2's rule has an exception.**

- **Phase 2's opening rule does not hold for 2.2, and Phase 2.2's own text is why.** The rule is that "every
  one of them carries a record row that ties to a ledger balance"; the item says the accrual "belongs in the
  accounts and is currently in nobody's figures". Both cannot be true, and the item is the one that is:
  there is no leave-liability account in `config/accounting.php`'s payroll mapping and nothing posts one. So
  the report states *"not posted to any account"* on its face, every time. Inventing a comparison against an
  account that does not hold this would have been worse than having none, and delivering that fact is the
  report's whole value — a provision nobody has posted is exactly as real as one that has been, and the only
  difference is that the balance sheet does not know.
- **The figure is `FinalSettlementBuilder::leaveEncashment()`, not a formula of this report's own.** A leave
  liability is what the company would have to pay, so the only defensible definition is the one the
  settlement uses: same encashable types, same positive-balance-only rule, same `statutory.encashment_divisor`.
  A second formula would drift, and the drift would surface as a leaver settled for an amount the accrual
  never held. The test asks the builder directly and compares — if this report ever grows arithmetic of its
  own, that is what fails.
- **It lives in Lifecycle, not Leave**, for the same reason: the calculation is Lifecycle's, and putting the
  report beside it is what keeps the accrual and the settlement from being two numbers. It also needs no new
  module edge — `lifecycle -> leave` already exists for exactly this calculation.
- **Only encashable types, and "nothing is encashable" is a different sentence from "nobody has any left".**
  A nought against both would read as a company that happens to be up to date, when in one case there is
  nothing to be up to date about.
- **Days that cannot be priced read as unknown, not as nought — a defect the tests caught.** An employee with
  no recorded wage returns 0.0 from the builder, correctly, since it has no rate to multiply by; printing
  that as `0` says the days are worth nothing. They are worth an amount nobody has recorded the wage to
  compute. Both money cells are now dashes and the note says how many people that applies to and that the
  total is therefore incomplete.
- **A leaver is not a provision.** Somebody who has left has either been settled — the money is a payable —
  or has not, in which case it is a debt. Only people still in service at the date are on it.
- **The `Illuminate\Support\Carbon` mistake from Phase 1.7 recurred in this file a day later**, because the
  shorter import is the one muscle memory reaches for and the failure surfaces inside the service rather than
  at the report's own boundary. Recorded as a standing rule rather than a second incident: always import
  Illuminate's here.
- **The cost is a few queries per employee, accepted and stated.** `LeaveBalance::for()` answers per employee
  per type. Batching it would have meant a second implementation, which is the thing this report exists to
  avoid; if it ever bites, the fix belongs in `LeaveBalance` as a bulk method the single-employee one also
  calls.


**2026-08-23 — the payroll register (Phase 2.1), the first of the reports that reconcile.**

- **It is built from `payslip_pay_components` alone, and that is only correct because of
  `PayComponentRecorder`.** Half of pay in this application lives in payslip *columns* and half in component
  rows — every shipped component is `is_column_backed` — and the recorder copies the columns into rows on
  every save. So the component table is the complete record of what a payslip paid, which is what
  `PayComponentSeeder`'s own comment says the rows are for: "a report … can ask what pay is made of instead
  of carrying its own list, which is how the billing statement came to keep a hand-maintained column map
  with an 'Other' bucket". This report keeps none.
- **The tie is exact, not approximate.** The payroll entry credits *salaries payable* with each payslip's
  net salary, so the register's net total must equal that credit across the month's posted payslips. Three
  states are tested: everything posted and agreeing; a payslip unposted, which is named as the reason and
  not called a discrepancy; and a payslip altered behind the model after its entry was posted, which is.
- **The scoping needed its own test, and a mutation is what proved it.** Reading the account *balance*
  instead of the credits from these payslips' entries left the first reconciliation test green — with one
  month of fixtures the two readings are identical. Salaries payable carries every month's unpaid salaries
  and every payment against them, so a balance would be neither independent of the register nor the same
  figure. Two months of payslips now pin it, and the mutation fails.
- **A component deactivated after it was paid keeps its column.** Reading only `active()` components would
  take the amount out of the columns and leave it in the row total, so the register would stop adding up —
  silently, and only in the months where somebody had tidied the component list.
- **`Earnings` here includes expense reimbursement and the payslip's own `total_earnings` does not.** The
  register's own arithmetic has to close — earnings less deductions equals net — and net salary *is*
  `total_earnings + reimbursement - total_deductions`. So the column is every earning component, the
  reimbursement is visible in its own column, and the help says so rather than leaving two documents
  disagreeing about a word.
- **A dash is not a nought.** The recorder deletes a component row whose amount rounds to nothing, so an
  empty cell means the component was not part of that person's pay — where a nought would claim it was and
  came to nil.
- **Two corrections to my own work:** a private `post()` helper in the test is a *fatal* error, not a
  shadowed method, because `TestCase::post()` is public — the same collision as `run()` on a console command
  a fortnight ago; and I had written that two payslips for one person in a month was reachable when
  `payslips` carries a unique key on (employee, month, fiscal year). The test now pins that key instead,
  since it is what makes a row-per-payslip register readable as a row-per-person one.
- `PayrollReports::fiscalYear()` now calls `ReportPeriod::yearFor()` rather than spelling the same query out
  again. Two copies of the fiscal-year rule is how `PayrollMonth` came to have two implementations that
  disagreed for every year not starting in July or January.


**2026-08-23 — Cash Commitments (Phase 1.7). Phase 1 is complete.**

- **The plan's note was "nothing in the application answers this today", and the reason was three runners.**
  Scheduled entries, beneficiary subscriptions and recurring invoices each had something that *raised* them
  and nothing that listed what was coming, so a company could see everything it had been billed for and
  nothing it had committed to.
- **`outstandingFor()` was the wrong method, for two separate reasons, and neither would have shown as an
  error.** It answers a *posting run*: outstanding occurrences only, capped at `MAX_PER_RUN` so that nobody
  has to review two hundred back-dated entries at once. A forward-looking report needs the occurrences that
  have *not* come round yet — the outstanding ones are the things that already happened — and it must not
  inherit a cap, or it is quietly short with nothing on screen to say so.
  `ScheduledTransactionService::occurrencesBetween()` is new, uncapped, forward, and one query for the
  raised dates of the whole book rather than one per schedule.
- **The three sources are registered, not imported.** Recurring invoices are Invoicing's, the report is
  Accounting's, and `docs/module-packaging-plan.md` §8 spent a phase removing `accounting -> invoicing` —
  one column of one report is not a reason to buy it back. `App\Support\CashCommitments` follows
  `PaymentGenerators`: the report asks, each module answers for itself. A company without invoicing gets a
  shorter list and no *arriving* total rather than an error, and a later phase can register loan instalments
  or a payroll month without the report learning anything new. Asserted by flushing the registry and
  re-registering only Accounting's two.
- **`Raised` is a column, not a filter**, because a commitment and a payable are read differently: one is
  something to chase, the other a decision still open. Conflating them would have double-counted everything
  the runner had already done.
- **Directions are words and amounts carry no sign.** A single column with some figures negative gets added
  up wrongly by hand every time, so *Out* and *In* are their own column, the two totals are the two
  directions, and the record row states the **net** — a column mixing directions has no meaningful sum.
- **Two type errors and two test faults worth recording.** `SubscriptionBillingService::due()` and
  `RecurringInvoiceService::due()` both hint `Illuminate\Support\Carbon`, and an instance of `Carbon\Carbon`
  is not an instance of its own subclass — which fails at the call rather than at the boundary, so it
  reached a rendered page before a test caught it. And my own first version of the "no negative amounts"
  test scanned the whole table for a hyphen and failed on the dates: the shape of assertion that passes for
  the wrong reason as often as it fails for one.
- Also reverted: `pint` run over a whole module directory reformatted three files nobody had touched
  (stale imports). Pre-existing drift, not this phase's to fix, and not this phase's to hide in a commit
  either.


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
