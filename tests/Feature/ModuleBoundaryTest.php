<?php

namespace Tests\Feature;

use App\Support\Modules;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one thing the physical structure buys that the registry could not: a module
 * may only reach into another module it has declared as a requirement.
 *
 * Without this, the directories are filing, not boundaries — Payroll could grow a
 * direct dependency on Invoicing and nothing would notice until a customer
 * licensed one without the other and hit a class that isn't there.
 *
 * Core is exempt as a target: it holds users, companies, fiscal years and the
 * audit trail, is always licensed and can never be switched off, so every module
 * depends on it by construction and declaring that everywhere would be noise.
 */
class ModuleBoundaryTest extends TestCase
{
    /**
     * Shared infrastructure outside app/Modules that any module may use: the
     * tenant model base class, the module system itself, PDF rendering, the
     * employee-access helpers several modules read.
     *
     * These are deliberately not module-owned. Moving EmployeeAccess into
     * Employees, say, would manufacture a cross-module dependency for Payroll,
     * Projects and MPR over a helper none of them owns.
     */
    private const SHARED_NAMESPACES = [
        'App\Support',
        'App\Models',
        'App\Http',
        'App\Console',
        'App\Filament\Concerns',
        'App\Filament\Livewire',
        'App\Filament\Livewire\CommandPalette',
        'App\Filament\Support',
        'App\Multitenancy',
        'App\Providers',
        'App\Listeners',
        'App\Traits',
        'App\Jobs',
        'App\Notifications',
    ];

    /**
     * Shared code that reaches into a module, per file, with the modules it may
     * reach. Everything else in a shared namespace may reference `core` and
     * nothing more.
     *
     * This list exists because the blanket exemption it replaces ('employees' was
     * allowed everywhere in a shared namespace) hid two things worth seeing. It is
     * deliberately keyed by *file* rather than by namespace, so paying one entry
     * off is visible in a diff, and stale entries fail exactly as KNOWN_COUPLINGS
     * does.
     *
     * The remaining entries have a known way out, which is what makes this list
     * "debt" and not "design": the employee helpers answer "which employees may
     * this user see" and are used by eleven modules — including CRM, which
     * deliberately requires nothing and is therefore already contradicted by this
     * import. The way out is a contract with a null default that Employees
     * rebinds, making the licensing claim structurally true rather than asserted.
     *
     * `app/Support/ModuleMap.php` was the largest entry here and is now gone. It
     * held 222 inline references to all 22 modules, so the class that maps modules
     * to their classes depended on every one of them. Each module now declares its
     * own slice in `module.php` and the map is an accumulator that names nobody.
     *
     * @var array<string, array<int, string>>
     */
    private const SHARED_DEBT = [
        'app/Support/EmployeeAccess.php' => ['employees'],
        'app/Support/EmployeeOptions.php' => ['employees'],
        'app/Support/LandlordUserColumn.php' => ['employees'],
    ];

    /**
     * How many modules may still be trapped in a dependency cycle. **Zero.**
     *
     * Ratcheted down, one phase of docs/module-packaging-plan.md at a time, and the
     * test fails in both directions — a cycle added, or a budget left stale after
     * one was broken. Zero was the goal and the point at which every module becomes
     * extractable as a composer package; the graph is acyclic as of 2026-08-17.
     *
     * At zero this constant stops being a budget and becomes an invariant: the
     * lower-bound assertion below can never fire again, and the upper bound now fails
     * for **any** cycle at all. Do not raise it to make a change pass. A cycle cannot
     * be expressed as a composer dependency, so re-introducing one takes a module out
     * of the set that can be packaged — which is the whole of what this plan bought.
     *
     * The history is kept because each step taught something the next one needed.
     *
     * The opening measurement, once inline references were visible, was 14 of 22
     * modules in two components:
     *
     *   [12] accounting, advances, attendance, core, employees, expenses,
     *        inventory, invoicing, leave, mpr, payroll, projects
     *   [2]  billing, timesheets
     *
     * That is larger than the plan estimated, and for a reason worth keeping: the
     * plan measured the graph with the old `^use`-only scan, so the container calls
     * into Advances, Expenses and Timesheets were not in it. Making the lint honest
     * pulled three more modules into the tangle before a single edge was cut.
     *
     * 14 -> 13: MPR left the tangle. `MPR::employee()` was a belongsTo with no
     * `employee_id` column anywhere, so it could only ever have thrown, and
     * `User::mprs()` was the only thing pointing Core at MPR.
     *
     * 13 -> 9, and four modules left at once: **core, inventory, invoicing and
     * projects**. Phase 9 finished, so Core names no module at all — and Core was
     * the hub. Five two-cycles ran through it, which transitively bound everything
     * touching any of the five, and that is why phases 6, 7 and 8 each deleted real
     * edges without moving this number by one. What is left is the domain knot the
     * plan always said it was:
     *
     *   [7] accounting, advances, attendance, employees, expenses, leave, payroll
     *   [2] billing, timesheets
     *
     * Every remaining member is a pay-and-people cycle — a payslip reaching an
     * advance, an advance reaching a payslip — not misfiled host code.
     *
     * 9 -> 7: **billing and timesheets, the only pair where neither direction was a
     * declared requirement.** Billing asks `BillableTime` for the lines, and the
     * implementation takes a contact, a year and a month rather than a `BillingRun` —
     * because a contract that passes a model passes the module that owns it. Two
     * modules that need not be sold together were unextractable anyway, which is the
     * purest form of this debt. See docs/module-packaging-plan.md §11.
     *
     * 7 -> 3: **`employees -> payroll`, one edge in two files, and four modules left.**
     * Accounting, attendance and leave were each held in this component only by a
     * three-cycle running back through it, exactly as inventory/invoicing/projects were
     * held by Core. Employees now names no module — which is the property a module
     * everything else depends on has to have.
     *
     * 3 -> 0: **advances, expenses and payroll — the one knot that was always domain
     * rather than misfiled code.** A payslip deducts an advance instalment and reimburses
     * an expense claim, and both of those modules declare Payroll as a requirement, so
     * the direction the licence points is the only one that is not a cycle: Payroll asks
     * `AdvanceLedger` and `ReimbursableClaims`, and the two ledgers bind them.
     *
     * The obstacle worth recording is that neither contract may name `Payslip` — a shared
     * contract naming a module's model is undeployable without that module, which is the
     * opposite of the point — so the four values the ledgers actually read from a payslip
     * travel as an `App\Support\PayslipSettlement`. **A contract that passes a model
     * passes the module that owns it**, and that was true of `BillableTime` and
     * `BillingRun` two steps earlier. It is the general lesson of the last three.
     */
    private const TANGLED_MODULE_BUDGET = 0;

