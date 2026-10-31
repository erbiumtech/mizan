<?php

namespace App\Modules\ConstructionField\Sync;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogEvent;
use App\Modules\ConstructionField\Models\DailyLogPhoto;
use App\Modules\ConstructionField\Services\DailyLogService;
use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use App\Support\Sync\AcceptsBlobs;
use App\Support\Sync\SyncChanges;
use App\Support\Sync\SyncCursor;
use App\Support\Sync\SyncWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Site diary entries, offline — docs/desktop-mobile-plan.md O2.
 *
 * **Append-only, so it cannot conflict.** A command is one line on a day's diary — a delay, an instruction,
 * a visitor, a stoppage, an inspection, an incident — for one job on one date. It goes through
 * DailyLogService (rule 6): the day is found or opened by `open()`, which keeps §16.1's one-diary-per-day,
 * and the line is added by `addEvent()`, which refuses a day that has been signed off. Two lines that
 * arrive in the wrong order are two lines on the same day: the time each one happened is its own
 * `started_at`, and the device's clock is never the order.
 *
 * **Photographs ride behind their line** (rule 5): uploaded one at a time once the line has landed, each
 * attached to the line's day and to the line itself through `addPhoto()`, on the same disk and directory
 * the diary's Photos tab stores in.
 *
 * The read is the diary days the person may read — the panel's own rule, `ConstructionDailyLogView` —
 * each with its lines and photographs, in `updated_at` order. A day is never deleted; a line removed from
 * an open day moves the day, so the day comes round again without it, and `deleted` is always empty.
 */
class SiteDiaryWorkflow implements AcceptsBlobs, SyncWorkflow
{
    public const KEY = 'site-diary';

    /** The Photos tab's own cap, so a photograph the panel would take is one the outbox may send. */
    public const MAX_KILOBYTES = 12288;

    public function __construct(private readonly DailyLogService $diary) {}

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
            'type' => ['required', 'string', 'in:entry'],
            'job_id' => ['required', 'integer', 'min:1'],
            'log_date' => ['required', 'date'],
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(DailyLogEvent::KINDS))],
            'description' => ['required', 'string', 'max:2000'],
            'started_at' => ['nullable', 'date_format:H:i'],
            'ended_at' => ['nullable', 'date_format:H:i'],
            'hours_lost' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'responsibility' => ['nullable', 'string', 'in:'.implode(',', array_keys(DailyLogEvent::RESPONSIBILITIES))],
            'raised_with' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function apply(SyncCommand $command, User $user): array
    {
        if (! Gate::forUser($user)->allows('create', DailyLog::class)) {
            throw new InvalidArgumentException('You may not write the site diary in this company.');
        }

        $payload = $command->payload;
        $job = Job::query()->find($payload['job_id']);

        if ($job === null) {
            throw new InvalidArgumentException('That job is not in this company.');
        }

        $date = Carbon::parse($payload['log_date'])->toDateString();

        // Found or opened, never two: open() is what keeps §16.1's one diary per day per job, and it refuses a
        // second, so the day that already exists is looked for first.
        $log = DailyLog::query()->where('job_id', $job->getKey())->on($date)->first()
            ?? $this->diary->open($job, $date);

        $event = $this->diary->addEvent($log, array_filter([
            'kind' => $payload['kind'],
            'description' => $payload['description'],
            'started_at' => $payload['started_at'] ?? null,
            'ended_at' => $payload['ended_at'] ?? null,
            'hours_lost' => $payload['hours_lost'] ?? null,
            'responsibility' => $payload['responsibility'] ?? null,
            'raised_with' => $payload['raised_with'] ?? null,
        ], fn ($value): bool => $value !== null));

        return [
            'event_id' => (int) $event->getKey(),
            'daily_log' => $log->refresh()->load(['events', 'photos'])->toClient(),
        ];
    }

    public function changesSince(?string $cursor, User $user, int $limit): SyncChanges
    {
        abort_unless(Gate::forUser($user)->allows('viewAny', DailyLog::class), 403, 'You may not read the site diary in this company.');

        $rows = SyncCursor::apply(DailyLog::query()->with(['events', 'photos']), SyncCursor::decode($cursor))
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return new SyncChanges(
            $page->map(fn (DailyLog $log): array => $log->toClient())->values()->all(),
            [],
            $last ? SyncCursor::fromModel($last)->encode() : $cursor,
            $hasMore,
        );
    }

    public function blobRules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:'.self::MAX_KILOBYTES],
            'caption' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'in:'.implode(',', array_keys(DailyLogPhoto::SUBJECTS))],
        ];
    }

    public function blobDirectory(): string
    {
        return 'construction/site-photos';
    }

    public function attachBlob(SyncCommand $command, array $file, array $fields, User $user): array
    {
        if (! Gate::forUser($user)->allows('create', DailyLog::class)) {
            throw new InvalidArgumentException('You may not write the site diary in this company.');
        }

        $log = DailyLog::query()->find($command->result['daily_log']['id'] ?? null);

        if ($log === null) {
            throw new InvalidArgumentException('The diary day this photograph belongs to is no longer there.');
        }

        // The line it was taken with, if that line is still on the day.
        $eventId = $command->result['event_id'] ?? null;
        $eventId = $eventId !== null && $log->events()->whereKey($eventId)->exists() ? (int) $eventId : null;

        $photo = $this->diary->addPhoto($log, array_filter([
            'file_path' => $file['path'],
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'file_mime' => $file['mime'],
            'file_hash' => $file['hash'],
            'caption' => $fields['caption'] ?? null,
            'subject' => $fields['subject'] ?? null,
            'event_id' => $eventId,
        ], fn ($value): bool => $value !== null));

        return ['photo' => $photo->toClient()];
    }
}
