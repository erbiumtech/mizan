<?php

namespace Tests\Feature;

use App\Health\TenantDatabaseCheck;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

/**
 * The health checks, and the two that needed writing rather than registering.
 *
 * `spatie/laravel-health` ships a `DatabaseCheck` that connects to one connection. In a
 * database-per-tenant application that connection is the landlord, so the check can be green
 * while any number of companies are unreachable — the same shape of gap as the backup's, and
 * with the same consequence: the monitoring says fine and a customer says otherwise.
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
