<?php

namespace Tests\Feature;

use App\Modules\Projects\Jobs\CheckEnvironmentCertificate;
use App\Modules\Projects\Jobs\CheckEnvironmentHealth;
use Tests\TestCase;

/**
 * The queue's timing settings, which are only correct in relation to each other.
 *
 * Every value asserted here was wrong in the published defaults, and each one fails silently:
 * the workers keep running, the dashboard stays green, and what goes wrong goes wrong in the
 * customer's mailbox. So the assertions are about the *ordering* of the numbers rather than the
 * numbers themselves — the numbers are allowed to move, the relationships are not.
 *
 * The reason this needs asserting at all is that the longest-running thing on this queue does not
 * look like it: a payslip notification renders its own PDF through headless Chrome inside the
 * job (PayslipIssued::toMail calls PayslipService::renderPdf), so a "send an email" job can
 * legitimately occupy a worker for minutes.
 */
class QueueConfigurationTest extends TestCase
{
    /**
     * The measured cost of booting this application before it touches a job, in MB.
     *
     * Not a guess: Horizon refused to stay up on the shipped `memory_limit` of 64 with
     * `Memory limit exceeded: Using 66/64MB`, seconds after boot, with no jobs processed.
     * Filament's panels plus twenty-two module service providers cost this much to load.
     */
    private const BOOT_COST_MB = 66;

    /** Every supervisor definition, defaults plus any per-environment override. */
    private function supervisors(): array
    {
        $supervisors = array_values(config('horizon.defaults'));

        foreach (config('horizon.environments') as $environment) {
            $supervisors = array_merge($supervisors, array_values($environment));
        }

        return $supervisors;
    }

    // ------------------------------------------------------- the duplicate-email bug

    /**
     * `retry_after` must outlast the worker timeout. This is the assertion that matters most.
     *
     * `retry_after` is when Redis decides a reserved job was abandoned and lets a second worker
     * take it. The shipped pair was `retry_after => 90` against a worker `timeout` of 300, which
     * is the wrong way round: at 90 seconds a payslip PDF is still rendering, a second worker
     * picks the same job up, and the employee is emailed their payslip twice. Both attempts then
     * succeed, so there is no failed job and no error anywhere to find.
     *
     * Checked against every supervisor, including per-environment overrides, because production
     * is exactly where a raised timeout would be introduced and not noticed.
     */
    public function test_the_queue_waits_longer_than_a_worker_before_handing_the_job_to_someone_else(): void
    {
        $retryAfter = config('queue.connections.redis.retry_after');

        foreach ($this->supervisors() as $supervisor) {
            if (! isset($supervisor['timeout'])) {
                continue;
            }

            $this->assertGreaterThan(
                $supervisor['timeout'],
                $retryAfter,
                'queue.connections.redis.retry_after must exceed the worker timeout, or a job '
                .'still running is handed to a second worker and delivered twice',
            );
        }
    }

    /**
     * The worker has to outlive the render it is waiting on.
     *
     * `config('pdf.timeout')` is how long Browsershot is allowed to wait for headless Chrome, and
     * that wait happens inside the job. A worker timeout below it kills the job before the render
     * it is blocked on has given up — and the retry hits the same wall, so the notification is
     * never sent at all.
     *
     * Note this runs against the suite's `PDF_TIMEOUT` (180, set in phpunit.xml), which is higher
     * than the production default of 60. Asserting against the larger value is the stricter test.
     */
    public function test_a_worker_outlives_the_pdf_render_it_is_waiting_for(): void
    {
        $render = (int) config('pdf.timeout');

        foreach ($this->supervisors() as $supervisor) {
            if (! isset($supervisor['timeout'])) {
                continue;
            }

            $this->assertGreaterThan(
                $render,
                $supervisor['timeout'],
                'the worker gives up before the PDF render inside the job does',
            );
        }
    }

    // ------------------------------------------------------------------- retries

    /**
     * A transient failure must not be final.
     *
     * Horizon ships `tries => 1`, which means one SMTP timeout or one Redis blip is the end of
     * that payslip notification — it is never sent, and the employee has no way to know a mail
     * was ever meant to arrive.
     */
    public function test_a_transient_failure_is_retried(): void
    {
        $this->assertGreaterThan(1, config('horizon.defaults.supervisor-1.tries'));
    }

    /**
     * Not retried forever either. A job that has failed three times is failing for a reason a
     * fourth attempt will not fix, and it belongs in the failed-jobs table where somebody sees it
     * rather than in a loop.
     */
    public function test_retries_are_bounded(): void
    {
        $this->assertLessThanOrEqual(3, config('horizon.defaults.supervisor-1.tries'));
    }

    /**
     * The supervisor's `tries` is a default, and the jobs that must not be retried say so
     * themselves — which is the claim config/horizon.php makes, so it is asserted here.
     *
     * Both environment checks reach out over the network to a host that may simply be hanging.
     * Retrying those is a pile-up rather than a recovery, and a per-job property beats the
     * supervisor default, so raising `tries` above does not quietly change their behaviour.
     */
    public function test_the_jobs_that_must_not_be_retried_declare_it_themselves(): void
    {
        foreach ([CheckEnvironmentHealth::class, CheckEnvironmentCertificate::class] as $job) {
            $this->assertSame(
                1,
                (new \ReflectionClass($job))->getDefaultProperties()['tries'] ?? null,
                $job.' relies on the supervisor default instead of pinning its own tries',
            );
        }
    }

    // -------------------------------------------------------------------- memory

    /**
     * Both limits have to clear the cost of booting the application, which is most of them.
     *
     * The master supervisor on the shipped 64MB terminated and restarted itself continuously
     * while reporting "started successfully" — queued work would have stalled with nothing in the
     * log explaining why. A worker on the shipped 128 had the boot cost taken out of it before it
     * began, and would have died part-way through a payroll run instead.
     */
    public function test_the_memory_limits_clear_the_cost_of_booting_the_application(): void
    {
        $this->assertGreaterThan(
            self::BOOT_COST_MB * 2,
            config('horizon.memory_limit'),
            'the master supervisor restarts itself continuously below the boot cost',
        );

        foreach ($this->supervisors() as $supervisor) {
            if (! isset($supervisor['memory'])) {
                continue;
            }

            $this->assertGreaterThan(
                self::BOOT_COST_MB * 2,
                $supervisor['memory'],
                'a worker with little headroom over the boot cost dies inside the job',
            );
        }
    }

    // ------------------------------------------------------------------- plumbing

    /**
     * Horizon must consume the queue the application actually dispatches to.
     *
     * Both sides default to `default` and so agree out of the box, which is what makes this worth
     * pinning: setting `REDIS_QUEUE` moves every dispatched job to a queue no supervisor here is
     * listening on. Nothing errors. Jobs accumulate in Redis, are never processed, and Horizon's
     * dashboard shows an idle, healthy queue because the queue it is watching is idle and healthy.
     */
    public function test_horizon_listens_on_the_queue_the_application_dispatches_to(): void
    {
        $dispatchesTo = config('queue.connections.redis.queue');

        $this->assertContains(
            $dispatchesTo,
            config('horizon.defaults.supervisor-1.queue'),
            'jobs are dispatched to a queue no supervisor consumes',
        );
    }

    /** And on the connection those jobs are dispatched over. */
    public function test_the_supervisor_watches_the_redis_connection(): void
    {
        $this->assertSame('redis', config('horizon.defaults.supervisor-1.connection'));
        $this->assertSame('redis', config('queue.connections.redis.driver'));
    }
}
