<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Support\Filament\Pages\SlaBreaches;
use App\Modules\Support\Filament\Pages\SlaPerformance;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The two SLA reports — `docs/reports-expansion-plan.md` Phase 1.3.
 *
 * `TicketService::performance()` and `breaches()` were implemented, tested and called from nothing a user
 * could reach. `CrmSupportAndCampaignTest` owns the clock arithmetic — an internal note not starting the
 * response clock, a category with no commitment never breaching, a breach not blocking a close — and this
 * file does not re-assert any of it.
 *
 * What it asserts is what turning those two methods into reports adds:
 *
 *  - **The window, and which date it is measured on.** Tickets *opened* in the month, not resolved in it:
 *    an SLA is a promise made when a ticket arrives, and grouping by resolution would silently drop every
 *    ticket still in breach — the population the report exists to show.
 *  - **That two groupings in one table do not double-count.** The totals row adds up the category rows
 *    alone. Summing both halves counts every ticket twice and looks entirely plausible.
 *  - **How an absence is stated.** No ratings is not a bad score; no tickets is not a nought per cent
 *    success rate; a breached ticket with nobody on it is the worst row in the table and must not read as
 *    missing data.
 *  - **That the reports never claim to enforce anything.** The module's rule is that the clocks are
 *    measured and not enforced, and a percentage that looks like a penalty invites somebody to close
 *    tickets to improve it.
 *
 * Time is frozen. Every breach figure in this module is measured against `now()`, so a report over a past
 * month would show every ticket in it as catastrophically overdue — correctly, and uselessly for a test.
 */
class SupportReportsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** Midday, mid-month, mid-week. Far enough from every boundary that no rounding matters. */
    private const NOW = '2026-08-20 12:00:00';

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::NOW);

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['support', 'employees'] as $module) {
            $this->setModule($module, true);
        }
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function category(string $name, ?int $response = 60, ?int $resolution = 480): TicketCategory
    {
        return TicketCategory::create([
            'name' => $name,
            'sla_response_minutes' => $response,
            'sla_resolution_minutes' => $resolution,
        ]);
    }

    private function makeTicket(TicketCategory $category, string $openedAt, array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'category_id' => $category->id,
            'subject' => 'The reader is offline',
            'opened_at' => $openedAt,
        ], $attributes));
    }

    private function makeEmployee(string $id): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'name' => $id,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
    }

    /** @return array<string, mixed> */
    private function report(string $key, ?string $asOf = null): array
    {
        $payload = ReportRenderers::render($key, $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, "no renderer is registered for {$key}");

        return $payload;
    }

    private function cells(array $payload): string
    {
        return collect($payload['rows'])->flatten()->implode(' | ');
    }

    /** The row whose first cell contains a phrase — the buckets are labelled, not positional. */
    private function row(array $payload, string $contains): array
    {
        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $contains)) {
                return $row;
            }
        }

        $this->fail("no row labelled [{$contains}] in:\n".$this->cells($payload));
    }

    // ─────────────────────────────────────────────────────────── performance ──

    /**
     * Opened in the month, not resolved in it.
     *
     * An SLA is the promise made when a ticket arrives. Measured on the resolution date, every ticket
     * still open would drop out of every month's figures — so the report would improve as the backlog got
     * worse, which is the most dangerous direction for a service measure to lie in.
     */
    public function test_performance_counts_the_tickets_opened_in_the_month(): void
    {
        $general = $this->category('General');

        $this->makeTicket($general, '2026-08-02 09:00:00');
        // Opened last month and still open: last month's promise, however long it has been running.
        $this->makeTicket($general, '2026-07-28 09:00:00');
        // Opened next month: not yet anybody's promise.
        $this->makeTicket($general, '2026-09-01 09:00:00');

        $payload = $this->report('SlaPerformance');

        $this->assertStringContainsString('2026-08-01', $payload['subtitle']);
        $this->assertStringContainsString('2026-08-31', $payload['subtitle']);
        $this->assertSame(1.0, $payload['tiles'][0]['value']);
    }

    /**
     * Both groupings, category first, and the total counts each ticket once.
     *
     * Two groupings in one table is the arithmetic trap in this report: the by-assignee rows are the same
     * tickets again, so a total summing every row would double every figure and still look like a total.
     */
    public function test_performance_shows_both_groupings_without_double_counting(): void
    {
        $general = $this->category('General');
        $urgent = $this->category('Urgent', response: 15, resolution: 60);
        $ali = $this->makeEmployee('EMP-1');
        $sara = $this->makeEmployee('EMP-2');

        $this->makeTicket($general, '2026-08-20 11:45:00', ['assignee_employee_id' => $ali->id]);
        $this->makeTicket($general, '2026-08-20 11:50:00', ['assignee_employee_id' => $sara->id]);
        $this->makeTicket($urgent, '2026-08-20 11:55:00', ['assignee_employee_id' => $ali->id]);

        $payload = $this->report('SlaPerformance');

        // Category rows before assignee rows: the commitment belongs to the category, so those rows are
        // the report and the assignee rows are the diagnosis.
        $this->assertStringContainsString('By category', $payload['rows'][0][0]);
        $this->assertStringContainsString('By assignee', $payload['rows'][count($payload['rows']) - 1][0]);

        $this->assertSame('2', $this->row($payload, 'By category · General')[1]);
        $this->assertSame('2', $this->row($payload, 'By assignee · EMP-1')[1]);

        // Three tickets, not six.
        $this->assertSame(3.0, $payload['tiles'][0]['value']);
        $this->assertSame('Total — 3 tickets', $payload['footer'][0]);
        $this->assertSame('3', $payload['footer'][1]);
    }

    /**
     * An unanswered ticket whose time is up has already missed, before anybody answers it late.
     *
     * The service's rule, restated here because it is the one that decides whether this report is useful
     * on the morning it matters or only in hindsight.
     */
    public function test_performance_counts_an_unanswered_ticket_past_its_time_as_missed(): void
    {
        $general = $this->category('General', response: 60, resolution: 120);

        // Three hours old against a one-hour response commitment, and nobody has replied.
        $this->makeTicket($general, '2026-08-20 09:00:00');

        $payload = $this->report('SlaPerformance');

        $this->assertSame('0.0%', $this->row($payload, 'By category · General')[2]);
        $this->assertSame('0.0%', $this->row($payload, 'By category · General')[3]);
        $this->assertSame(1.0, $payload['tiles'][1]['value'], 'the missed-resolution tile');
    }

    /** A category with no commitment is met, not missed — there was nothing to miss. */
    public function test_performance_treats_a_category_with_no_commitment_as_met(): void
    {
        // Both null. Nulling only the response commitment would leave the resolution clock running.
        $unmeasured = $this->category('Unmeasured', response: null, resolution: null);
        $this->makeTicket($unmeasured, '2026-08-01 09:00:00');

        $payload = $this->report('SlaPerformance');

        $this->assertSame('100.0%', $this->row($payload, 'By category · Unmeasured')[2]);
        $this->assertSame('100.0%', $this->row($payload, 'By category · Unmeasured')[3]);
        $this->assertSame(0.0, $payload['tiles'][1]['value']);
    }

    /**
     * Reopenings, not reopened tickets.
     *
     * One ticket reopened three times is three failures to resolve it. Counting the ticket once would read
     * as a single unlucky case, which is the opposite of what three reopenings mean.
     */
    public function test_performance_counts_reopenings_rather_than_reopened_tickets(): void
    {
        $general = $this->category('General');

        $this->makeTicket($general, '2026-08-20 11:45:00', ['reopened_count' => 3]);
        $this->makeTicket($general, '2026-08-20 11:50:00', ['reopened_count' => 1]);

        $payload = $this->report('SlaPerformance');

        $this->assertSame('4', $this->row($payload, 'By category · General')[4]);
        $this->assertSame('4', $payload['footer'][4]);
    }

    /**
     * No ratings is a dash, not a nought.
     *
     * An average of nothing reported as 0 would say a team was hated when it was simply never asked — and
     * the same figure would then be indistinguishable from genuinely terrible service.
     */
    public function test_performance_shows_a_dash_where_nobody_rated_anything(): void
    {
        $rated = $this->category('Rated');
        $unrated = $this->category('Unrated');

        $this->makeTicket($rated, '2026-08-20 11:45:00', ['satisfaction_rating' => 5]);
        $this->makeTicket($rated, '2026-08-20 11:46:00', ['satisfaction_rating' => 3]);
        $this->makeTicket($unrated, '2026-08-20 11:47:00');

        $payload = $this->report('SlaPerformance');

        $this->assertSame('4.0', $this->row($payload, 'By category · Rated')[5]);
        $this->assertSame('—', $this->row($payload, 'By category · Unrated')[5]);
        $this->assertStringNotContainsString('0.0 |', $this->cells($payload));
    }

    /** A month with no tickets says so, rather than reporting nought per cent met. */
    public function test_performance_says_when_no_ticket_was_opened(): void
    {
        $payload = $this->report('SlaPerformance');

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO TICKETS WERE OPENED IN THIS MONTH', $payload['note']);
        $this->assertStringNotContainsString('0.0%', $payload['note']);
    }

    /**
     * The report never claims to enforce a commitment.
     *
     * The module's rule, and the reason it is on the face of the report rather than only in the help: a
     * percentage that looks like a penalty invites somebody to close tickets in order to improve it.
     */
    public function test_both_reports_say_the_clocks_are_reported_and_not_enforced(): void
    {
        $general = $this->category('General');
        $this->makeTicket($general, '2026-08-20 09:00:00');

        $this->assertStringContainsString('REPORTED, NOT ENFORCED', $this->report('SlaPerformance')['note']);
        $this->assertStringContainsString('REPORTED, NOT ENFORCED', $this->report('SlaBreaches')['note']);
    }

    // ────────────────────────────────────────────────────────────── breaches ──

    /** Open tickets only. A closed ticket's breach is history, and history is the performance report. */
    public function test_breaches_lists_open_tickets_only(): void
    {
        $general = $this->category('General');

        $this->makeTicket($general, '2026-08-20 09:00:00');
        $this->makeTicket($general, '2026-08-19 09:00:00', [
            'status' => Ticket::STATUS_CLOSED,
            'resolved_at' => '2026-08-20 08:00:00',
            'closed_at' => '2026-08-20 08:00:00',
        ]);

        $payload = $this->report('SlaBreaches');

        $this->assertCount(1, $payload['rows']);
        $this->assertSame(1.0, $payload['tiles'][0]['value']);
    }

    /** And says what was missed, in words, rather than leaving a reader to compare two timestamps. */
    public function test_breaches_states_what_was_missed(): void
    {
        $this->makeTicket($this->category('General'), '2026-08-20 09:00:00');

        $payload = $this->report('SlaBreaches');

        $this->assertStringContainsString('first response past the', $this->cells($payload));
        $this->assertStringContainsString('commitment', $this->cells($payload));
    }

    /**
     * A breached ticket with nobody assigned is named and counted.
     *
     * It is the worst row in the table — nobody is going to fix it by accident — so a blank cell, which
     * reads as missing data, is the one thing it must not be.
     */
    public function test_breaches_names_and_counts_the_unassigned(): void
    {
        $general = $this->category('General');
        $this->makeTicket($general, '2026-08-20 09:00:00');
        $this->makeTicket($general, '2026-08-20 08:00:00', [
            'assignee_employee_id' => $this->makeEmployee('EMP-1')->id,
        ]);

        $payload = $this->report('SlaBreaches');

        $this->assertStringContainsString('Unassigned', $this->cells($payload));
        $this->assertSame(2.0, $payload['tiles'][0]['value']);
        $this->assertSame(1.0, $payload['tiles'][1]['value'], 'the unassigned tile');
    }

    /** Hours up to two days, days after that: "97h" is a number somebody has to divide. */
    public function test_breaches_reports_age_in_the_unit_that_reads(): void
    {
        $general = $this->category('General');
        $this->makeTicket($general, '2026-08-20 09:00:00', ['subject' => 'Three hours old']);
        $this->makeTicket($general, '2026-08-15 12:00:00', ['subject' => 'Five days old']);

        $payload = $this->report('SlaBreaches');
        $ages = array_column($payload['rows'], 4);

        $this->assertContains('3h', $ages);
        $this->assertContains('5d', $ages);
    }

    /** Nothing in breach is a sentence, not an empty grid. */
    public function test_breaches_says_when_everything_is_inside_its_commitment(): void
    {
        $this->makeTicket($this->category('General'), '2026-08-20 11:45:00');

        $payload = $this->report('SlaBreaches');

        $this->assertSame([], $payload['rows']);
        $this->assertSame('EVERY OPEN TICKET IS INSIDE ITS COMMITMENT', $payload['note']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload — the same claim `CrmReportsTest` makes for CRM's five. */
    public function test_both_reports_state_the_same_thing_on_their_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->makeTicket($this->category('General'), '2026-08-20 09:00:00');

        foreach ([SlaPerformance::class, SlaBreaches::class] as $page) {
            $key = class_basename($page);

            $onThePage = Livewire::test($page, ['asOf' => self::AS_OF])
                ->assertSuccessful()
                ->instance()
                ->statement();

            $this->assertSame(
                $onThePage,
                app(ReportPaneRenderer::class)->for($key, self::AS_OF, false, []),
                "{$key} draws differently on its page than in the pane",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────── gating ──

    /** Both disappear with the module. */
    public function test_the_reports_are_gated_on_the_support_module(): void
    {
        Gate::before(fn () => true);

        foreach ([SlaPerformance::class, SlaBreaches::class] as $page) {
            $this->assertTrue($page::canAccess(), class_basename($page).' is unreachable with Support enabled');
        }

        $this->setModule('support', false);

        foreach ([SlaPerformance::class, SlaBreaches::class] as $page) {
            $this->assertFalse($page::canAccess(), class_basename($page).' survives Support being disabled');
        }
    }

    /** And on `ReportView`. */
    public function test_the_reports_are_gated_on_report_view(): void
    {
        foreach ([SlaPerformance::class, SlaBreaches::class] as $page) {
            $this->assertFalse($page::canAccess(), class_basename($page).' is reachable without ReportView');
        }
    }
}
