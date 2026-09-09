<?php

namespace Tests\Feature;

use App\Health\HorizonCheck;
use App\Health\MailConfigurationCheck;
use App\Multitenancy\TenantAwareJobs;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use RuntimeException;
use Spatie\Health\Enums\Status;
use Spatie\Health\Jobs\HealthQueueJob;
use Tests\TestCase;

/**
 * The three faults that filled a production log with 2,404 errors in thirteen hours.
 *
 * Every one of them was silent in development and loud only in a file nobody reads until something
 * else goes wrong, so each is pinned here by its own symptom rather than by the fix.
 *
 *  1. **The queue heartbeat was refused once a minute.** `HealthQueueJob` writes one cache key to
 *     prove a worker is alive; `queues_are_tenant_aware_by_default` made it look like a company's
 *     job, and the dispatch guard refused it. 800 failures, and the check that watches the queue
 *     could not run — so a genuinely dead worker would have looked identical.
 *  2. **The Horizon check crashed instead of failing.** With Redis refusing connections the
 *     package's check threw `RedisException` out of `run()`, logging a stack trace every minute
 *     and taking `health:check`'s exit code with it.
 *  3. **Mail was configured to throw.** `MAIL_MAILER=failover` naming a `mailgun` mailer that the
 *     deployed release did not define, so every send raised `Mailer [mailgun] is not defined` —
 *     including the health notification reporting fault 2.
 */
class ProductionLogErrorsTest extends TestCase
{
    // ─────────────────────────── 1. the heartbeat ───────────────────────────

    public function test_the_queue_heartbeat_job_serves_the_installation_not_a_company(): void
    {
        config(['multitenancy.queues_are_tenant_aware_by_default' => true]);

        $this->assertFalse(
            TenantAwareJobs::requiresTenant(new HealthQueueJob(new \Spatie\Health\Checks\Checks\QueueCheck)),
            'the heartbeat writes one cache key and belongs to no company; refusing it disables QueueCheck',
        );
    }

    // ──────────────────────────── 2. the Horizon check ───────────────────────

    public function test_an_unreachable_horizon_fails_rather_than_crashing(): void
    {
        $this->app->instance(MasterSupervisorRepository::class, new UnreachableHorizonRepository);

        $result = HorizonCheck::new()->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('Connection refused', $result->getNotificationMessage());
        $this->assertStringContainsString('nothing is known to be consuming the queue', $result->getNotificationMessage());
    }

    public function test_horizon_is_only_watched_where_redis_carries_the_queue(): void
    {
        $check = collect(\Spatie\Health\Facades\Health::registeredChecks())
            ->first(fn ($check): bool => $check instanceof HorizonCheck);

        $this->assertNotNull($check, 'the Horizon check should be registered');

        config(['queue.default' => 'database']);
        $this->assertFalse($check->shouldRun(), 'no Horizon to watch without a Redis queue');

        config(['queue.default' => 'redis']);
        $this->assertTrue($check->shouldRun());
    }

    // ───────────────────────────── 3. mail ──────────────────────────────────

    /** The production failure exactly: a chain naming a mailer the release does not define. */
    public function test_a_mailer_named_in_the_chain_but_not_defined_is_a_failure(): void
    {
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['sendgrid', 'mailgun']],
            'mail.mailers.mailgun' => null,
            'services.sendgrid.key' => 'SG.key',
            'mail.from.address' => 'payroll@example.test',
        ]);

        $result = (new MailConfigurationCheck)->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('[mailgun]', $result->getNotificationMessage());
    }

    public function test_a_provider_in_the_chain_with_no_credentials_is_a_failure(): void
    {
        $this->configureChain(['sendgrid' => null, 'mailgun' => ['domain' => 'mg.example.test', 'secret' => 'key']]);

        $result = (new MailConfigurationCheck)->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('SENDGRID_API_KEY', $result->getNotificationMessage());
    }

    public function test_the_shipped_placeholder_from_address_is_a_failure(): void
    {
        $this->configureChain(['sendgrid' => 'SG.key', 'mailgun' => ['domain' => 'mg.example.test', 'secret' => 'key']]);
        config(['mail.from.address' => MailConfigurationCheck::PLACEHOLDER_FROM]);

        $result = (new MailConfigurationCheck)->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS', $result->getNotificationMessage());
    }

    public function test_a_working_chain_is_ok_and_the_log_driver_is_a_warning(): void
    {
        $this->configureChain(['sendgrid' => 'SG.key', 'mailgun' => ['domain' => 'mg.example.test', 'secret' => 'key']]);

        $this->assertSame(Status::ok(), (new MailConfigurationCheck)->run()->status);

        // Delivering nothing while reporting success is the failure a production server ran all day.
        config(['mail.default' => 'log']);

        $result = (new MailConfigurationCheck)->run();

        $this->assertSame(Status::warning(), $result->status);
        $this->assertStringContainsString('Nothing reaches anybody', $result->getNotificationMessage());
    }

    /** @param  array<string, mixed>  $providers */
    private function configureChain(array $providers): void
    {
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => array_keys($providers)],
            'mail.mailers.sendgrid' => ['transport' => 'sendgrid'],
            'mail.mailers.mailgun' => ['transport' => 'mailgun'],
            'mail.from.address' => 'payroll@example.test',
            'services.sendgrid.key' => $providers['sendgrid'] ?? null,
            'services.mailgun.domain' => $providers['mailgun']['domain'] ?? null,
            'services.mailgun.secret' => $providers['mailgun']['secret'] ?? null,
        ]);
    }
}

/** Horizon's repository on a host where Redis is not listening — the production case. */
class UnreachableHorizonRepository implements MasterSupervisorRepository
{
    public function names()
    {
        $this->refuse();
    }

    public function all()
    {
        $this->refuse();
    }

    public function find($name)
    {
        $this->refuse();
    }

    public function get(array $names)
    {
        $this->refuse();
    }

    public function update(MasterSupervisor $master)
    {
        $this->refuse();
    }

    public function forget($name)
    {
        $this->refuse();
    }

    public function flushExpired()
    {
        $this->refuse();
    }

    /**
     * What phpredis raises, as a plain exception: `RedisException` does not exist without the
     * extension, and the check has to survive whatever a broken Redis throws rather than one class.
     */
    private function refuse(): never
    {
        throw new RuntimeException('Connection refused');
    }
}
