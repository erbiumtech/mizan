<?php

namespace App\Modules\Employees\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Employees\Models\Announcement;
use App\Modules\Employees\Notifications\AnnouncementPublished;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;
use Throwable;

/**
 * Announcements whose date has come — docs/saas-launch-plan.md §14.10.
 *
 * Scheduling one has always been half-possible: `scopeCurrent` respects a future
 * `published_at`, so the dashboard would show it on the day. What was missing is that
 * nobody was told — a notice everybody has to go looking for is a notice nobody reads.
 *
 * **It tells people once, and the window is what makes that safe.** A run looks at
 * announcements whose date fell inside the last day; an announcement published at
 * three in the morning is told at the next run and never again, because by the
 * following run its date is more than a day old. A second column recording that it
 * had been told would say almost exactly what `published_at` already says.
 *
 * The consequence, stated rather than hidden: a company whose scheduler was down for
 * two days misses the telling for anything published in that gap. The announcement is
 * still on the dashboard, which is where the other half of this feature has always
 * worked.
 */
class PublishDueAnnouncements extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'announcements:publish {--tenant=*}';

    protected $description = 'Tell the audience about announcements whose publish date has arrived';

    public function handle(): int
    {
        if ($this->skipsDisabledModule('employees')) {
            return self::SUCCESS;
        }

        $told = 0;
        $failed = 0;

        foreach (Announcement::query()->dueToAnnounce()->get() as $announcement) {
            try {
                $audience = $announcement->audienceUsers();

                if ($audience->isNotEmpty()) {
                    Notification::send($audience, new AnnouncementPublished($announcement));
                    $told += $audience->count();
                }
            } catch (Throwable $e) {
                // One announcement with an audience that cannot be resolved must not stop
                // the others, and a company should hear about it rather than wonder.
                report($e);
                $failed++;
                $this->components->error($announcement->title.': '.$e->getMessage());
            }
        }

        $this->components->info("Told {$told} person(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