    /**
     * Coupling that exists today and is not a declared licence dependency.
     *
     * These are two different things, and the distinction is the whole reason this
     * list exists rather than a list of `requires` entries. `requires` means "this
     * module cannot be *enabled* without that one". An import means "this code
     * mentions that class" — and because every module is always deployed, an
     * import is harmless even when the other module is unlicensed, provided the
     * code path is guarded.
     *
     * Payroll -> Accounting is the clearest case: Payroll deliberately does not
     * require Accounting, degrades when it is unavailable
     * (PayrollPostingService returns early), and still imports fourteen of its
     * classes. Declaring the requirement to satisfy a lint would make Payroll
     * unsellable without Accounting — the opposite of what is wanted.
     *
     * The rest is real architectural debt, recorded so it cannot grow quietly:
     *
     *  - Accounting -> Employees/Payroll/Invoicing/Inventory reverses the plan's
     *    graph. Payment.payable may be an Employee, PaymentService settles
     *    payslips, OperationsOverview aggregates across every module, and
     *    RegisterEntryService reaches into invoices and stock. Accounting is
     *    therefore not the base of the dependency graph the plan drew; it sits in
     *    a cycle with Invoicing and Inventory, which do require it.
     *  - Core -> six modules, because Core holds surfaces that enumerate domain
     *    models: the CustomField model list, the payroll-account validation on
     *    Company Settings, the fiscal-year close action, User::mprs().
     *  - Employees -> Projects/Accounting, Invoicing -> Inventory,
     *    MPR -> Employees: relations across the seam.
     *
     * Breaking these needs interfaces, events or a registry, which is a separate
     * piece of work. Until then the value of this test is that the graph cannot
     * get worse without someone deciding to make it worse.
     *
     * @var array<string, array<int, string>>
     */
    private const KNOWN_COUPLINGS = [
        // Guarded soft dependencies, by design — see PayrollPostingService, and
        // MonthlyBillingService::creditLines() for Billing.
        // Payroll -> Attendance and Payroll -> Leave are phase 3's join, and guarded
        // rather than declared for the same reason as everything else here: payroll is
        // sold to companies that run neither. AttendanceFigures returns the zeros
        // MonthlyPayrollService always raised when the modules are off, and
        // AttendanceProration refuses to divide by them — so the *default* behaviour of
        // a company with neither module is byte-identical to before phase 3, which
        // PayslipAttendanceProrationTest asserts.
        //
        // Advances and Expenses were reached through the container with an inline FQCN, which was invisible
        // until importsIn() learned to see inline references. The container call was chosen deliberately —
        // both modules *require* payroll, so a `use` statement would have made the pair a cycle — but the
        // dependency was structural either way: the class has to be on disk for `app()` to resolve it.
        // **Both are gone, and they were the last cycles in the application.** Payroll asks
        // App\Support\Contracts\AdvanceLedger and ReimbursableClaims, which cannot name `Payslip` — a shared
        // contract naming a module's model is undeployable without that module — so the four values the two
        // ledgers read from one travel as an App\Support\PayslipSettlement instead. See §11.
        'payroll' => ['accounting', 'attendance', 'leave'],
        // Timesheets was the same shape and the same discovery: the hours lines were fetched via
        // app(BillableHours::class) behind modules()->enabled('timesheets'), which kept Billing sellable to a
        // headcount-billed client and kept the pair out of this list. The guard was real; the invisibility
        // was not. **Both directions are now gone** — Billing asks `App\Support\Contracts\BillableTime` and
        // the implementation takes a contact, a year and a month rather than a `BillingRun`. Neither module
        // required the other, so nothing was declared to make this possible; see §11.
        'billing' => ['advances'],
        'expenses' => ['accounting'],

        // Debt.
        // Accounting reaches Inventory, Invoicing and Payroll no longer. Four registries and one shared
        // namespace did it: DashboardStats, JournalEntryOwners, ReportRenderers, PaymentGenerators, and
        // App\Support\Banking for the file writer, the month pickers and the fiscal-month arithmetic.
        // Employees is what remains — see below.
        'accounting' => ['employees'],
        // Core is not here any more, and that is the entry this whole exercise was for. All seven of §9's
        // files are inverted: the comment policy asks OwnedByUser, the user page announces UserCreated, the
        // custom-fields screen reads CustomFieldSubjects, the fiscal-years table asks FiscalYearCloseCheck,
        // the Reports hub reads ReportCatalogue and ReportPaneRenderer, the CSV importer reads CsvImporters,
        // and Company Settings receives its currency and payroll-posting sections through SettingsSections.
        // Core now names no module at all, which is what took the eleven-module cycle apart — see
        // docs/module-packaging-plan.md §9 and "Core is the hub".
        // Employees -> Payroll was a third inline-reference find: EmployeeSetting hasMany
        // EmployeeSettingComponent, and the components relation manager read PayComponent. Payroll
        // *requires* employees, so it was a cycle, and unlike the guarded pairs it was not degradable at
        // all — the relation was simply declared, in fully-qualified form so the import would not show.
        // **It is gone, and it was the linchpin**: accounting, attendance and leave were each held in the
        // same component only by a three-cycle running back through it, so deleting one edge freed four
        // modules. Payroll now contributes both the relation and the tab from its own provider — see
        // App\Support\ResourceContributions and docs/module-packaging-plan.md §11.
        // Employees -> Accounting was here for `Employee::bank()`, and it is gone: Bank is a
        // reference table three modules read, so it moved to Core, where a dependency is free. See
        // docs/module-packaging-plan.md §7 and App\Modules\Core\Models\Bank.
        // Employees -> Projects is gone too: Projects requires Employees, so it now contributes both
        // the Employee model's project relations and the Projects tab on the Employee screen, and
        // Employees names nothing. See App\Support\ResourceContributions.
        // **Employees is no longer here at all.** It names no module, which is what a module every other
        // module depends on has to be true of — the same property Core reached in phase 9.
        // Invoicing -> Projects is guarded, not debt: an invoice may name the
        // engagement it belongs to (GnuCash's "job"), and every surface that
        // offers the field checks modules()->enabled('projects') first. Invoicing
        // stays sellable to a company that runs no projects — the column exists
        // in every tenant, because licensing decides what is offered rather than
        // what is migrated, and it simply stays empty.
        'invoicing' => ['inventory', 'projects'],
        // CRM -> Invoicing and CRM -> Employees are guarded, not debt, and not
        // declared as requirements: docs/crms-plan.md §1 makes `crm` sellable to a
        // company that has bought neither. Converting a lead creates a Contact and the
        // action is hidden when invoicing is off (LeadConversion::isAvailable); a
        // lead's owner is an employee and the field is not offered when employees is
        // off, with `leads.created_by` answering ownership instead. Same shape as
        // invoicing -> projects above.
        // `projects` joins the same set for phase 6's won-deal hand-off: a won deal may
        // become a project, and `opportunities.project_id` names it. Guarded like the other
        // two — the action is absent without the module, and the column simply stays empty,
        // exactly as invoicing -> projects already works in the other direction.
        'crm' => ['invoicing', 'employees', 'projects'],
        // Attendance -> Leave and Attendance -> Payroll are guarded, not declared.
        // A day covered by approved leave cannot be overwritten (AttendanceRecorder),
        // and a correction inside a locked payroll month is refused
        // (RegularizationService) — both check modules()->enabled() first, so
        // attendance stays sellable to a factory that runs neither.
        // Attendance -> Payroll is gone: the locked-month question is now the
        // PeriodLock contract, answered by Payroll's own binding.
        'attendance' => ['leave', 'employees'],
        // Timesheets -> Attendance is guarded: the utilisation report compares booked time with the
        // attendance record, and is absent rather than broken without it.
        // Timesheets -> Billing is gone. `BillableHours` took a `BillingRun` to price a month, and a contract
        // that passes a model passes the module that owns it — so it takes the three values it actually read
        // from the run instead. That was the half of the cycle no amount of guarding could have hidden.
        'timesheets' => ['attendance'],
        // Lifecycle -> Leave/Advances/Accounting are guarded, and each one missing
        // makes the final settlement a smaller document rather than a broken one:
        // FinalSettlementBuilder returns 0 for encashment without `leave` and 0 for the
        // advance balance without `advances`, and the fixed-asset link is hidden
        // without `accounting`.
        //
        // Payroll is deliberately NOT listed. `final_settlements.payslip_id` is a
        // nullable column recording which existing path paid a settlement, and no PHP
        // here imports a Payslip — the settlement never writes one. That is the whole
        // of §4.6's "a proposal, not a posting", visible in the import graph.
        'lifecycle' => ['leave', 'advances', 'accounting', 'employees'],
        // Recruitment -> Employees/Payroll is the hire conversion, guarded so the
        // pipeline works at a company hiring its very first person: HireService::
        // isAvailable() hides the action without `employees`, and createPackage()
        // returns null without `payroll` rather than failing the hire.
        'recruitment' => ['employees', 'payroll'],
        // Performance -> MPR is the whole integration — a cycle READS the monthly
        // reports in its period rather than duplicating them — and -> Employees for the
        // reviewer. Both guarded; a cycle without MPR simply has no evidence attached.
        'performance' => ['mpr', 'employees'],
        // Quotations -> CRM/Inventory are guarded: a quote may be raised from a deal or
        // against a lead, and may carry product lines. Invoicing is a declared REQUIREMENT
        // rather than a coupling — §2: a quote that can never convert is a PDF generator.
        'quotations' => ['crm', 'inventory', 'invoicing'],
        // Support requires nothing and reaches three modules for optional context: the
        // customer, the engagement and the assignee. Each absent rather than broken.
        'support' => ['invoicing', 'projects', 'employees'],
        // Campaigns declares `crm` as a requirement (no audience without it) and reaches
        // Invoicing for the contacts half of a segment, which is guarded.
        'campaigns' => ['crm', 'invoicing'],
        // Construction -> Invoicing is guarded, not declared. A job's client and its certifier are both
        // Contacts, which Invoicing owns — but `construction` requires nothing on purpose
        // (docs/construction-management-plan.md §18: a contractor keeping its books elsewhere is a real
        // customer), so both pickers check modules()->enabled('invoicing') and the columns simply stay null
        // without it. A tender has no client record either way. Exactly the invoicing -> projects shape.
        //
        // `inventory` joins it for §6's site stores, built in Phase 8a: `construction_jobs.stock_location_id` names the
        // store a job keeps material in, and the picker is hidden without the module while the column stays null. §6
        // calls direct-to-site "the default" and says it "works with Inventory unlicensed" — most contractors, most of
        // the time — so this is the guarded shape again rather than a requirement.
        'construction' => ['invoicing', 'inventory'],
        // Construction costing -> Invoicing is guarded: a purchase order names a supplier, and a supplier is a
        // Contact, which Invoicing owns. `construction_costing` requires `construction` and `accounting` and
        // deliberately not Invoicing — a contractor can cost jobs and raise orders while buying nothing from this
        // application's invoicing — so the picker checks `modules()->enabled('invoicing')` and
        // `commitments.contact_id` stays null without it. An order to somebody with no contact record is still an
        // order, and still commits the money.
        //
        // `timesheets` joins it for Phase 7d's labour import (§7.1): where Timesheets is licensed its entries are
        // copied into draft labour records rather than read in place, because that table bills and never costs — its
        // ladder resolves charge-out rates, and costing a job from those overstates every margin by the mark-up.
        // `TimesheetLabourImport::isAvailable()` is the guard, the action is absent without it, and §18.1's row says
        // the absence leaves "site sheets only, which is the primary path anyway". Note what is *not* here:
        // `projects`, because the bridge from a timesheet entry to a job is the unconstrained
        // `construction_jobs.project_id` column, which this module reads as an integer and never as a `Project`.
        //
        // `inventory` joins it in Phase 8a: a goods-receipt line destined for a site store writes a `StockMovement` at
        // the job's location. Guarded three ways in `GoodsReceiptService::guardStoreLine()` — the module, a product on
        // the line, and a store on the job — and each absence is a refusal naming the fix rather than a quiet fallback
        // to direct, because a receipt costed as though it had been stocked makes materials-on-site wrong with nothing
        // saying so.
        'construction_costing' => ['invoicing', 'timesheets', 'inventory'],
        // Construction contracts -> Invoicing is guarded, and §18 calls this the sharpest fork in that
        // section. The other party on a contract is a Contact, and the certificate's *raise invoice* action
        // needs an Invoice — but a payment certificate is not a quote: it is itself a contractual instrument
        // that starts the payment period and that an adjudicator reads, and large contractors run
        // certification and invoicing in different departments. So Invoicing is not declared, the action is
        // absent without it, and the register with its retention ledger and printed forms is still the whole
        // deliverable.
        //
        // `accounting` joins it for the same hand-off: §10.4's invoice needs the retention-receivable and
        // contract-liability accounts, resolved through `ConstructionAccounts`. Guarded twice over — the action is
        // absent without Invoicing, and Invoicing requires Accounting — so the path cannot be reached with the
        // account map unavailable.
        //
        // `construction_costing` is the cross-sibling money path §18.2 anticipates, added by Phase 6c: issuing a
        // subcontract certificate relieves the commitment behind it, which is §5's "earlier of receipt or
        // certificate". Neither sibling declares the other — §18 sells certification without cost control and cost
        // control without certification — so it is guarded in `CertificateCommitmentService`, and **the direction is
        // forced rather than chosen**: pointing costing back at contracts as well would make the pair a cycle, and a
        // cycle cannot be a composer dependency. That is why `commitments.contract_id` is set from the contract's
        // own screen instead of by a picker on the order form, which is where anybody would look for it first.
        //
        // `construction_field` joins it in Phase 9c, and it exists to fix something the materials-on-site panel got
        // wrong: it went absent whenever `construction_costing` was off, and this module does not require that module.
        // A certifier saw no panel and could not tell whether nothing was on site or nothing was being tracked, which
        // is §18.1's healthy figure hiding an absence in the one place a figure is being certified. So the panel reads
        // the diary's flagged dockets and *names the source*. Guarded, and the direction is forced the same way the
        // costing edge is — `construction_field` never names a class of this module, so the graph stays acyclic.
        'construction_contracts' => ['invoicing', 'accounting', 'construction_costing', 'construction_field'],
        // Construction site operations -> Invoicing, and it is the smallest instance of the shape this section keeps
        // returning to: a diary's manpower line names the company that supplied the men, and a company is a Contact,
        // which Invoicing owns. `construction_field` requires only `construction` (§18), so the picker checks
        // `modules()->enabled('invoicing')` and `company_label` — a plain string beside the column — carries the name
        // without it. A diary must never be unfillable because of a licence.
        //
        // Note what is *not* here. The manpower line's trade and the plant line's machine both live in
        // `construction_costing`, and this module reads them out of `construction_trades` and
        // `construction_plant_items` with the query builder rather than naming `Trade` or `PlantItem` — the same
        // treatment §13's `contract_id` gets. Two integers and two labels are all a diary needs, and a model would
        // have bought nothing and cost the boundary.
        //
        // Phase 9c adds two more of the same shape and no new module: a delivery's contract item is read out of
        // `construction_contract_items`, and the goods receipt behind a docket out of `construction_goods_receipts`,
        // both with the query builder. **The photograph's location is the exception, and it is a real relation** — §16.5
        // built the location tree in the spine precisely so five subsystems could say *where*, and `construction` is
        // required, so naming `Location` costs the boundary nothing. Same for `Document`: promoting a photograph to the
        // ISO 19650 register is a spine call, which is why §15 put the register in the spine rather than here.
        'construction_field' => ['invoicing'],
    ];

