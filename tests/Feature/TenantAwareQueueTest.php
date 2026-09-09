<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Multitenancy\TenantAwareJobs;
use App\Multitenancy\TenantRequiredToQueue;
use App\Notifications\RecordChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Jobs\HealthQueueJob;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Tests\TestCase;

/**
 * A tenant-aware job with no tenant is refused when it is queued, not deleted when it is run.
 *
 * spatie/laravel-multitenancy's worker-side answer to "tenant-aware, no tenant id" is to delete the job and
 * throw — nothing in `failed_jobs`, nothing printed, nothing in the panel. A payslip notification queued
 * that way never arrives and nobody is told. `RefuseTenantlessTenantAwareJobs` moves the failure to the
 * dispatch, in front of whoever made the mistake.
 *
 * On the `database` connection throughout, because `SyncQueue` never raises `JobQueueing` — the sync
 * driver runs the job in-process, so there is nothing to queue and nothing to refuse.
 */
class TenantAwareQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'multitenancy.queues_are_tenant_aware_by_default' => true,
        ]);
    }

    public function test_a_tenant_aware_job_queued_with_no_tenant_is_refused_by_name(): void
    {
        $user = User::factory()->create();

        $this->expectException(TenantRequiredToQueue::class);
        $this->expectExceptionMessage(RecordChanged::class);

        $user->notify(new RecordChanged('Orphan', 'No tenant current.'));
    }

    public function test_with_a_tenant_current_the_same_job_queues(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        // What `makeCurrent()` puts in Context — the exact thing the worker will read back.
        Context::add((string) config('multitenancy.current_tenant_context_key'), $company->getKey());

        $user->notify(new RecordChanged('Housed', 'Tenant present.'));

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_job_that_serves_no_company_is_not_asked_for_one(): void
    {
        dispatch(new LandlordOnlyJob);

        $this->assertSame(1, DB::table('jobs')->count());
    }

    /**
     * A package job that serves the installation queues without a tenant — `not_tenant_aware_jobs`.
     *
     * The health heartbeat was refused once a minute on production, 800 times in thirteen hours, which
     * left `QueueCheck` unable to run: the check that would report a dead worker was itself the thing
     * that could not be dispatched. See ProductionLogErrorsTest.
     */
    public function test_a_package_job_on_the_exemption_list_queues_with_no_tenant(): void
    {
        dispatch(new HealthQueueJob(new QueueCheck));

        $this->assertSame(1, DB::table('jobs')->count());
    }

    /**
     * The rule restates the package's, so it is pinned to the same answers for every queueable shape here.
     * If the package changes its rule, this is what says so.
     */
    public function test_the_rule_unwraps_laravels_queueable_wrappers(): void
    {
        $user = User::factory()->create();

        $wrapped = new SendQueuedNotifications($user, new RecordChanged('x', 'y'), ['database']);

        $this->assertTrue(TenantAwareJobs::requiresTenant($wrapped), 'judged by the notification inside');
        $this->assertFalse(TenantAwareJobs::requiresTenant(new LandlordOnlyJob));

        config(['multitenancy.queues_are_tenant_aware_by_default' => false]);
        $this->assertFalse(TenantAwareJobs::requiresTenant($wrapped), 'the default is the package default');

        config(['multitenancy.tenant_aware_jobs' => [RecordChanged::class]]);
        $this->assertTrue(TenantAwareJobs::requiresTenant($wrapped), 'the allow-list overrides the default');
    }
}

/** A job about the installation rather than any company — backups, health, licence sync. */
class LandlordOnlyJob implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
