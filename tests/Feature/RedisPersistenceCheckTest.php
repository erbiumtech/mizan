<?php

namespace Tests\Feature;

use App\Health\RedisPersistenceCheck;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

/**
 * Would a Redis restart empty the queue? The decision, fed canned answers, so it needs no Redis.
 *
 * The check exists because persistence is a server setting the application cannot make and cannot see
 * except by asking the running Redis — and with none configured, a restart loses every queued payslip
 * notification, PDF and report without an error anywhere.
 */
class RedisPersistenceCheckTest extends TestCase
{
    public function test_append_only_is_healthy(): void
    {
        $result = RedisPersistenceCheck::judge(['aof_enabled' => '1'], ['save' => '']);

        $this->assertSame(Status::ok(), $result->status);
    }

    public function test_snapshots_alone_are_a_warning_that_names_the_gap(): void
    {
        $result = RedisPersistenceCheck::judge(['aof_enabled' => '0'], ['save' => '3600 1 300 100']);

        $this->assertSame(Status::warning(), $result->status);
        $this->assertStringContainsString('since the last snapshot', $result->notificationMessage);
    }

    public function test_no_persistence_at_all_fails_and_says_what_is_lost(): void
    {
        $result = RedisPersistenceCheck::judge(['aof_enabled' => '0'], ['save' => '']);

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('empties the queue', $result->notificationMessage);
        $this->assertStringContainsString('appendonly yes', $result->notificationMessage);
    }

    /** Redis clients disagree about the shape of INFO and CONFIG GET; one flat map is what is judged. */
    public function test_every_client_shape_flattens_to_the_same_map(): void
    {
        $this->assertSame(
            ['aof_enabled' => '1', 'rdb_changes_since_last_save' => '0'],
            RedisPersistenceCheck::flatten("# Persistence\r\naof_enabled:1\r\nrdb_changes_since_last_save:0\r\n"),
            'phpredis returns INFO as text',
        );

        $this->assertSame(
            ['save' => '3600 1'],
            RedisPersistenceCheck::flatten(['save', '3600 1']),
            'CONFIG GET as a [key, value] list',
        );

        $this->assertSame(
            ['aof_enabled' => '0'],
            RedisPersistenceCheck::flatten(['Persistence' => ['aof_enabled' => '0']]),
            'predis returns INFO nested by section',
        );
    }
}