    public function test_no_module_reaches_into_another_it_has_not_declared(): void
    {
        $unexpected = [];
        $seenPairs = [];

        foreach ($this->moduleDirectories() as $module => $directory) {
            $allowed = array_merge(
                $this->allowedTargets($module),
                self::KNOWN_COUPLINGS[$module] ?? [],
            );

            foreach ($this->importsIn($directory) as $file => $imports) {
                foreach ($imports as $import) {
                    $target = $this->moduleOf($import);

                    if ($target === null || $target === $module) {
                        continue;
                    }

                    if (in_array($target, self::KNOWN_COUPLINGS[$module] ?? [], true)) {
                        $seenPairs[] = "{$module} -> {$target}";
                    }

                    if (in_array($target, $allowed, true)) {
                        continue;
                    }

                    $unexpected[] = sprintf('%s imports %s [%s -> %s]', $file, $import, $module, $target);
                }
            }
        }

        $unexpected = array_values(array_unique($unexpected));

        $this->assertSame([], $unexpected, implode("\n", [
            'A new dependency between modules. Either:',
            '  - add it to `requires` in config/modules.php, if the module genuinely cannot',
            '    be enabled without the other (which also means it cannot be sold without it);',
            '  - guard the call site and add the pair to KNOWN_COUPLINGS with the reason;',
            '  - or move the shared code out of both modules.',
            '',
            ...$unexpected,
        ]));

        // A baseline nobody prunes stops describing the code. Anything fixed must
        // leave this list.
        $stale = [];

        foreach (self::KNOWN_COUPLINGS as $module => $targets) {
            foreach ($targets as $target) {
                if (! in_array("{$module} -> {$target}", $seenPairs, true)) {
                    $stale[] = "{$module} -> {$target}";
                }
            }
        }

        $this->assertSame([], $stale, implode("\n", [
            'These couplings no longer exist — remove them from KNOWN_COUPLINGS so the',
            'list keeps meaning something.',
            '',
            ...$stale,
        ]));
    }

