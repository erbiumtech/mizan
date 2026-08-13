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
        'payroll' => ['accounting', 'attendance', 'leave'],
        'billing' => ['advances'],
        'expenses' => ['accounting'],

        // Debt.
        'accounting' => ['employees', 'payroll', 'invoicing', 'inventory'],
        'core' => ['accounting', 'payroll', 'invoicing', 'inventory', 'employees', 'mpr'],
        'employees' => ['projects', 'accounting'],
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
        'attendance' => ['leave', 'payroll', 'employees'],
        // Leave -> Attendance is the other half of the same coupling: with attendance
        // licensed the leave-day generator reads the employee's work pattern instead
        // of leave.weekend_days, which config/leave.php always described as a stopgap.
        'leave' => ['attendance'],
        // Timesheets -> Billing and -> Attendance are guarded. BillableHours takes a
        // BillingRun to price a month, and the utilisation report compares booked time
        // with the attendance record; both are absent rather than broken without those
        // modules. Note the direction: Billing does NOT import Timesheets — it asks for
        // the hours lines through the container behind modules()->enabled('timesheets'),
        // so Billing stays sellable to a headcount-billed client.
        'timesheets' => ['billing', 'attendance'],
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
        'mpr' => ['employees'],
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
            'payroll' => ['accounting', 'attendance', 'leave'],
            // A client with no advances has nothing to credit back, so Billing has
            // to be sellable without the module; creditLines() returns none when it
            // is off. Note that Payroll reaches Advances the other way — through the
            // container, with no import — because Advances *requires* Payroll and an
            // import would make the pair a cycle. That call site is guarded too, in
            // PayslipService::advanceInstalmentFor().
            'billing' => ['advances'],

            // A claim's category is a TransactionType and its alternative settlement
            // is a Payment, both optional: the category is nullable and a claim is
            // reimbursed through the payslip, not the ledger. Expenses declares
            // Payroll, which is where the money actually reaches the employee, and
            // does not declare Accounting for the same reason Payroll does not —
            // requiring it would make the module unsellable to a company that keeps
            // its books elsewhere.
            'expenses' => ['accounting'],
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

        // Employee is referenced by the employee-access helpers, which exist
        // precisely because Payroll, Projects and MPR all need the same "which
        // employees may this user see" rule. Moving them into Employees would give
        // three modules a dependency on a helper none of them owns, so the
        // reference stays and is named here.
        $allowed = ['core', 'employees'];
        $violations = [];

        foreach ($scanned as $relative) {
            $directory = base_path($relative);

            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach ($this->importsIn($directory) as $file => $imports) {
                foreach ($imports as $import) {
                    $module = $this->moduleOf($import);

                    if ($module !== null && ! in_array($module, $allowed, true)) {
                        $violations[] = "{$file} imports {$import}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)), implode("\n", [
            'Shared code must not depend on a module. Anything here that needs a',
            'module\'s classes belongs in that module.',
            '',
            ...array_unique($violations),
        ]));
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
     * Imports per file. `use` statements only: a fully-qualified reference inside
     * a string or a docblock is not a structural dependency, and PHP files here
     * import what they use.
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

            $source = File::get($file->getPathname());

            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', $source, $matches);

            if ($matches[1] !== []) {
                $imports[Str::after($file->getPathname(), base_path().'/')] = $matches[1];
            }
        }

        return $imports;
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
