<?php

namespace App\Modules\ConstructionField\Sync;

use App\Modules\ConstructionField\Models\PunchInspection;
use App\Modules\ConstructionField\Models\PunchItem;
use App\Modules\ConstructionField\Services\PunchListService;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use App\Support\Sync\Stale;
use App\Support\Sync\SyncChanges;
use App\Support\Sync\SyncCursor;
use App\Support\Sync\SyncWorkflow;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Snag status changes, offline — docs/desktop-mobile-plan.md O2, and **the one mutable document the
 * engine has**.
 *
 * A punch item is changed by more than one person: the foreman marks it ready on a phone that has been
 * dark since lunch while the site manager has already failed it at re-inspection in the office. So a
 * command carries the `version` the device's copy had (`base_version`), and rule 4 decides by it alone:
 *
 *  - **Matching version**: applied, through PunchListService exactly as the panel's row actions apply it
 *    (rule 6). `ready` is readyForInspection(), `inspect` is inspect() — still the only way an item
 *    closes — and `reject` is reject() with its reason. The service's refusals are refusals here.
 *  - **Stale version**: the server's value is kept. The device's change is written onto the item's notes
 *    for a human, with who, when, what, and the two versions — the activity log records that note like
 *    any other change to the item — and the command is answered `stale` (409) with the item as the
 *    server holds it. No merging and no merge screen, which is the whole of the plan's policy.
 *
 * The read is the punch items the person may read (`ConstructionPunchView`, the panel's rule), in
 * `updated_at` order. An item that has never been inspected may be deleted outright, so `deleted` is
 * read from the activity log's record of the deletion — the one place a hard delete leaves a trace.
 */
class PunchItemWorkflow implements SyncWorkflow
{
    public const KEY = 'punch-items';

    /** @var array<string, string> What each action asks of the item's policy, and how the note describes it. */
    private const ACTIONS = [
        'ready' => 'update',
        'inspect' => 'inspect',
        'reject' => 'reject',
    ];

    public function __construct(private readonly PunchListService $punch) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'construction_field';
    }

    public function rules(): array
    {
        return [
            'punch_item_id' => ['required', 'integer', 'min:1'],
            'base_version' => ['required', 'integer', 'min:0'],
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'result' => ['nullable', 'string', 'in:'.implode(',', array_keys(PunchInspection::RESULTS))],
            'inspected_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function apply(SyncCommand $command, User $user): array
    {
        if (! Gate::forUser($user)->allows('create', PunchItem::class)) {
            throw new InvalidArgumentException('You may not change snags in this company.');
        }

        $payload = $command->payload;
        $item = PunchItem::query()->find($payload['punch_item_id']);

        if ($item === null) {
            throw new InvalidArgumentException('That snag is not in this company any more.');
        }

        $action = $payload['action'];

        if ((int) $item->version !== (int) $payload['base_version']) {
            $item = $this->punch->fileOfflineChange($item, $this->describe($command, $user, $item));

            throw new Stale(
                "{$item->displayName()} changed after this device's copy was taken, so the change was not applied. It has been written on the item for somebody to look at.",
                ['item' => $item->toClient()],
            );
        }

        if (! Gate::forUser($user)->allows(self::ACTIONS[$action], $item)) {
            throw new InvalidArgumentException("{$item->displayName()} is {$item->status}, so it cannot be changed from here.");
        }

        match ($action) {
            'ready' => $this->punch->readyForInspection($item),
            'inspect' => $this->punch->inspect($item, array_filter([
                'result' => $payload['result'] ?? null,
                'inspected_on' => $payload['inspected_on'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ], fn ($value): bool => $value !== null)),
            'reject' => $this->punch->reject($item, (string) ($payload['reason'] ?? '')),
        };

        return ['item' => $item->refresh()->toClient()];
    }

    public function changesSince(?string $cursor, User $user, int $limit): SyncChanges
    {
        abort_unless(Gate::forUser($user)->allows('viewAny', PunchItem::class), 403, 'You may not read snags in this company.');

        $after = SyncCursor::decode($cursor);
        $rows = SyncCursor::apply(PunchItem::query(), $after)->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        // Deletions since the cursor's moment. A device reading from the start holds nothing to delete. Repeated
        // across pages when two deletions share a second, which costs a device nothing: removing an item it no
        // longer holds is a no-op.
        $deleted = $after === null ? [] : ActivityLog::query()
            ->where('subject_type', (new PunchItem)->getMorphClass())
            ->where('event', 'deleted')
            ->where('created_at', '>=', $after->updatedAt->format('Y-m-d H:i:s'))
            ->pluck('subject_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new SyncChanges(
            $page->map(fn (PunchItem $item): array => $item->toClient())->values()->all(),
            $deleted,
            $last ? SyncCursor::fromModel($last)->encode() : $cursor,
            $hasMore,
        );
    }

    /** The note a stale change leaves on the item: who, when, what they tried, and against which version. */
    private function describe(SyncCommand $command, User $user, PunchItem $item): string
    {
        $payload = $command->payload;
        $tried = match ($payload['action']) {
            'ready' => 'mark it ready for inspection',
            'inspect' => 'record an inspection: '.(PunchInspection::RESULTS[$payload['result'] ?? ''] ?? 'no result given')
                .(filled($payload['notes'] ?? null) ? ' ('.$payload['notes'].')' : ''),
            'reject' => 'agree it is not a defect: '.($payload['reason'] ?? 'no reason given'),
            default => $payload['action'],
        };

        $when = ($command->captured_at ?? $command->received_at)?->format('d M Y H:i');

        return sprintf(
            'Offline change not applied — %s tried on %s to %s. It was made against version %d; the item was at version %d (%s) when it arrived.',
            $user->name,
            $when,
            $tried,
            (int) $payload['base_version'],
            (int) $item->version,
            PunchItem::STATUSES[$item->status] ?? $item->status,
        );
    }
}
