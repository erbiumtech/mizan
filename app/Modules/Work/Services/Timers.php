<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Integrations\Facts\TimeEntryFact;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationIdentity;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkTimeProject;
use App\Modules\Work\Models\WorkTimeTask;
use App\Support\Integrations;
use RuntimeException;

/**
 * Start, stop, and the task that must exist first — docs/project-management-plan.md §8.8.
 *
 * **The mode is the option the owner asked for.** `here`: Log time writes the ledger and the timer
 * buttons are absent. `clockify`: the buttons are present, and Log time is absent on an item that
 * has a task — an hour that could be entered in both places will be — but stays on an item with
 * no task, and on every item for a user with no linked identity, who has no other way. `either`:
 * both, and the time-by-source report is where a manager finds the day somebody did both. A
 * project overrides the company.
 *
 * Nothing flows out but the timer: rates, cost and the invoice are this application's (§9).
 */
class Timers
{
    public const HERE = 'here';

    public const CLOCKIFY = 'clockify';

    public const EITHER = 'either';

    public const MODES = [
        self::HERE => 'Here — Log time on the item',
        self::CLOCKIFY => 'Clockify — the timer, and Log time only where there is no task',
        self::EITHER => 'Either — both, reconciled in the time-by-source report',
    ];

    public function __construct(
        private readonly IntegrationEvents $events,
        private readonly TimeSync $sync,
    ) {}

    /** The mode in force for a project: its own say, else the company's. */
    public function modeFor(?Project $project): string
    {
        $mode = $project?->work_time_source ?: setting('work.time_source', self::HERE);

        return in_array($mode, [self::HERE, self::CLOCKIFY, self::EITHER], true) ? $mode : self::HERE;
    }

    /** The time tracker linked to this project, if any. */
    public function timeProjectFor(WorkItem $item): ?WorkTimeProject
    {
        return WorkTimeProject::query()
            ->where('project_id', $item->project_id)
            ->with('connection')
            ->whereHas('connection', fn ($q) => $q->where('status', '!=', IntegrationConnection::DISCONNECTED))
            ->first();
    }

    public function taskFor(WorkItem $item): ?WorkTimeTask
    {
        return WorkTimeTask::query()->where('work_item_id', $item->getKey())->with('timeProject.connection')->first();
    }

    /** The user's identity on the project's tracker, if they have one. */
    public function identityFor(WorkItem $item, ?User $user): ?IntegrationIdentity
    {
        $timeProject = $this->timeProjectFor($item);

        return $timeProject === null || $user === null ? null : $this->sync->identityFor($timeProject->connection, $user);
    }

    /** Whether the timer buttons are offered to this user on this item. */
    public function timerAvailable(WorkItem $item, ?User $user): bool
    {
        if (! modules()->enabled('timesheets') || $this->modeFor($item->project) === self::HERE) {
            return false;
        }

        return $this->identityFor($item, $user) !== null;
    }

    /**
     * Whether *Log time* is offered — the rule that keeps an hour from having two doors.
     *
     * Absent only in `clockify` mode, on an item with a task, for a user with a linked identity.
     */
    public function logTimeAllowed(WorkItem $item, ?User $user): bool
    {
        if ($this->modeFor($item->project) !== self::CLOCKIFY) {
            return true;
        }

        return $this->taskFor($item) === null || $this->identityFor($item, $user) === null;
    }

    /**
     * The tracker's task for this item, made if missing — named `WRK-12 · title`.
     *
     * @throws RuntimeException
     */
    public function ensureTask(WorkItem $item): WorkTimeTask
    {
        $existing = $this->taskFor($item);

        if ($existing !== null) {
            return $existing;
        }

        $timeProject = $this->timeProjectFor($item);

        if ($timeProject === null) {
            throw new RuntimeException('This project is not linked to a time tracker project. Link one on the integration\'s page first.');
        }

        $name = $item->key.' · '.\Illuminate\Support\Str::limit($item->title, 200, '');
        $made = $this->events->timeProviderFor($timeProject->connection)->createTask($timeProject->connection, $timeProject->external_project_id, $name);

        if (blank($made['id'])) {
            throw new RuntimeException(Integrations::label($timeProject->connection->provider).' did not return a task id.');
        }

        return WorkTimeTask::query()->create([
            'work_item_id' => $item->getKey(),
            'work_time_project_id' => $timeProject->getKey(),
            'external_task_id' => $made['id'],
            'name' => $made['name'] ?: $name,
        ]);
    }

    /** The project says a task should exist as soon as the item is in progress — §8.8, §16.9. */
    public function tasksOnProgress(?Project $project): bool
    {
        return (bool) ($project?->work_task_on_progress ?? false);
    }

    /**
     * Start a timer on the item, for this user, in the tracker.
     *
     * @throws RuntimeException
     */
    public function start(WorkItem $item, User $user, bool $billable = true): array
    {
        $identity = $this->identityFor($item, $user);

        if ($identity === null) {
            throw new RuntimeException('Your account is not linked to a user on this project\'s time tracker. Claim your identity on the integration\'s page first.');
        }

        $task = $this->ensureTask($item);
        $connection = $task->timeProject->connection;

        return $this->events->timeProviderFor($connection)->startTimer(
            $connection,
            $identity->provider_username,
            $task->timeProject->external_project_id,
            $task->external_task_id,
            $item->display_label,
            $billable,
        );
    }

    /**
     * Stop the user's running timer. The stopped entry lands in the ledger the way a webhook's
     * would, so a company without webhooks still sees it before the next pull.
     *
     * @throws RuntimeException
     */
    public function stop(WorkItem $item, User $user): ?TimeEntryFact
    {
        $identity = $this->identityFor($item, $user);
        $timeProject = $this->timeProjectFor($item);

        if ($identity === null || $timeProject === null) {
            throw new RuntimeException('Nothing to stop: your account is not linked to this project\'s time tracker.');
        }

        $connection = $timeProject->connection;
        $provider = $this->events->timeProviderFor($connection);
        $stopped = $provider->stopTimer($connection, $identity->provider_username);

        if ($stopped !== null) {
            $this->events->hold($connection, $stopped->externalId.':STOPPED_HERE:'.md5((string) json_encode([$stopped->start, $stopped->end])), 'TIMER_STOPPED', [
                'id' => $stopped->externalId,
                'userId' => $stopped->externalUserId,
                'projectId' => $stopped->externalProjectId,
                'taskId' => $stopped->externalTaskId,
                'description' => $stopped->description,
                'billable' => $stopped->billable,
                'timeInterval' => ['start' => $stopped->start->toIso8601String(), 'end' => $stopped->end?->toIso8601String()],
            ]);
        }

        return $stopped;
    }

    /** The user's running timer on this project's tracker, as *running, 00:41* — or null. */
    public function running(WorkItem $item, ?User $user): ?TimeEntryFact
    {
        $identity = $this->identityFor($item, $user);
        $timeProject = $this->timeProjectFor($item);

        if ($identity === null || $timeProject === null) {
            return null;
        }

        try {
            return $this->events->timeProviderFor($timeProject->connection)->runningTimer($timeProject->connection, $identity->provider_username);
        } catch (RuntimeException) {
            return null;
        }
    }

    public static function elapsed(TimeEntryFact $running): string
    {
        $seconds = max(0, (int) $running->start->diffInSeconds(now()));

        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
