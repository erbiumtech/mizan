<?php

namespace Tests\Feature;

use App\Modules\Projects\Jobs\CheckEnvironmentCertificate;
use App\Modules\Projects\Jobs\CheckEnvironmentHealth;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Services\HealthCheckDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

class EnvironmentCheckSchedulingTest extends TestCase
{
    use MakesProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['projects.health.default_interval' => 5]);
    }

    public function test_a_never_checked_environment_is_due(): void
    {
        $environment = $this->makeEnvironment($this->makeProject());

        $this->assertTrue($environment->isDue());
        $this->assertCount(1, ProjectEnvironment::dueForCheck());
    }

    public function test_an_environment_checked_inside_its_interval_is_not_due(): void
    {
        $environment = $this->makeEnvironment($this->makeProject());
        $environment->forceFill(['health_checked_at' => now()->subMinutes(2)])->save();

        $this->assertFalse($environment->refresh()->isDue());
        $this->assertCount(0, ProjectEnvironment::dueForCheck());
    }

    public function test_the_default_interval_applies_when_none_is_set(): void
    {
        $environment = $this->makeEnvironment($this->makeProject());
        $environment->forceFill(['health_checked_at' => now()->subMinutes(6)])->save();

        $this->assertTrue($environment->refresh()->isDue());
    }

    public function test_a_per_environment_interval_overrides_the_default(): void
    {
        $frequent = $this->makeEnvironment($this->makeProject(), ['check_interval_min' => 1]);
        $frequent->forceFill(['health_checked_at' => now()->subMinutes(2)])->save();

        $rare = $this->makeEnvironment($this->makeProject(), ['check_interval_min' => 60]);
        $rare->forceFill(['health_checked_at' => now()->subMinutes(30)])->save();

        $this->assertTrue($frequent->refresh()->isDue(), '1-minute interval, checked 2 minutes ago');
        $this->assertFalse($rare->refresh()->isDue(), '60-minute interval, checked 30 minutes ago');
        $this->assertSame(1, ProjectEnvironment::dueForCheck()->count());
    }

    /**
     * Asserted against the dispatcher rather than the console command: the
     * commands are wrapped in Spatie's TenantAware trait, which iterates real
     * per-tenant database connections and cannot run in this single-database
     * suite. The per-company fan-out is the package's behaviour; what is ours
     * is which environments get queued.
     */
    public function test_the_dispatcher_queues_one_job_per_due_environment(): void
    {
        Queue::fake();

        $due = $this->makeEnvironment($this->makeProject());
        $notDue = $this->makeEnvironment($this->makeProject());
        $notDue->forceFill(['health_checked_at' => now()])->save();
        $this->makeEnvironment($this->makeProject(), ['kind' => 'dev', 'is_monitored' => false]);

        $count = app(HealthCheckDispatcher::class)->dispatchDueHealthChecks();

        $this->assertSame(1, $count);
        Queue::assertPushed(CheckEnvironmentHealth::class, 1);
        Queue::assertPushed(fn (CheckEnvironmentHealth $job) => $job->environment->is($due));
    }

    public function test_the_dispatcher_respects_the_disable_switch(): void
    {
        Queue::fake();
        config(['projects.health.enabled' => false]);

        $this->makeEnvironment($this->makeProject());

        $this->assertSame(0, app(HealthCheckDispatcher::class)->dispatchDueHealthChecks());
        Queue::assertNothingPushed();
    }

    public function test_only_https_environments_get_a_certificate_check(): void
    {
        Queue::fake();

        $secure = $this->makeEnvironment($this->makeProject(), ['url' => 'https://secure.example.test']);
        $this->makeEnvironment($this->makeProject(), ['url' => 'http://plain.example.test']);

        $count = app(HealthCheckDispatcher::class)->dispatchCertificateChecks();

        $this->assertSame(1, $count);
        Queue::assertPushed(CheckEnvironmentCertificate::class, 1);
        Queue::assertPushed(fn (CheckEnvironmentCertificate $job) => $job->environment->is($secure));
    }
}
