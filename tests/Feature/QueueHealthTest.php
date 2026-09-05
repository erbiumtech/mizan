<?php

namespace Tests\Feature;

use App\Health\FailedJobsCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;
use Tests\TestCase;

/**
 * Is a worker consuming the queue, and is what it ran failing quietly?
 *
 * The bell was empty on this installation because no worker had ever run and eighteen jobs sat in Redis —
 * and nothing said so. `ScheduleCheck` proved cron fired the dispatcher; nothing proved anything was on
 * the other end. These two checks are that other end.
 */
class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queue_check_is_registered_and_its_heartbeat_is_scheduled(): void
    {
        $registered = collect(Health::registeredChecks())->map(fn ($check): string => $check::class);

        $this->assertContains(QueueCheck::class, $registered->all());
        $this->assertContains(FailedJobsCheck::class, $registered->all());

        $heartbeats = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'health:queue-check-heartbeat'));

        $this->assertCount(1, $heartbeats, 'QueueCheck can only pass if something pushes its heartbeat job');
        $this->assertSame('* * * * *', $heartbeats->first()->expression, 'every minute, like the schedule heartbeat');
    }

    public function test_no_failed_jobs_is_healthy(): void
    {
        $this->assertSame(Status::ok(), (new FailedJobsCheck)->run()->status);
    }

    public function test_one_failed_job_is_worth_a_look_and_many_are_a_fault(): void
    {
        $check = (new FailedJobsCheck)->warnWhenCountIsAtLeast(1)->failWhenCountIsAtLeast(3);

        $this->failAJob();
        $this->assertSame(Status::warning(), $check->run()->status);

        $this->failAJob();
        $this->failAJob();
        $result = $check->run();
        $this->assertSame(Status::failed(), $result->status);
        $this->assertSame(3, $result->meta['count']);
    }

    private function failAJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'It broke.',
            'failed_at' => now(),
        ]);
    }
}
