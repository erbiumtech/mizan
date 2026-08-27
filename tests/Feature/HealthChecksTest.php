<?php

namespace Tests\Feature;

use App\Health\DiskSpaceCheck;
use App\Health\TenantDatabaseCheck;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Enums\Status;
use Spatie\Health\Health;
use Tests\TestCase;

/**
 * The health checks, and the ones that needed writing rather than registering.
 *
 * `spatie/laravel-health` ships a `DatabaseCheck` that connects to one connection. In a
 * database-per-tenant application that connection is the landlord, so the check can be green
 * while any number of companies are unreachable — the same shape of gap as the backup's, and
 * with the same consequence: the monitoring says fine and a customer says otherwise.
 *
 * Its `UsedDiskSpaceCheck` had a plainer problem: it shelled out to `df` and parsed the output, so
 * on a host without a usable `df` it crashed on a regex against an empty string once per scheduled
 * run. {@see \App\Health\DiskSpaceCheck} asks PHP instead, and the tests below hold both halves of
 * that: it reads a real volume, and where it cannot read one it fails with an explanation rather
 * than throwing or answering 0%.
 *
 * The other thing asserted here is the Horizon dashboard gate. Horizon's screens show queued job
 * payloads, and in this application those are payslip notifications, expense approvals and
 * billing statements — one customer's administrator must not be able to read another's.
 */
class HealthChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs single-database (phpunit.xml empties TENANT_DATABASE_CONNECTION), so the
        // tests that are about tenant connections configure one. The behaviour when none is
        // configured is asserted on its own below.
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
    }

    private function company(string $slug, ?string $database): Company
    {
        return Company::factory()->create([
            'slug' => $slug,
            'database' => $database ?? '',
        ]);
    }

    /** An sqlite path that cannot be opened — Laravel throws rather than creating it. */
    private function unreachablePath(): string
    {
        return '/nonexistent-directory-'.__CLASS__.'/nope.sqlite';
    }

    // -------------------------------------------------- the tenant database check

    /**
     * A single-database installation is not broken, and must not be reported as broken.
     *
     * The check runs everywhere, including in this suite and in any deployment that has not
     * split its databases. Reporting "no tenant connection" as a failure would put a permanent
     * red on those installations and teach whoever watches it to ignore the check.
     */
    public function test_it_is_ok_when_no_tenant_connection_is_configured(): void
    {
        config(['multitenancy.tenant_database_connection_name' => null]);

        $this->company('alpha', database_path('tenants/placeholder.sqlite'));

        $result = (new TenantDatabaseCheck)->run();

        $this->assertSame(Status::ok(), $result->status);
    }

    public function test_it_is_ok_when_there_are_no_companies(): void
    {
        $this->assertSame(Status::ok(), (new TenantDatabaseCheck)->run()->status);
    }

    public function test_a_reachable_company_database_passes(): void
    {
        // :memory: is always openable, which is what "reachable" means here.
        $this->company('alpha', ':memory:');

        $result = (new TenantDatabaseCheck)->run();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertSame(1, $result->meta['companies_checked']);
        $this->assertSame([], $result->meta['unreachable']);
    }

    /**
     * The whole point of the check: one company down out of several is a warning, and it names
     * which one. A check that says "something is wrong" without saying which company means
     * somebody opens forty databases by hand.
     */
    public function test_an_unreachable_company_is_named(): void
    {
        $this->company('alpha', ':memory:');
        $this->company('beta', $this->unreachablePath());

        $result = (new TenantDatabaseCheck)->run();

        $this->assertSame(Status::warning(), $result->status);
        $this->assertSame(2, $result->meta['companies_checked']);
        $this->assertArrayHasKey('beta', $result->meta['unreachable']);
        $this->assertArrayNotHasKey('alpha', $result->meta['unreachable']);
        $this->assertStringContainsString('beta', $result->notificationMessage);
    }

    /**
     * One company down is that company's incident; every company down is the database server.
     * Whoever is woken up has to be able to tell those apart from the notification alone, so
     * they are different statuses rather than one message with a number in it.
     */
    public function test_several_unreachable_companies_are_a_failure_not_a_warning(): void
    {
        $this->company('alpha', $this->unreachablePath());
        $this->company('beta', $this->unreachablePath());

        $result = (new TenantDatabaseCheck)->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertCount(2, $result->meta['unreachable']);
    }

    public function test_the_failure_threshold_is_adjustable(): void
    {
        $this->company('alpha', $this->unreachablePath());

        $this->assertSame(
            Status::failed(),
            TenantDatabaseCheck::new()->failAtCount(1)->run()->status,
        );
    }

    /** A company with no database name reaches the same dead end and is reported the same way. */
    public function test_a_company_with_a_blank_database_is_reported(): void
    {
        $this->company('alpha', null);

        $result = (new TenantDatabaseCheck)->run();

        $this->assertArrayHasKey('alpha', $result->meta['unreachable']);
        $this->assertSame('no database recorded', $result->meta['unreachable']['alpha']);
    }

    /**
     * Checking one company must not leave the application pointed at its database.
     *
     * The check runs on a schedule, in a process that may be serving other things. Repointing the
     * live `tenant` connection would be a data-leak-shaped bug introduced by a monitoring tool,
     * so it uses a throwaway connection and this asserts the live one is untouched.
     */
    public function test_checking_does_not_repoint_the_live_tenant_connection(): void
    {
        $before = config('database.connections.tenant.database');

        $this->company('alpha', ':memory:');
        (new TenantDatabaseCheck)->run();

        $this->assertSame($before, config('database.connections.tenant.database'));
    }

    // -------------------------------------------------------- the disk space check

    /**
     * The reading itself. Whatever this machine's disk is at, the check has to produce a number
     * between nothing-used and completely-full and say which path it measured.
     *
     * The status is not asserted, because it depends on how full whoever runs this suite has let
     * their disk get — which is the check working, not the check failing.
     */
    public function test_it_reports_a_percentage_for_a_real_path(): void
    {
        $result = DiskSpaceCheck::new()->path(storage_path())->run();

        $this->assertIsInt($result->meta['disk_space_used_percentage']);
        $this->assertGreaterThanOrEqual(0, $result->meta['disk_space_used_percentage']);
        $this->assertLessThanOrEqual(100, $result->meta['disk_space_used_percentage']);
        $this->assertSame(storage_path(), $result->meta['path']);
        $this->assertStringEndsWith('%', $result->shortSummary);
    }

    /** Left to itself it watches the volume holding backups, uploads and rendered PDFs. */
    public function test_it_measures_the_storage_path_by_default(): void
    {
        $this->assertSame(storage_path(), DiskSpaceCheck::new()->run()->meta['path']);
    }

    /**
     * The regression. The package's `UsedDiskSpaceCheck` ran `df -P .`, ignored the exit code, and
     * matched `/(\d*)%/` against whatever came back on stdout — so on a host with no usable `df`
     * it threw `RegexFailed` on an empty string, crashed, and reported a regex error once per
     * scheduled run while saying nothing at all about the disk.
     *
     * A check that cannot read the disk must say so as a result, not as an exception.
     */
    public function test_an_unreadable_path_fails_rather_than_throwing(): void
    {
        $result = DiskSpaceCheck::new()->path('/nonexistent-volume-'.__CLASS__)->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertSame('unknown', $result->shortSummary);
        $this->assertStringContainsString('could not be determined', $result->notificationMessage);
    }

    /**
     * And it must not answer 0% instead. A green tick meaning "no idea" is worse than a red one,
     * because the disk it claims is empty is the one the backups are failing to write to.
     */
    public function test_an_unreadable_path_does_not_report_zero_used(): void
    {
        $result = DiskSpaceCheck::new()->path('/nonexistent-volume-'.__CLASS__)->run();

        $this->assertArrayNotHasKey('disk_space_used_percentage', $result->meta);
    }

    /** Thresholds, forced past in both directions so the registered 70/85 are known to be wired. */
    public function test_the_thresholds_decide_the_status(): void
    {
        $path = storage_path();

        $this->assertSame(
            Status::failed(),
            DiskSpaceCheck::new()->path($path)->failWhenUsedSpaceIsAbovePercentage(-1)->run()->status,
        );

        $this->assertSame(
            Status::warning(),
            DiskSpaceCheck::new()->path($path)
                ->warnWhenUsedSpaceIsAbovePercentage(-1)
                ->failWhenUsedSpaceIsAbovePercentage(100)
                ->run()->status,
        );

        $this->assertSame(
            Status::ok(),
            DiskSpaceCheck::new()->path($path)
                ->warnWhenUsedSpaceIsAbovePercentage(100)
                ->failWhenUsedSpaceIsAbovePercentage(100)
                ->run()->status,
        );
    }

    /**
     * Nothing on the schedule may go back to shelling out for this. The failure was not a bad
     * regex; it was a health check that depended on a subprocess existing, on a host where it did
     * not — so re-registering the package's version would restore the crash exactly.
     */
    public function test_the_registered_disk_check_does_not_shell_out(): void
    {
        $checks = app(Health::class)->registeredChecks();

        $this->assertTrue(
            $checks->contains(fn (Check $check): bool => $check instanceof DiskSpaceCheck),
            'the disk space check is not registered',
        );

        $this->assertFalse(
            $checks->contains(fn (Check $check): bool => $check instanceof UsedDiskSpaceCheck),
            'UsedDiskSpaceCheck shells out to df and crashes where df is unavailable',
        );
    }

    // ------------------------------------------------------------ the Horizon gate

    /**
     * Horizon shows queued job payloads — payslip notifications, expense approvals, billing
     * statements — for every tenant at once, in one dashboard.
     */
    public function test_a_super_admin_may_view_horizon(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);

        $this->assertTrue(Gate::forUser($user)->allows('viewHorizon'));
    }

    /**
     * The assertion that matters. `Administrator` is scoped to a company by
     * spatie/laravel-permission teams, so granting it Horizon would hand one customer's admin a
     * window onto every other customer's payroll.
     */
    public function test_an_ordinary_user_may_not_view_horizon(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'status' => 1]);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_a_guest_may_not_view_horizon(): void
    {
        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    // ---------------------------------------------------------------- the config

    /**
     * A failing check must answer with a failing status code.
     *
     * The package defaults to 200, and an uptime monitor reads the code rather than the body — so
     * the default reports the application healthy while every check inside it is red. That is the
     * failure this package exists to prevent, one layer further out.
     */
    public function test_a_failed_check_answers_with_a_failure_status_code(): void
    {
        $this->assertSame(503, config('health.json_results_failure_status'));
    }

    /**
     * Notifications are on by default, so an empty recipient means every failure is reported to
     * nobody — the same trap as the backup's placeholder address.
     */
    public function test_health_notifications_have_somewhere_to_go(): void
    {
        $this->assertNotEmpty(
            config('health.notifications.mail.to'),
            'health notifications are enabled but addressed to nobody',
        );
    }

    /** History belongs to the landlord: health is a fact about the installation, not a company. */
    public function test_check_history_is_stored_on_the_landlord_connection(): void
    {
        $stores = config('health.result_stores');
        $eloquent = $stores[\Spatie\Health\ResultStores\EloquentHealthResultStore::class] ?? null;

        $this->assertNotNull($eloquent);
        $this->assertSame(config('database.default'), $eloquent['connection']);
    }
}