    public function test_the_recorded_debt_does_not_hide_a_licence_dependency(): void
    {
        // The dangerous half of the list. A module may import another and stay
        // sellable separately only if the call sites degrade — the runtime guard is
        // what makes that true. Invoicing and Inventory declare Accounting, so they
        // are covered by the read-time requirement propagation instead; Payroll is
        // covered by PayrollPostingService.
        //
        // Anything added to KNOWN_COUPLINGS in the direction of a module that is
        // NOT declared and NOT guarded is a licence hole, so the guarded pairs are
        // named here explicitly rather than assumed.
        $guarded = [
            // Accounting for the posting, and Attendance and Leave for phase 3's pay
            // join. All three degrade, and the two new ones do so in the strongest
            // available sense: AttendanceFigures returns the zeros MonthlyPayrollService
            // always raised when the modules are off, and AttendanceProration then
            // refuses to divide by a zero `total_working_days` — so a company with
            // neither module gets the pre-phase-3 behaviour exactly, which
            // PayslipAttendanceProrationTest and ModuleDegradationTest both assert.
            //
            // The guards are in AttendanceFigures::for(), ::monthIsComplete(),
            // ::overtimeMinutes() and OvertimeRate::contractedHoursIn().
            //
            // Advances and Expenses joined the list once inline references were visible, and have now left
            // it entirely. The guard did not disappear — it moved to the far side of the contract, where
            // AdvanceService and ExpenseClaimService check their own licence and NoAdvanceLedger /
            // NoReimbursableClaims answer for a company that bought neither. Payroll's behaviour without
            // either module is unchanged, which is what PayslipAttendanceProrationTest and
            // ModuleDegradationTest assert; what changed is that it is no longer a cycle.
            'payroll' => ['accounting', 'attendance', 'leave'],
            // A client with no advances has nothing to credit back, so Billing has
            // to be sellable without the module; creditLines() returns none when it
            // is off. Timesheets used to be the same and is no longer here at all: the
            // guard moved to the far side of `BillableTime`, where NoBillableTime bills
            // nothing and BillableHours::priceFor() checks the licence itself. Billing
            // asking "is timesheets enabled" was Billing knowing about Timesheets by
            // another name.
            'billing' => ['advances'],

            // A claim's category is a TransactionType and its alternative settlement
            // is a Payment, both optional: the category is nullable and a claim is
            // reimbursed through the payslip, not the ledger. Expenses declares
            // Payroll, which is where the money actually reaches the employee, and
            // does not declare Accounting for the same reason Payroll does not —
            // requiring it would make the module unsellable to a company that keeps
            // its books elsewhere.
            'expenses' => ['accounting'],

            // A job's client and certifier are Contacts, and `construction` requires nothing — so both
            // pickers are hidden without Invoicing and the columns stay null. The job is still a job:
            // §18.1's "smaller, never broken", and the reason construction is sellable to a contractor
            // whose books are somewhere else.
            //
            // Inventory joins it in Phase 8a for the job's site store, hidden without the module.
            'construction' => ['invoicing', 'inventory'],

            // A purchase order's supplier is a Contact, and the picker is hidden without Invoicing while the
            // column stays null. The same shape as the job's client, one module along.
            //
            // Timesheets degrades to the import action not being offered at all, and `isAvailable()` refuses the
            // service in one sentence if anything reaches it another way. A contractor without that module records
            // labour on site sheets, which is how most site labour is recorded regardless.
            //
            // Inventory degrades to a refusal naming what is missing, which §18.1's exception requires: a store
            // receipt accepted without a movement would leave materials-on-site wrong with nothing saying so.
            'construction_costing' => ['invoicing', 'timesheets', 'inventory'],

            // The same shape one level up: the other party on a contract is a Contact and the certificate
            // becomes a draft invoice, both guarded. §18's refusal to declare Invoicing here is deliberate
            // and is why `certificates.invoice_id` is nullable rather than the module requiring the key.
            // Accounting comes with the hand-off: the invoice's retention line needs an asset account, and
            // getting that wrong is the misstatement §10.4 exists to prevent.
            //
            // Costing degrades to doing nothing at all: `CertificateCommitmentService::canRelieve()` is false,
            // so a certificate is issued exactly as it was before Phase 6c and the orders relation manager does
            // not appear. A contractor certifying subcontractors while keeping cost control elsewhere has no
            // commitment ledger for a certificate to relieve, which is the whole reason the two are sold apart.
            //
            // The site diary joins it in Phase 9c and degrades to one placeholder instead of two: the
            // materials-on-site panel shows the stock figure and no site corroboration beside it. **Both absent is the
            // case that was wrong before 9c** — the panel vanished entirely, so a certifier could not tell whether
            // nothing was on site or nothing was being tracked. It now says which source it used, or that there is
            // none.
            'construction_contracts' => ['invoicing', 'accounting', 'construction_costing', 'construction_field'],

            // A diary's manpower line names the company that supplied the men. Hidden without Invoicing, with the
            // free-text company name carrying it — the same shape as the job's client, two modules along.
            //
            // Phase 9c's delivery and photograph children add no target here. The contract item, the goods receipt and
            // the fleet register are all read with the query builder, and the photograph's location and the register
            // container it can be promoted into are both in `construction`, which this module requires.
            'construction_field' => ['invoicing'],
        ];

        foreach ($guarded as $module => $targets) {
            $this->assertSame(
                $targets,
                self::KNOWN_COUPLINGS[$module],
                ucfirst($module).' gained a coupling that is not the documented, guarded one. '
                .'Confirm the new call sites degrade when that module is unavailable.'
            );
        }
    }

