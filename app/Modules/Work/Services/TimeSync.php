<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Work\Integrations\Facts\TimeEntryFact;
use App\Modules\Work\Integrations\TimeProvider;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationIdentity;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkTimeProject;
use App\Modules\Work\Models\WorkTimeTask;
use App\Modules\Work\Support\SyncedTimesheetRows;
use App\Support\EmployeeAccess;
use App\Support\Integrations;
use RuntimeException;

/**
 * Entries become timesheet entries, exactly once — docs/project-management-plan.md §8.8.
 *
 * Idempotent on the tracker's entry id: `external_ref` is unique per source, so the same entry
 * delivered three times is one row, an update rewrites that row, and a delete removes it. A
 * running timer is written nowhere — the ledger holds hours, not intentions. An entry on a linked
 * project with no task is time on the project with no item, which is still true; an entry on an
 * unlinked project is **held**, which is to say the event fails with the reason and waits on the
 * connection's page until the project is linked and it is replayed.
 *
 * The hourly rate Clockify sends is never read: the rate is this application's (§9).
 */
class TimeSync
{
    public function __construct(
        private readonly Identities $identities,
        private readonly EmployeeAccess $access,
    ) {}

    /**
     * @param  array<int, TimeEntryFact>  $facts
     *
     * @throws RuntimeException when an entry is held, so the event records why
     */
    public function handle(IntegrationConnection $connection, TimeProvider $provider, array $facts): void
    {
        if (! modules()->enabled('timesheets')) {
            throw new RuntimeException('There is no timesheet to put these hours in: the Timesheets module is not enabled.');
        }

        $held = [];

        foreach ($facts as $fact) {
            $reason = $this->apply($connection, $provider, $fact);

            if ($reason !== null) {
                $held[] = $reason;
            }
        }

        if ($held !== []) {
            throw new RuntimeException(implode(' ', array_unique($held)));
        }
    }

    /** @return string|null why the entry was held, or null when it landed */
    public function apply(IntegrationConnection $connection, TimeProvider $provider, TimeEntryFact $fact): ?string
    {
        $existing = TimesheetEntry::query()
            ->where('source', WorkTime::CLOCKIFY)
            ->where('external_ref', $this->ref($connection, $fact))
            ->first();

        if ($fact->deleted) {
            if ($existing !== null) {
                SyncedTimesheetRows::whileSyncing(fn () => $existing->delete());
                $this->record($existing->work_item_id, $connection, $this->userFor($connection, $provider, $fact), 'Entry '.$fact->externalId.' deleted in '.Integrations::label($connection->provider).'; the timesheet row went with it.');
            }

            return null;
        }

        if ($fact->isRunning()) {
            return null;
        }

        if ($fact->externalProjectId === null) {
            return null;
        }

        $timeProject = WorkTimeProject::query()
            ->where('integration_connection_id', $connection->getKey())
            ->where('external_project_id', $fact->externalProjectId)
            ->first();

        if ($timeProject === null) {
            return Integrations::label($connection->provider).' project '.$fact->externalProjectId.' is not linked to a project here; its entries are held until it is.';
        }

        $user = $this->userFor($connection, $provider, $fact);
        $employeeId = $this->access->ownEmployeeId($user);

        if ($user === null || $employeeId === null) {
            return Integrations::label($connection->provider).' user '.$fact->externalUserId.' is not matched to an employee here; their entries are held until they are.';
        }

        $task = $fact->externalTaskId === null ? null : WorkTimeTask::query()
            ->where('work_time_project_id', $timeProject->getKey())
            ->where('external_task_id', $fact->externalTaskId)
            ->first();

        $attributes = [
            'employee_id' => $employeeId,
            'project_id' => $timeProject->project_id,
            'date' => $fact->start->toDateString(),
            'minutes' => max(1, min(1440, $fact->minutes())),
            'is_billable' => $fact->billable,
            'task' => $task?->item?->key,
            'description' => $fact->description,
        ];

        SyncedTimesheetRows::whileSyncing(function () use ($existing, $attributes, $connection, $fact, $task): void {
            $entry = $existing ?? new TimesheetEntry;
            $entry->fill($attributes);
            $entry->forceFill([
                'work_item_id' => $task?->work_item_id,
                'source' => WorkTime::CLOCKIFY,
                'external_ref' => $this->ref($connection, $fact),
            ]);
            $entry->save();
        });

        $this->record($task?->work_item_id, $connection, $user, ($existing !== null ? 'Entry '.$fact->externalId.' updated' : 'Entry '.$fact->externalId.' synced').' from '.Integrations::label($connection->provider).': '.WorkTime::duration($attributes['minutes']).' on '.$attributes['date'].'.');

        return null;
    }

    /** `connection:entry` — unique per source, and the same entry from two workspaces is two rows. */
    private function ref(IntegrationConnection $connection, TimeEntryFact $fact): string
    {
        return $connection->getKey().':'.$fact->externalId;
    }

    /** The user behind a tracker user id, through the identity table — §8.5. */
    public function userFor(IntegrationConnection $connection, TimeProvider $provider, TimeEntryFact $fact): ?User
    {
        $email = $fact->userEmail;
        $name = $fact->userName;

        if ($email === null) {
            try {
                $known = $provider->users($connection)[$fact->externalUserId] ?? null;
                $email = $known['email'] ?? null;
                $name = $known['name'] ?? $name;
            } catch (RuntimeException) {
                // The directory call failed; match on what the delivery carried, if anything.
            }
        }

        $identity = $this->identities->resolve($connection, [
            'username' => $fact->externalUserId,
            'id' => $fact->externalUserId,
            'email' => $email,
        ]);

        return $identity?->user();
    }

    /** The identity for a user on this connection, if they have one — what the timer buttons need. */
    public function identityFor(IntegrationConnection $connection, User $user): ?IntegrationIdentity
    {
        return IntegrationIdentity::query()
            ->where('integration_connection_id', $connection->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    /** *by Clockify (via Ahmed Raza)* — the integration never acts anonymously. */
    private function record(?int $itemId, IntegrationConnection $connection, ?User $user, string $description): void
    {
        $item = $itemId === null ? null : WorkItem::query()->find($itemId);
        $provider = Integrations::label($connection->provider);

        $log = activity('Integration')->causedBy($user)->withProperties(['provider' => $provider, 'via' => $user?->name]);

        if ($item !== null) {
            $log->performedOn($item);
        }

        $log->log('By '.$provider.($user !== null ? ' (via '.$user->name.')' : '').': '.$description);
    }
}
