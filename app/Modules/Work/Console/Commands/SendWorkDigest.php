<?php

namespace App\Modules\Work\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\NotificationPreferences;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Notifications\WorkDigest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * The morning digest — docs/project-management-plan.md §10.3.
 *
 * Scheduled hourly and sent in the hour the company chose, so one schedule entry serves every
 * company's own time. One notification per user who has anything in any of the three lists; a
 * person with nothing waiting hears nothing, because a digest that says "nothing" every morning is
 * a digest nobody opens on the morning it says something.
 *
 * TenantAware: with no --tenant option it runs once per company, with that company's connection
 * current. `--force` sends whatever the hour, for the person testing it.
 */
class SendWorkDigest extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'work:digest {--tenant=*} {--force : Send now, whatever the configured hour}';

    protected $description = 'Send each person their work for the day: due today, newly assigned, blocked on them';

    public function handle(): int
    {
        if ($this->skipsDisabledModule('work')) {
            return self::SUCCESS;
        }

        if (! (bool) setting('work.digest_enabled', true)) {
            $this->line('The digest is off for this company.');

            return self::SUCCESS;
        }

        $hour = substr((string) setting('work.digest_time', '08:00'), 0, 2);

        if (! $this->option('force') && now()->format('H') !== $hour) {
            $this->line('Not this company\'s hour ('.$hour.':00).');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($this->recipients() as $userId => $lists) {
            $user = User::query()->find($userId);

            if (! $user instanceof User || ! NotificationPreferences::wants($user, 'work')) {
                continue;
            }

            $user->notify(new WorkDigest(...$lists));
            $sent++;
        }

        $this->info("{$sent} digest(s) sent.");

        return self::SUCCESS;
    }

    /**
     * User id => [due today, assigned since yesterday, blocked on you], for users with anything.
     *
     * Three queries for the company rather than three per user: the lists are small and the
     * grouping is done once in PHP.
     *
     * @return array<int, array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}>
     */
    private function recipients(): array
    {
        $open = WorkItem::query()->open()->with(['assignee.user'])->whereNotNull('assignee_employee_id');

        $dueToday = (clone $open)->whereDate('due_on', today())->get();

        // "Since yesterday" is the last day's worth of changes to who holds it — `assigned_at`,
        // stamped when the assignee changes, not `updated_at`, which moves on every edit.
        $assigned = (clone $open)->where('assigned_at', '>=', now()->subDay())->get();

        // Items you hold that something else is waiting on.
        $blocked = (clone $open)
            ->whereHas('links', fn (Builder $l) => $l->where('kind', WorkItemLink::BLOCKS))
            ->get();

        $byUser = [];

        $add = function (Collection $items, int $slot) use (&$byUser): void {
            foreach ($items as $item) {
                $userId = $item->assignee?->user_id;

                if ($userId === null) {
                    continue;
                }

                $byUser[(int) $userId] ??= [[], [], []];
                $byUser[(int) $userId][$slot][] = $item->display_label;
            }
        };

        $add($dueToday, 0);
        $add($assigned, 1);
        $add($blocked, 2);

        return $byUser;
    }
}
