<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Filament\Pages\EnvironmentHealth;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Environment health and incidents — `docs/reports-expansion-plan.md` Phase 3.13.
 *
 * The plan calls this "the history", and the tests are largely about how much history there actually is.
 * `ProjectEnvironmentCheck` is `Prunable` at thirty days, so uptime cannot be reported over a financial year
 * however the plan words it — the checks are deleted. Incidents are not pruned, so the two halves of the
 * report cover different spans and both are stated.
 *
 * Beyond that:
 *
 *  - **only confirmed incidents are outages** — `confirmed_at` is flap suppression, and counting the
 *    unconfirmed rows would turn every blip into an outage;
 *  - **uptime is a dash where nothing was checked, never nought** — `uptimePercent()`'s own rule, "never
 *    render 0% for not checked yet", and an unchecked environment is the opposite of a down one;
 *  - **an incident that ran with alerts off or muted** is the finding neither existing widget can show,
 *    because both are point-in-time and a mute has usually expired by the time anybody looks.
 *
 * The clock is frozen throughout. This report reads a retention horizon relative to its own date, and a test
 * whose fixtures drift with the wall clock would pass in February and fail in March.
 */
class EnvironmentHealthReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen because the retention horizon is `asOf - retention_days` and several fixtures sit either side
        // of it. `recordCheck()` also stamps `now()`, so an unfrozen clock would place checks outside the
        // window the report reads.
        Carbon::setTestNow(self::AS_OF.' 12:00:00');

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['projects', 'employees'] as $module) {
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

        config(['projects.health.retention_days' => 30]);

        $this->project = Project::create(['code' => 'ACME', 'name' => 'Acme Portal', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function environment(array $attributes = [], ?Project $project = null): ProjectEnvironment
    {
        // Through the relation, not `ProjectEnvironment::create()` plus `associate()`: `project_id` is NOT
        // NULL and not fillable, so the insert fails before the association is ever set.
        return ($project ?? $this->project)->environments()->create(array_merge([
            'kind' => ProjectEnvironment::KIND_PROD,
            'url' => 'https://acme.test',
            'is_monitored' => true,
            'alerts_enabled' => true,
        ], $attributes));
    }

    private function check(ProjectEnvironment $environment, string $checkedAt, bool $isUp): void
    {
        $environment->checks()->create([
            'checked_at' => $checkedAt,
            'is_up' => $isUp,
            'status_code' => $isUp ? 200 : 502,
        ]);
    }

    private function incident(
        ProjectEnvironment $environment,
        string $startedAt,
        ?string $resolvedAt = null,
        bool $confirmed = true,
    ): ProjectEnvironmentIncident {
        return $environment->incidents()->create([
            'started_at' => $startedAt,
            'confirmed_at' => $confirmed ? $startedAt : null,
            'resolved_at' => $resolvedAt,
            'failure_count' => 3,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('EnvironmentHealth', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for EnvironmentHealth');

        return $payload;
    }

    private function cell(array $payload, string $column, int $row = 0): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "there is no {$column} column");

        return $payload['rows'][$row][$index];
    }

    // ───────────────────────────────────────── checks failed and uptime ──

    /** Checks and failures, per environment, with the project named. */
    public function test_an_environment_reports_its_checks_and_failures(): void
    {
        $environment = $this->environment();
        $this->check($environment, '2027-02-15 09:00:00', true);
        $this->check($environment, '2027-02-15 09:05:00', true);
        $this->check($environment, '2027-02-15 09:10:00', false);
        $this->check($environment, '2027-02-15 09:15:00', true);

        $payload = $this->report();

        $this->assertSame('Acme Portal', $this->cell($payload, 'Project'));
        $this->assertSame('Production', $this->cell($payload, 'Environment'));
        $this->assertSame('4', $this->cell($payload, 'Checks'));
        $this->assertSame('1', $this->cell($payload, 'Failed'));
        $this->assertSame('75.00%', $this->cell($payload, 'Uptime'));
    }

    /**
     * Uptime is a dash where nothing was checked, never nought per cent.
     *
     * `ProjectEnvironment::uptimePercent()` says it in as many words — "never render 0% for not checked yet".
     * An environment nobody checked is the opposite of one that is down: nought would report the worst
     * possible health for the absence of any information.
     */
    public function test_uptime_is_a_dash_where_nothing_was_checked(): void
    {
        $environment = $this->environment();

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Uptime'));
        $this->assertSame('—', $this->cell($payload, 'Checks'));
        $this->assertNull($environment->uptimePercent(), 'the model agrees');
    }

    /** An environment that failed every check is nought per cent, which is a real answer. */
    public function test_an_environment_that_failed_everything_is_nought_per_cent(): void
    {
        $environment = $this->environment();
        $this->check($environment, '2027-02-15 09:00:00', false);
        $this->check($environment, '2027-02-15 09:05:00', false);

        $this->assertSame('0.00%', $this->cell($this->report(), 'Uptime'));
    }

    // ────────────────────────────── the retention horizon, which is the point ──

    /**
     * Checks older than the retention horizon are not read, because they do not survive.
     *
     * The most important thing this report knows about itself. `ProjectEnvironmentCheck` is `Prunable` at
     * `projects.health.retention_days`, so reading further back would compute a year's uptime from whatever
     * happened to escape pruning and present a month as though it were eight.
     */
    public function test_checks_older_than_the_retention_horizon_are_not_counted(): void
    {
        $environment = $this->environment();
        // Inside the 30 days to 20 February 2027.
        $this->check($environment, '2027-02-10 09:00:00', true);
        // Inside the fiscal year but well outside retention — a row that only exists because pruning has not
        // run yet, and must not be counted.
        $this->check($environment, '2026-09-01 09:00:00', false);

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'Checks'));
        $this->assertSame('100.00%', $this->cell($payload, 'Uptime'), 'the old failure is not in the figure');
    }

    /**
     * A check recorded after the date being read is not counted.
     *
     * The report is an as-at, so reading last quarter must show last quarter's uptime. Without the upper
     * bound the check window would run from the horizon to whenever the newest row happens to be, which is
     * not a window at all — a surviving mutation is what asked for this.
     */
    public function test_a_check_after_the_date_being_read_is_not_counted(): void
    {
        $environment = $this->environment();
        $this->check($environment, '2027-02-15 09:00:00', true);
        $this->check($environment, '2027-03-01 09:00:00', false);

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'Checks'));
        $this->assertSame('100.00%', $this->cell($payload, 'Uptime'), 'the later failure is not in the figure');
    }

    /** The horizon follows the company's configured retention, not a number in the report. */
    public function test_the_horizon_follows_the_configured_retention(): void
    {
        $environment = $this->environment();
        $this->check($environment, '2027-01-05 09:00:00', false);
        $this->check($environment, '2027-02-15 09:00:00', true);

        $this->assertSame('1', $this->cell($this->report(), 'Checks'), '5 January is outside 30 days');

        config(['projects.health.retention_days' => 90]);

        $payload = $this->report();
        $this->assertSame('2', $this->cell($payload, 'Checks'));
        $this->assertStringContainsString('LAST 90 DAYS', $payload['note']);
    }

    /** The horizon is stated on the report, because it qualifies the uptime column on every row. */
    public function test_the_retention_horizon_is_stated(): void
    {
        $this->environment();

        $payload = $this->report();

        $this->assertStringContainsString(
            'UPTIME COVERS THE LAST 30 DAYS ONLY — OLDER CHECKS ARE PRUNED',
            $payload['note'],
        );
        $this->assertStringContainsString('30-day retention horizon', $payload['subtitle']);
    }

    /**
     * Incidents are not pruned, so their history is the whole period.
     *
     * The two halves of the report cover different spans deliberately. Shortening the incident history to
     * match the checks would throw away the only long record there is.
     */
    public function test_incidents_older_than_the_check_horizon_are_still_counted(): void
    {
        $environment = $this->environment();
        // Inside the fiscal year, far outside the 30-day check window.
        $this->incident($environment, '2026-09-01 10:00:00', '2026-09-01 11:00:00');

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'Incidents'));
        $this->assertSame('—', $this->cell($payload, 'Checks'), 'no checks that old survive');
    }

    // ─────────────────────────── confirmed incidents only ──

    /** A confirmed incident is an outage, with its downtime. */
    public function test_a_confirmed_incident_is_counted_with_its_downtime(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2027-02-10 10:00:00', '2027-02-10 12:30:00');

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'Incidents'));
        $this->assertSame('2h 30m', $this->cell($payload, 'Downtime'));
        $this->assertSame(1.0, $payload['tiles'][0]['value']);
    }

    /**
     * An unconfirmed incident is a suppressed blip, not an outage.
     *
     * `ProjectEnvironmentIncident` doubles as the flap-suppression state: a row opens on the first failure and
     * is confirmed only once the threshold is crossed. Counting the unconfirmed rows would turn every
     * transient failure into an outage, which is exactly what `confirmed_at` exists to prevent —
     * `EnvironmentHealthOverview` takes the same view with `open()->confirmed()`.
     */
    public function test_an_unconfirmed_incident_is_reported_as_a_suppressed_blip(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2027-02-10 10:00:00', '2027-02-10 10:02:00', confirmed: false);

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Incidents'));
        $this->assertSame(0.0, $payload['tiles'][0]['value']);
        $this->assertStringContainsString('1 UNCONFIRMED BLIP SUPPRESSED RATHER THAN COUNTED', $payload['note']);
    }

    /**
     * An open incident is measured to the date being read, not to the clock.
     *
     * A report run for last quarter must not credit an outage with the months since. From 10 February 10:00
     * to the end of 20 February is ten days, thirteen hours and fifty-nine minutes, which reads as 10d 13h.
     */
    public function test_an_open_incident_is_measured_to_the_date_being_read(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2027-02-10 10:00:00');

        $payload = $this->report();

        $this->assertSame('10d 13h', $this->cell($payload, 'Downtime'));
        $this->assertStringContainsString('Down', $this->cell($payload, 'Standing'));
        $this->assertStringContainsString('1 STILL OPEN NOW', $payload['note']);
        $this->assertStringContainsString('1 still open', $payload['footer'][7]);
    }

    /** And a shorter window gives a shorter outage for the very same incident. */
    public function test_the_same_open_incident_is_shorter_when_read_earlier(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2027-02-10 10:00:00');

        $this->assertSame('1d 13h', $this->cell($this->report('2027-02-11'), 'Downtime'));
    }

    /**
     * An incident is included if it overlaps the period, not if it fits inside it.
     *
     * An outage that began before the financial year and is still open is this period's problem.
     */
    public function test_an_incident_overlapping_the_period_is_counted(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2026-06-01 10:00:00', '2026-08-15 11:00:00');

        $this->assertSame('1', $this->cell($this->report(), 'Incidents'));
    }

    /**
     * An incident that began after the date being read has not happened yet, as far as this read goes.
     *
     * The as-at bound on the other end. An incident still open in the future would otherwise be counted, and
     * its downtime measured backwards from the report's date to a start after it.
     */
    public function test_an_incident_starting_after_the_date_being_read_is_not_counted(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2027-03-01 10:00:00');

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Incidents'));
        $this->assertSame('—', $this->cell($payload, 'Downtime'));
        $this->assertStringNotContainsString('STILL OPEN NOW', $payload['note']);
    }

    /** One that closed before the period belongs to the previous report. */
    public function test_an_incident_that_closed_before_the_period_is_not_counted(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2026-05-01 10:00:00', '2026-05-01 11:00:00');

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Incidents'));
        $this->assertSame('—', $this->cell($payload, 'Downtime'));
    }

    /** The footer states none open when none are. */
    public function test_the_footer_says_none_open_when_nothing_is_down(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2027-02-10 10:00:00', '2027-02-10 11:00:00');

        $this->assertSame('none open', $this->report()['footer'][7]);
    }

    // ────────────────── an outage nobody was told about ──

    /**
     * The finding neither existing widget can show.
     *
     * Both are point-in-time, and a mute has usually expired by the time anybody comes looking. The outage
     * happened, the record exists, and nobody was told.
     */
    public function test_an_incident_with_alerts_off_is_reported_as_unalerted(): void
    {
        $environment = $this->environment(['alerts_enabled' => false]);
        $this->incident($environment, '2027-02-10 10:00:00', '2027-02-10 11:00:00');

        $payload = $this->report();

        $this->assertSame(1.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('1 unalerted', $this->cell($payload, 'Standing'));
        $this->assertStringContainsString('alerts off', $this->cell($payload, 'Standing'));
        $this->assertStringContainsString(
            '1 HAPPENED WITH ALERTS OFF OR MUTED, SO NOBODY WAS TOLD',
            $payload['note'],
        );
    }

    /** A muted environment is the same thing by another route. */
    public function test_an_incident_while_muted_is_reported_as_unalerted(): void
    {
        $environment = $this->environment(['muted_until' => '2027-03-01 00:00:00']);
        $this->incident($environment, '2027-02-10 10:00:00', '2027-02-10 11:00:00');

        $payload = $this->report();

        $this->assertTrue($environment->isMuted(), 'the model agrees');
        $this->assertSame(1.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('muted', $this->cell($payload, 'Standing'));
    }

    /** An environment being alerted on is not reported as unalerted. */
    public function test_an_incident_with_alerts_on_is_not_reported_as_unalerted(): void
    {
        $environment = $this->environment();
        $this->incident($environment, '2027-02-10 10:00:00', '2027-02-10 11:00:00');

        $payload = $this->report();

        $this->assertSame(0.0, $payload['tiles'][1]['value']);
        $this->assertStringNotContainsString('unalerted', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('NOBODY WAS TOLD', $payload['note']);
    }

    /** A mute with nothing to report is still stated — it is why nothing *will* be reported. */
    public function test_a_mute_is_stated_even_with_no_incidents(): void
    {
        $this->environment(['muted_until' => '2027-03-01 00:00:00']);

        $payload = $this->report();

        $this->assertStringContainsString('muted', $this->cell($payload, 'Standing'));
        $this->assertSame(0.0, $payload['tiles'][1]['value'], 'nothing happened to go unheard');
    }

    // ────────────────────────── monitoring that is not happening ──

    /**
     * A monitored environment nobody has ever checked is its own finding.
     *
     * The monitoring is nominal: it is switched on, it has a URL, and nothing has ever run against it.
     */
    public function test_a_monitored_environment_never_checked_is_called_out(): void
    {
        $this->environment();

        $payload = $this->report();

        $this->assertSame('Never checked', $this->cell($payload, 'Standing'));
        $this->assertStringContainsString(
            '1 MONITORED BUT NEVER CHECKED, SO NOTHING IS KNOWN ABOUT IT',
            $payload['note'],
        );
    }

    /** An unmonitored environment is not, because nobody asked for it to be watched. */
    public function test_an_unmonitored_environment_is_not_called_out(): void
    {
        $this->environment(['is_monitored' => false]);

        $payload = $this->report();

        $this->assertSame('Not monitored', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('NEVER CHECKED', $payload['note']);
    }

    /** Nor is one with no URL, which cannot be checked whatever the flag says. */
    public function test_an_environment_with_no_url_is_not_monitorable(): void
    {
        $this->environment(['url' => null]);

        $payload = $this->report();

        $this->assertSame('Not monitored', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('NEVER CHECKED', $payload['note']);
    }

    /** An environment that has been checked reports its current health rather than the absence of it. */
    public function test_a_checked_environment_reports_its_current_health(): void
    {
        $environment = $this->environment();
        $environment->recordCheck(true, 200, 120);

        $payload = $this->report();

        $this->assertSame('Up', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('NEVER CHECKED', $payload['note']);
    }

    /** And one whose last check failed says down, even with no incident row yet. */
    public function test_an_environment_whose_last_check_failed_says_down(): void
    {
        $environment = $this->environment();
        $environment->recordCheck(false, 502, null, 'Bad gateway');

        $this->assertSame('Down', $this->cell($this->report(), 'Standing'));
    }

    // ───────────────────────────────────── shape and ordering ──

    /** Environments are grouped by project name, production first within each. */
    public function test_environments_are_grouped_by_project(): void
    {
        $other = Project::create(['code' => 'ZED', 'name' => 'Zed Site', 'status' => 'active']);

        $this->environment(['kind' => ProjectEnvironment::KIND_DEV], project: $other);
        $this->environment(['kind' => ProjectEnvironment::KIND_QUAL]);
        $this->environment(['kind' => ProjectEnvironment::KIND_PROD]);

        $payload = $this->report();

        $this->assertSame('Acme Portal', $this->cell($payload, 'Project', 0));
        $this->assertSame('Acme Portal', $this->cell($payload, 'Project', 1));
        $this->assertSame('Zed Site', $this->cell($payload, 'Project', 2));
        $this->assertSame('Production', $this->cell($payload, 'Environment', 0));
    }

    /** With nothing recorded the report says nothing is being watched, not "nothing to show". */
    public function test_an_empty_report_says_nothing_is_being_watched(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame(
            'NO ENVIRONMENT IS RECORDED ON ANY PROJECT, SO NOTHING IS BEING WATCHED',
            $payload['note'],
        );
    }

    /** The footer totals the checks and computes uptime across all of them. */
    public function test_the_footer_totals_the_checks_and_uptime(): void
    {
        $one = $this->environment();
        $this->check($one, '2027-02-15 09:00:00', true);
        $this->check($one, '2027-02-15 09:05:00', false);

        $two = $this->environment(['kind' => ProjectEnvironment::KIND_QUAL]);
        $this->check($two, '2027-02-15 09:00:00', true);
        $this->check($two, '2027-02-15 09:05:00', true);

        $payload = $this->report();
        $at = fn (string $column): string => $payload['footer'][array_search($column, $payload['columns'], true)];

        $this->assertSame('Total — 2 environments', $payload['footer'][0]);
        $this->assertSame('4', $at('Checks'));
        $this->assertSame('1', $at('Failed'));
        $this->assertSame('75.00%', $at('Uptime'));
    }

    /** Eight columns and a standing carrying sentences, so it scrolls — Phase 0.2. */
    public function test_the_table_is_marked_wide(): void
    {
        $this->environment();

        $this->assertTrue($this->report()['wide']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $environment = $this->environment();
        $this->check($environment, '2027-02-15 09:00:00', true);

        $onThePage = Livewire::test(EnvironmentHealth::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame(
            $onThePage,
            app(ReportPaneRenderer::class)->for('EnvironmentHealth', self::AS_OF, false, []),
        );
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(EnvironmentHealth::canAccess());
    }
}
