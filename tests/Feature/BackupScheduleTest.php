<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The backups run, rather than merely being configured to be watched.
 *
 * spatie/laravel-backup was configured, `backup:tenants` was written, BackupsCheck watched the archive
 * directory — and nothing was scheduled to make an archive. `backup:list` said "There are no backups of this
 * application at all." A retention setting with no schedule behind it is a table that grows forever; a
 * monitor with no schedule behind it is a red light with nothing to be red about.
 */
class BackupScheduleTest extends TestCase
{
    public function test_clean_landlord_and_tenant_backups_are_each_scheduled_nightly(): void
    {
        $events = collect(app(Schedule::class)->events());

        $at = fn (string $command): ?string => $events
            ->first(fn ($event): bool => str_contains($event->command ?? '', $command))
            ?->expression;

        $this->assertSame('0 1 * * *', $at('backup:clean'), 'prune first, so the disk has room');
        $this->assertSame('30 1 * * *', $at('backup:run'), 'the landlord and the uploads');
        $this->assertSame('0 2 * * *', $at('backup:tenants'), 'one archive per company, after the landlord it needs');
    }
}