    public function test_the_module_graph_is_acyclic(): void
    {
        // The progress bar for docs/module-packaging-plan.md, and a ratchet in the
        // same shape as KNOWN_COUPLINGS: the count may fall, never rise, and a
        // budget that no longer matches the code fails just as loudly as a
        // regression does.
        //
        // Composer forbids circular requires between packages, so every cycle here
        // is a module that cannot be extracted until the cycle is broken. The graph
        // is built from what the code actually does — every module -> module
        // reference the scan finds, plus every declared requirement — rather than
        // from KNOWN_COUPLINGS, which by construction cannot show `X -> core` and
        // therefore cannot show six of the cycles.
        $components = $this->tangledComponents($this->moduleGraph());
        $trapped = array_sum(array_map('count', $components));

        $report = array_map(
            fn (array $component): string => sprintf('  [%d] %s', count($component), implode(', ', $component)),
            $components,
        );

        $this->assertLessThanOrEqual(self::TANGLED_MODULE_BUDGET, $trapped, implode("\n", [
            sprintf('Modules trapped in a cycle: %d, budget %d.', $trapped, self::TANGLED_MODULE_BUDGET),
            'A cycle cannot be expressed as a composer dependency, so this is the one',
            'kind of coupling that packaging cannot tolerate at all.',
            '',
            ...$report,
        ]));

        $this->assertGreaterThanOrEqual(self::TANGLED_MODULE_BUDGET, $trapped, implode("\n", [
            sprintf(
                'TANGLED_MODULE_BUDGET is stale: %d modules are trapped, budget says %d.',
                $trapped,
                self::TANGLED_MODULE_BUDGET,
            ),
            'Lower it in the same commit that broke the cycle, or the ratchet slips.',
            '',
            ...$report,
        ]));
    }

