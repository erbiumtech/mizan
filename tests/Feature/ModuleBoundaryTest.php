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
     * How many modules may still be trapped in a dependency cycle.
     *
     * Ratchets down, one phase of docs/module-packaging-plan.md at a time, and the
     * test fails in both directions — a cycle added, or a budget left stale after
     * one was broken. Zero is the goal and the point at which every module becomes
     * extractable as a composer package.
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
     */
    private const TANGLED_MODULE_BUDGET = 13;

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
        // Advances and Expenses are reached through the container with an inline
        // FQCN, which was invisible until importsIn() learned to see inline
        // references. The container call was chosen deliberately — both modules
        // *require* payroll, so a `use` statement would have made the pair a cycle
        // — but the dependency is structural either way: the class has to be on
        // disk for `app()` to resolve it. Recording it is the honest position, and
        // it says out loud that payroll <-> advances and payroll <-> expenses are
        // cycles that a package split would have to break.
        'payroll' => ['accounting', 'attendance', 'leave', 'advances', 'expenses'],
        // Timesheets is the same shape and the same discovery: the hours lines are
        // fetched via app(BillableHours::class) behind modules()->enabled('timesheets'),
        // which kept Billing sellable to a headcount-billed client and kept the
        // pair out of this list. The guard is real; the invisibility was not.
        'billing' => ['advances', 'timesheets'],
        'expenses' => ['accounting'],

        // Debt.
        'accounting' => ['employees', 'payroll', 'invoicing', 'inventory'],
        'core' => ['accounting', 'payroll', 'invoicing', 'inventory', 'employees'],
        // Employees -> Payroll is a third inline-reference find: EmployeeSetting
        // hasMany EmployeeSettingComponent, and the components relation manager
        // reads PayComponent. Payroll *requires* employees, so this is a cycle,
        // and unlike the two above it is not guarded at all — the relation is
        // simply declared. It is debt, not design.
        // Employees -> Accounting was here for `Employee::bank()`, and it is gone: Bank is a
        // reference table three modules read, so it moved to Core, where a dependency is free. See
        // docs/module-packaging-plan.md §7 and App\Modules\Core\Models\Bank.
        'employees' => ['projects', 'payroll'],
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
            // Advances and Expenses join the list once inline references are visible.
            // Both are guarded in the strongest sense — PayslipService::
            // advanceInstalmentFor() and ::expenseClaimsFor() return 0.0 when the
            // module is off, and Payslip's saved() hook returns before touching
            // AdvanceService — so payroll's behaviour without either module is
            // unchanged. What changed is that the coupling is now recorded.
            'payroll' => ['accounting', 'attendance', 'leave', 'advances', 'expenses'],
            // A client with no advances has nothing to credit back, so Billing has
            // to be sellable without the module; creditLines() returns none when it
            // is off. Timesheets is the same: hoursLines() returns [] and lockFor()
            // is skipped, both behind modules()->enabled('timesheets'), so a
            // headcount-billed client never reaches the class.
            'billing' => ['advances', 'timesheets'],

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
