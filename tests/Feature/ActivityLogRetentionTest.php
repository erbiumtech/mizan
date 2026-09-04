<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The audit trail is pruned, not merely configured to be.
 *
 * `config/activitylog.php` said "365 days" from the day the log was added and nothing ever ran the command
 * that enforces it. A retention setting with no schedule behind it is a table that grows forever while
 * looking managed — and this one is a landlord table every company writes to, and the one the bell now
 * hangs off.
 */
class ActivityLogRetentionTest extends TestCase
{
    public function test_the_clean_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'activitylog:clean'));

        $this->assertCount(1, $events, 'activitylog:clean must be on the schedule exactly once');

        // Daily, in the quiet hours: a cron of the form "M H * * *".
        $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $events->first()->expression);
    }

    public function test_retention_is_bounded(): void
    {
        $days = (int) config('activitylog.delete_records_older_than_days');

        $this->assertGreaterThan(0, $days, 'a retention of 0 means "keep everything", which is what this exists to prevent');
        $this->assertLessThanOrEqual(3 * 365, $days);
    }
}
