<?php

namespace App\Support;

use App\Modules\Core\Models\User;
use Closure;
use InvalidArgumentException;

/**
 * What is waiting for the signed-in person to decide — the "Waiting for you" card on the dashboard.
 *
 * Leave and Expenses register a source each from their service providers. A source lists the pending items
 * the person may decide and decides one, through the same queue the `/v1` approvals endpoints use
 * (docs/desktop-mobile-plan.md A4), so the dashboard can never offer what the service would refuse — an
 * Administrator's own claim included. Core's `WaitingForYou` widget only draws and dispatches, so it names
 * no module.
 *
 * `visible` is a licence and permission check with no query, for the widget's `canView()`.
 */
class DashboardApprovals
{
    /**
     * @var array<string, array{source: string, sort: int, visible: Closure, pending: Closure, approve: Closure, refuse: Closure, url: Closure}>
     */
    private static array $sources = [];

    /**
     * @param  Closure(User): bool  $visible
     * @param  Closure(User): array<int, array{id: int, initials: string, title: string, meta: string}>  $pending
     * @param  Closure(User, int): void  $approve  throws InvalidArgumentException or RuntimeException with a reason to show
     * @param  Closure(User, int, string): void  $refuse
     * @param  Closure(): ?string  $url  where the full list lives
     */
    public static function register(
        string $source,
        Closure $visible,
        Closure $pending,
        Closure $approve,
        Closure $refuse,
        Closure $url,
        int $sort = 100,
    ): void {
        self::$sources[$source] = compact('source', 'sort', 'visible', 'pending', 'approve', 'refuse', 'url');
    }

    public static function anyVisible(?User $user): bool
    {
        return $user !== null && self::visibleSources($user) !== [];
    }

    /**
     * Every pending item across the sources, oldest first within each source.
     *
     * @return array<int, array{key: string, source: string, id: int, initials: string, title: string, meta: string}>
     */
    public static function pendingFor(User $user, int $limit = 6): array
    {
        $items = [];

        foreach (self::visibleSources($user) as $source) {
            foreach (($source['pending'])($user) as $item) {
                $items[] = ['key' => $source['source'].':'.$item['id'], 'source' => $source['source'], ...$item];
            }
        }

        return array_slice($items, 0, $limit);
    }

    /** Where "View all" goes: the first source that has a list this person may open. */
    public static function viewAllUrl(User $user): ?string
    {
        foreach (self::visibleSources($user) as $source) {
            if ($url = ($source['url'])()) {
                return $url;
            }
        }

        return null;
    }

    public static function approve(User $user, string $key): void
    {
        [$source, $id] = self::parse($user, $key);

        ($source['approve'])($user, $id);
    }

    public static function refuse(User $user, string $key, string $reason): void
    {
        [$source, $id] = self::parse($user, $key);

        ($source['refuse'])($user, $id, $reason);
    }

    public static function flush(): void
    {
        self::$sources = [];
    }

    /** Two letters for an avatar: first and last name's initials, or the first two of a single name. */
    public static function initials(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : mb_substr($words[0], 1, 1);

        return mb_strtoupper($first.$last);
    }

    /** @return array<int, array<string, mixed>> */
    private static function visibleSources(User $user): array
    {
        $sources = array_filter(self::$sources, fn (array $source): bool => (bool) ($source['visible'])($user));
        usort($sources, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_values($sources);
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private static function parse(User $user, string $key): array
    {
        [$name, $id] = array_pad(explode(':', $key, 2), 2, null);

        $source = self::$sources[$name] ?? null;

        if ($source === null || ! ctype_digit((string) $id) || ! ($source['visible'])($user)) {
            throw new InvalidArgumentException(__('This item is no longer waiting for you.'));
        }

        return [$source, (int) $id];
    }
}