    public function test_the_lint_has_something_to_check(): void
    {
        // A boundary test that scans nothing passes forever.
        $modules = $this->moduleDirectories();

        $this->assertGreaterThanOrEqual(8, count($modules));

        $total = 0;

        foreach ($modules as $directory) {
            $total += count($this->importsIn($directory));
        }

        $this->assertGreaterThan(100, $total, 'Expected to be scanning the whole of app/Modules.');
    }

    public function test_shared_namespaces_do_not_reach_into_modules(): void
    {
        // The inverse direction, and the one that would quietly undo the whole
        // structure: if App\Support or App\Http grows an import from
        // App\Modules\Accounting, that "shared" code is really accounting code and
        // every other module now depends on Accounting through the back door.
        //
        // Console commands, providers, listeners and the panel are excluded: those
        // exist precisely to wire modules together.
        $scanned = ['app/Support', 'app/Http', 'app/Filament/Concerns', 'app/Models'];

        $violations = [];
        $seen = [];

        foreach ($scanned as $relative) {
            $directory = base_path($relative);

            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach ($this->importsIn($directory) as $file => $imports) {
                $permitted = array_merge(['core'], self::SHARED_DEBT[$file] ?? []);

                foreach ($imports as $import) {
                    $module = $this->moduleOf($import);

                    if ($module === null || $module === 'core') {
                        continue;
                    }

                    if (array_key_exists($file, self::SHARED_DEBT)) {
                        $seen[] = $file;
                    }

                    if (! in_array($module, $permitted, true)) {
                        $violations[] = "{$file} imports {$import}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)), implode("\n", [
            'Shared code must not depend on a module. Anything here that needs a',
            'module\'s classes belongs in that module — or, if it genuinely cannot yet,',
            'it goes in SHARED_DEBT with the reason and a way out.',
            '',
            ...array_unique($violations),
        ]));

        // Same discipline as KNOWN_COUPLINGS: an entry that no longer describes the
        // code has to go, or the list stops meaning anything.
        $stale = array_values(array_diff(array_keys(self::SHARED_DEBT), array_unique($seen)));

        $this->assertSame([], $stale, implode("\n", [
            'These files no longer reach into a module — remove them from SHARED_DEBT.',
            '',
            ...$stale,
        ]));
    }

    /**
     * Every module -> module edge the code actually has: references the scan finds,
     * plus declared requirements.
     *
     * @return array<string, array<int, string>>
     */
    private function moduleGraph(): array
    {
        $graph = [];

        foreach ($this->moduleDirectories() as $module => $directory) {
            $targets = [];

            foreach ($this->importsIn($directory) as $imports) {
                foreach ($imports as $import) {
                    $target = $this->moduleOf($import);

                    if ($target !== null && $target !== $module) {
                        $targets[$target] = true;
                    }
                }
            }

            foreach (Modules::requirements($module) as $required) {
                if ($required !== $module) {
                    $targets[$required] = true;
                }
            }

            $graph[$module] = array_keys($targets);
        }

        return $graph;
    }

    /**
     * The modules trapped in a cycle, as strongly connected components of more
     * than one member.
     *
     * Counting *elementary cycles* would be the more literal reading, and it is
     * the wrong measure here: their number is exponential in a dense graph, so the
     * test would hang long before it reported anything, and the count would swing
     * wildly for a single edge. A module is extractable or it is not, and an SCC
     * answers exactly that — every member of one is unextractable, and breaking a
     * component always splits or shrinks it.
     *
     * Tarjan's algorithm, iterative rather than recursive so a wide component
     * cannot exhaust the stack.
     *
     * @param  array<string, array<int, string>>  $graph
     * @return array<int, array<int, string>>
     */
    private function tangledComponents(array $graph): array
    {
        $index = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $next = 0;
        $components = [];

        foreach (array_keys($graph) as $root) {
            if (array_key_exists($root, $index)) {
                continue;
            }

            // Each frame is [node, position in that node's edge list].
            $frames = [[$root, 0]];
            $index[$root] = $low[$root] = $next++;
            $stack[] = $root;
            $onStack[$root] = true;

            while ($frames !== []) {
                [$node, $edge] = $frames[count($frames) - 1];
                $targets = $graph[$node] ?? [];

                if ($edge < count($targets)) {
                    $frames[count($frames) - 1][1]++;
                    $target = $targets[$edge];

                    if (! array_key_exists($target, $graph)) {
                        continue;
                    }

                    if (! array_key_exists($target, $index)) {
                        $index[$target] = $low[$target] = $next++;
                        $stack[] = $target;
                        $onStack[$target] = true;
                        $frames[] = [$target, 0];
                    } elseif ($onStack[$target] ?? false) {
                        $low[$node] = min($low[$node], $index[$target]);
                    }

                    continue;
                }

                array_pop($frames);

                if ($frames !== []) {
                    $parent = $frames[count($frames) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                }

                if ($low[$node] !== $index[$node]) {
                    continue;
                }

                $component = [];

                do {
                    $member = array_pop($stack);
                    $onStack[$member] = false;
                    $component[] = $member;
                } while ($member !== $node);

                if (count($component) > 1) {
                    sort($component);
                    $components[] = $component;
                }
            }
        }

        // A module that requires or imports itself would be a one-member cycle,
        // which Tarjan reports as a trivial component. Nothing does this today and
        // the check costs one pass.
        foreach ($graph as $node => $targets) {
            if (in_array($node, $targets, true)) {
                $components[] = [$node];
            }
        }

        return $components;
    }

    /**
     * @return array<int, string>
     */
    private function allowedTargets(string $module): array
    {
        $allowed = ['core'];

        foreach (Modules::requirements($module) as $required) {
            $allowed[] = $required;
            $allowed = array_merge($allowed, $this->allowedTargets($required));
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @return array<string, string> module key => directory
     */
    private function moduleDirectories(): array
    {
        $directories = [];

        foreach (File::directories(app_path('Modules')) as $directory) {
            $key = Str::snake(basename($directory));

            if (array_key_exists($key, Modules::registry())) {
                $directories[$key] = $directory;
            }
        }

        return $directories;
    }

    /**
     * References per file: `use` statements *and* fully-qualified inline
     * references such as `\App\Modules\Payroll\Models\Payslip::class`.
     *
     * The inline half was missing and it hid the single largest edge in the
     * codebase. `app/Support/ModuleMap.php` has one `use` statement and 222
     * inline references spanning every module — so the class that maps modules
     * to their classes was invisible to the test built to find exactly that.
     *
     * Comments are stripped before matching, and that is not a nicety. A docblock
     * explaining a bug by quoting `\App\Modules\Payroll\Models\Payslip::class` is
     * prose, not a dependency — and a naive regex reads it as one, which invents
     * an edge and, worse, keeps a real edge's KNOWN_COUPLINGS entry looking alive
     * long after the code was fixed. That is precisely the staleness this file
     * exists to prevent, so the scan tokenises rather than pattern-matches.
     *
     * @return array<string, array<int, string>>
     */
    private function importsIn(string $directory): array
    {
        $imports = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $this->withoutComments(File::get($file->getPathname()));

            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', $source, $used);
            preg_match_all('/\\\\(App\\\\Modules\\\\[A-Za-z0-9_\\\\]+)\s*(?=::|\()/', $source, $inline);

            $references = array_values(array_unique(array_merge($used[1], $inline[1])));

            if ($references !== []) {
                $imports[Str::after($file->getPathname(), base_path().'/')] = $references;
            }
        }

        return $imports;
    }

    /**
     * The same source with every comment and docblock replaced by whitespace, so
     * line structure survives for the `^use` pattern.
     */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /**
     * The module a class belongs to, judged by namespace. Shared namespaces and
     * vendor classes return null.
     */
    private function moduleOf(string $class): ?string
    {
        foreach (self::SHARED_NAMESPACES as $shared) {
            if (Str::startsWith($class, $shared.'\\')) {
                return null;
            }
        }

        if (! preg_match('/^App\\\\Modules\\\\([A-Za-z0-9]+)\\\\/', $class, $matches)) {
            return null;
        }

        $module = Str::snake($matches[1]);

        return array_key_exists($module, Modules::registry()) ? $module : null;
    }
}
