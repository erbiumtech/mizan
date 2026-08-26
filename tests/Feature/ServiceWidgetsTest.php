<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Support\Filament\Widgets\SlaComplianceOverview;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Modules\Support\Services\TicketService;
use App\Modules\Timesheets\Filament\Widgets\BillableShareOverview;
use App\Modules\Timesheets\Filament\Widgets\UnbilledWipOverview;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\TimesheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The service widgets — `docs/reports-expansion-plan.md` Phase 5.4.
 *
 * Two things are under test beyond the arithmetic, and both are places where following the plan literally
 * would have been wrong:
 *
 *  - **the plan names `TimesheetService::utilisationFor()` and this uses `utilisation()`.** The former answers
 *    for one employee and reaches `AttendanceCalendar::summarise()`, which walks every day of a month; the
 *    service's own docblock calls looping it over a company "hundreds of queries for one screen ... the exact
 *    fault `docs/page-load-performance-plan.md` was written about". The company-wide method is three queries
 *    a month whatever the headcount, and is what the Timesheet Utilisation report uses.
 *  - **the plan asks for "billable utilisation" and this reports billable *share*.** `TimesheetService`
 *    refuses to state capacity, deliberately and with a reason — a rule making timesheets and attendance
 *    reconcile "would make people book the difference somewhere to make the screen agree". A percentage
 *    against an invented denominator would undo that decision on the one screen where it reads as fact.
 *
 * And the SLA widget's two halves read the period differently, which is the plan's own wording: compliance is
 * a rate over a window, breaches are "outstanding **now**".
 */
class ServiceWidgetsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const TODAY = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['support', 'timesheets', 'projects', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ────────────────────────────────────────────────── fixtures ──

    private function employee(string $name = 'Ayesha'): Employee
    {
        return Employee::firstOrCreate(
            ['employee_id' => 'EMP-'.mb_substr(md5($name), 0, 4)],
            ['name' => $name, 'date_of_joining' => '2024-01-01', 'status' => 1],
        );
    }

    private function project(string $name = 'Acme Portal'): Project
    {
        return Project::firstOrCreate(
            ['code' => mb_strtoupper(mb_substr($name, 0, 4))],
            ['name' => $name, 'status' => 'active'],
        );
    }

    /**
     * A timesheet entry, approved.
     *
     * `timesheets.require_approval_to_bill` defaults to **true**, so `unbilledWip()` applies the `approved()`
     * scope — unapproved time is not work in progress a company can bill, and leaving `approved_at` null
     * reported every WIP figure as nought. That is the service being right and the fixture being unrealistic.
     */
    private function entry(string $date, int $minutes, bool $billable, ?Employee $employee = null): TimesheetEntry
    {
        return TimesheetEntry::create([
            'employee_id' => ($employee ?? $this->employee())->getKey(),
            'project_id' => $this->project()->getKey(),
            'date' => $date,
            'minutes' => $minutes,
            'is_billable' => $billable,
            'approved_at' => $date.' 17:00:00',
            'description' => 'Work',
        ]);
    }

    // ─────────────────── SLA compliance ──

    /** With no tickets it is a dash, not nought per cent. */
    public function test_no_tickets_is_a_dash_not_a_failure(): void
    {
        Livewire::test(SlaComplianceOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('no tickets in this period')
            ->assertSee('nothing open is past its commitment');
    }

    /**
     * A quiet month is not a total failure, which is what a nought would put on the dashboard.
     *
     * Asserted as its own case because the arithmetic tempts a division that would either error or report
     * the worst possible figure for the best possible month.
     */
    public function test_the_rate_does_not_divide_by_nothing(): void
    {
        $stats = $this->slaStats(['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY]);

        $this->assertStringNotContainsString('0.0%', $stats);
    }

    /**
     * Breaches are counted now, whatever the period — the plan's own wording.
     *
     * A breach outstanding in March and since resolved is not something to act on today, so `breaches()`
     * takes no window at all. This is the one figure on the widget the period does not move, and the widget
     * says so rather than leaving a reader to assume it does.
     */
    public function test_breaches_are_counted_now_whatever_the_period(): void
    {
        // A period well before anything exists. The breach count must be unaffected by it.
        $narrow = $this->slaStats(['periodFrom' => '2020-01-01', 'periodTo' => '2020-01-31']);

        $this->assertStringContainsString('nothing open is past its commitment', $narrow);
        $this->assertStringContainsString('whatever the period', $this->breachDescription());
    }

    /** The compliance figure is the ticket service's own. */
    public function test_compliance_agrees_with_the_ticket_service(): void
    {
        $category = TicketCategory::create(['name' => 'Support', 'sla_response_minutes' => 60, 'sla_resolution_minutes' => 240]);

        Ticket::create([
            'subject' => 'Broken',
            'category_id' => $category->getKey(),
            'status' => Ticket::STATUS_RESOLVED,
            'opened_at' => '2027-02-10 09:00:00',
            'first_responded_at' => '2027-02-10 09:30:00',
            'resolved_at' => '2027-02-10 10:00:00',
        ]);

        // A second ticket that blew its four-hour resolution commitment, so the rate cannot be 100% — this
        // test previously passed on an *uncategorised* ticket, which has no SLA to breach and therefore
        // counts as met. The category key is `category_id`; `ticket_category_id` was silently dropped by
        // mass assignment and took two assertions with it.
        Ticket::create([
            'subject' => 'Still broken',
            'category_id' => $category->getKey(),
            'status' => Ticket::STATUS_RESOLVED,
            'opened_at' => '2027-02-10 09:00:00',
            'first_responded_at' => '2027-02-10 09:10:00',
            'resolved_at' => '2027-02-12 09:00:00',
        ]);

        $rows = app(TicketService::class)->performance('2027-02-01', self::TODAY);
        $tickets = array_sum(array_column($rows, 'tickets'));
        $met = array_sum(array_column($rows, 'met_resolution'));

        $this->assertSame(2, $tickets, 'the fixture did not land in the window');
        $this->assertSame(1, $met, 'the fixture cannot tell a met commitment from a breached one');
        $this->assertSame('Support', $rows[0]['label'], 'the tickets were uncategorised, so no SLA applied');

        $this->assertStringContainsString(
            number_format($met / $tickets * 100, 1).'%',
            $this->slaStats(['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY]),
        );
    }

    private function slaStats(array $params): string
    {
        return Livewire::test(SlaComplianceOverview::class, $params)->assertSuccessful()->html();
    }

    private function breachDescription(): string
    {
        Ticket::create([
            'subject' => 'Late',
            'category_id' => TicketCategory::create([
                'name' => 'Urgent',
                'sla_response_minutes' => 1,
                'sla_resolution_minutes' => 1,
            ])->getKey(),
            'status' => Ticket::STATUS_OPEN,
            'opened_at' => '2027-01-01 09:00:00',
        ]);

        return $this->slaStats(['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY]);
    }

    // ─────────────────── billable share ──

    /** The share is billable hours over booked hours. */
    public function test_the_billable_share_is_billable_over_booked(): void
    {
        $this->entry('2027-02-10', 360, billable: true);
        $this->entry('2027-02-11', 120, billable: false);

        Livewire::test(BillableShareOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('75%')
            ->assertSee('by one person');
    }

    /**
     * **No capacity figure and no percentage against one.**
     *
     * `TimesheetService` refuses to state expected hours, and gives the reason: a rule making timesheets and
     * attendance reconcile "would make people book the difference somewhere to make the screen agree, which
     * produces worse data than the gap it closed". A dashboard percentage against an invented denominator
     * would undo that quietly, in the place it reads as fact. This asserts the widget says nothing about
     * capacity.
     */
    public function test_the_widget_states_no_capacity(): void
    {
        $this->entry('2027-02-10', 360, billable: true);

        $html = Livewire::test(BillableShareOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->html();

        foreach (['capacity', 'expected hours', 'available'] as $word) {
            $this->assertStringNotContainsString($word, mb_strtolower($html));
        }
    }

    /**
     * Every month the period touches is counted, not just the last one.
     *
     * `utilisation()` answers per month by design, so a quarter is three calls. Showing one month under a
     * label that says "this quarter" would be the easy version and the wrong one.
     */
    public function test_every_month_in_the_period_is_counted(): void
    {
        $this->entry('2026-12-10', 600, billable: true);
        $this->entry('2027-01-10', 600, billable: true);
        $this->entry('2027-02-10', 600, billable: true);

        Livewire::test(BillableShareOverview::class, ['periodFrom' => '2026-12-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('30');
    }

    /**
     * Somebody who booked in one month and not another is one person, not two.
     *
     * Counted across the span rather than summed per month, which would report a headcount larger than the
     * company.
     */
    public function test_a_person_booking_in_two_months_is_counted_once(): void
    {
        $ayesha = $this->employee('Ayesha');
        $this->entry('2027-01-10', 300, billable: true, employee: $ayesha);
        $this->entry('2027-02-10', 300, billable: true, employee: $ayesha);

        Livewire::test(BillableShareOverview::class, ['periodFrom' => '2027-01-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('by one person');
    }

    /** With nothing booked it is a dash rather than nought per cent. */
    public function test_nothing_booked_is_a_dash(): void
    {
        Livewire::test(BillableShareOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('nothing was booked in this period');
    }

    /** The figures agree with the utilisation service the report uses. */
    public function test_the_share_agrees_with_the_utilisation_service(): void
    {
        $this->entry('2027-02-10', 480, billable: true);
        $this->entry('2027-02-11', 120, billable: false);

        $rows = app(TimesheetService::class)->utilisation(2027, 2);
        $billable = array_sum(array_column($rows, 'billable_hours'));
        $booked = array_sum(array_column($rows, 'booked_hours'));

        Livewire::test(BillableShareOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee(round($billable / $booked * 100, 1).'%');
    }

    // ─────────────────── unbilled WIP ──

    /** The balance is the WIP service's own, and it names the projects it spans. */
    public function test_unbilled_wip_agrees_with_the_service(): void
    {
        $this->entry('2027-02-10', 480, billable: true);

        $wip = app(TimesheetService::class)->unbilledWip(self::TODAY);

        Livewire::test(UnbilledWipOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee(number_format($wip['hours'], 1))
            ->assertSee('1 project');
    }

    /**
     * It is an as-at, not a span.
     *
     * Work in progress is a balance — everything billable, unbilled and recorded on or before a date — so the
     * period sets the date. Read at a year end it gives the WIP that stood there, which is what somebody
     * accrues against.
     */
    public function test_unbilled_wip_reads_as_at_the_period_end(): void
    {
        $this->entry('2027-01-10', 480, billable: true);
        $this->entry('2027-02-10', 480, billable: true);

        $january = app(TimesheetService::class)->unbilledWip('2027-01-31');
        $february = app(TimesheetService::class)->unbilledWip(self::TODAY);

        $this->assertNotSame($january['hours'], $february['hours'], 'the fixture cannot tell the dates apart');

        Livewire::test(UnbilledWipOverview::class, ['periodTo' => '2027-01-31'])
            ->assertSuccessful()
            ->assertSee(number_format($january['hours'], 1));
    }

    /**
     * Unpriced hours are stated apart from the amount, because they are the finding.
     *
     * Time with no rate cannot be valued: it is real work that will be invoiced at a number nobody has
     * decided, and folding it into the amount would understate the balance while looking complete.
     */
    public function test_unpriced_hours_are_stated_separately(): void
    {
        $this->entry('2027-02-10', 480, billable: true);

        $html = Livewire::test(UnbilledWipOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->html();

        $this->assertTrue(
            str_contains($html, 'billable time with no rate') || str_contains($html, 'every billable hour has a rate'),
            'the widget says nothing about unpriced time',
        );
    }

    /** With nothing outstanding it still renders. */
    public function test_unbilled_wip_renders_with_nothing_outstanding(): void
    {
        Livewire::test(UnbilledWipOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('0 projects');
    }

    // ─────────────────── the group's own rules ──

    /** All three take the period, are lazy, and sit in the service band. */
    public function test_the_service_widgets_follow_the_dashboard_rules(): void
    {
        foreach ([
            SlaComplianceOverview::class => 30,
            BillableShareOverview::class => 31,
            UnbilledWipOverview::class => 32,
        ] as $widget => $sort) {
            $this->assertTrue(property_exists($widget, 'periodTo'), class_basename($widget).' takes no period');
            $this->assertTrue((new \ReflectionProperty($widget, 'isLazy'))->getValue(), class_basename($widget).' is not lazy');
            $this->assertSame($sort, (new \ReflectionProperty($widget, 'sort'))->getValue());
        }
    }

    /** Each gates on its module. */
    public function test_each_widget_is_gated_on_its_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())->update(['enabled' => false]);
        modules()->flush();

        foreach ([SlaComplianceOverview::class, BillableShareOverview::class, UnbilledWipOverview::class] as $widget) {
            $this->assertFalse($widget::canView(), class_basename($widget).' renders without its module');
        }
    }
}
