<?php

namespace App\Modules\Work\Integrations;

use App\Modules\Work\Integrations\Facts\TimeEntryFact;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What a time tracker has to be able to do — docs/project-management-plan.md §8.8.
 *
 * A workspace is a connection; its projects and tasks are linked to ours; its entries become
 * timesheet rows, exactly once. Nothing below the parsing layer names Clockify, so Toggl Track is
 * a second implementation of this on the day somebody asks.
 */
interface TimeProvider
{
    public function key(): string;

    /** Whether the delivery is genuinely from the provider for this connection. */
    public function verify(IntegrationConnection $connection, Request $request): bool;

    public function eventId(Request $request): ?string;

    public function eventType(Request $request): string;

    /**
     * One stored delivery, as the entries it states.
     *
     * @return array<int, TimeEntryFact>
     */
    public function entries(IntegrationEvent $event): array;

    /**
     * Every entry touched since a moment — the fifteen-minute and nightly pulls.
     *
     * @return array<int, TimeEntryFact>
     */
    public function pull(IntegrationConnection $connection, Carbon $since): array;

    /**
     * The workspace's projects, for the picker.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function projects(IntegrationConnection $connection): array;

    /** @return array{id: string, name: string} */
    public function createProject(IntegrationConnection $connection, string $name, ?string $code = null): array;

    /** @return array{id: string, name: string} */
    public function createTask(IntegrationConnection $connection, string $externalProjectId, string $name): array;

    /**
     * The workspace's users, for identity matching: provider user id => email.
     *
     * @return array<string, array{email: ?string, name: ?string}>
     */
    public function users(IntegrationConnection $connection): array;

    /** Start a timer for a workspace user on a task. @return array{id: string} */
    public function startTimer(IntegrationConnection $connection, string $externalUserId, string $externalProjectId, ?string $externalTaskId, string $description, bool $billable): array;

    /** Stop the user's running timer, if any. @return TimeEntryFact|null the stopped entry */
    public function stopTimer(IntegrationConnection $connection, string $externalUserId): ?TimeEntryFact;

    /** The user's running timer, if any. */
    public function runningTimer(IntegrationConnection $connection, string $externalUserId): ?TimeEntryFact;
}
