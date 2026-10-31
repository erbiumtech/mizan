<?php

namespace App\Modules\Work\Integrations;

use App\Modules\Work\Integrations\Facts\Fact;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Models\WorkRepository;
use Illuminate\Http\Request;

/**
 * What a code host has to be able to do — docs/project-management-plan.md §8.
 *
 * Everything after `facts()` is provider-free: a delivery is verified, stored and turned into
 * commits, branches and pull requests, and `FactHandler` never learns which host they came from.
 * The outbound half is the three actions the Development panel offers plus the GitHub release.
 *
 * Each method takes the connection or the repository it acts for, so one driver instance serves
 * every connection to that provider.
 */
interface CodeProvider
{
    public function key(): string;

    /** Whether the delivery is genuinely from the provider for this connection — §8.3. */
    public function verify(IntegrationConnection $connection, Request $request): bool;

    /** The provider's own delivery id, which makes a redelivery a no-op. */
    public function eventId(Request $request): ?string;

    /** The provider's event name — `push`, `pull_request`, `create`. */
    public function eventType(Request $request): string;

    /**
     * One stored delivery, as the facts it states.
     *
     * @return array<int, Fact>
     */
    public function facts(IntegrationEvent $event): array;

    /**
     * The repositories the connection's credential can see, for the picker.
     *
     * @return array<int, array{id: string, full_name: string, default_branch: string, url: string}>
     */
    public function repositories(IntegrationConnection $connection): array;

    /** Register the inbound hook on the provider; returns the hook's id there, or null when it could not. */
    public function registerWebhook(WorkRepository $repository, string $url, string $secret): ?string;

    public function removeWebhook(WorkRepository $repository): void;

    /** @return array{name: string, url: ?string} */
    public function createBranch(WorkRepository $repository, string $name, ?string $from = null): array;

    /** @return array{number: int, url: ?string, title: string} */
    public function openPullRequest(WorkRepository $repository, string $head, string $base, string $title, string $body): array;

    /** @return array{id: string, url: ?string} */
    public function createRelease(WorkRepository $repository, string $tag, string $name, string $body): array;

    /** A pull request URL on this provider, as (repository full name, number) — or null when it is not one. */
    public function parsePullRequestUrl(string $url): ?array;
}
