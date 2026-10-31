<?php

namespace App\Modules\Attendance\Sync;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use App\Support\EmployeeAccess;
use App\Support\Sync\SyncChanges;
use App\Support\Sync\SyncCursor;
use App\Support\Sync\SyncWorkflow;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Attendance punches, offline — docs/desktop-mobile-plan.md O1: the simplest append-only shape there is,
 * and the one the engine is proven on.
 *
 * A command is one punch at one moment, for the person who sent it — never for anybody else, exactly as
 * `POST /my-attendance/clock` is. It goes through AttendanceRecorder::punch(), the same method the
 * online clock-in calls with `now()`, so lateness, overtime, the leave contradiction and the geofence
 * are judged once, in one place (rule 6). Two punches that arrive in the wrong order come out right:
 * the earlier moment is the check-in whichever was received first.
 *
 * The incremental read is the person's attendance days within what they may see — their own, their
 * reports' if they manage people, everyone's for a privileged role — in `updated_at` order. There are
 * no tombstones: an attendance day is never soft-deleted (it is corrected, or pruned by retention), so
 * `deleted` is always empty here and a client treats a day it holds that stops arriving as still true.
 */
class AttendancePunchWorkflow implements SyncWorkflow
{
    public const KEY = 'attendance';

    public function __construct(
        private readonly AttendanceRecorder $recorder,
        private readonly EmployeeAccess $access,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'attendance';
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:punch'],
            'punched_at' => ['required', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function apply(SyncCommand $command, User $user): array
    {
        $employee = $this->access->employeeFor($user);

        if ($employee === null) {
            throw new InvalidArgumentException('Employee profile not found.');
        }

        $payload = $command->payload;
        $position = isset($payload['latitude'], $payload['longitude'])
            ? ['latitude' => $payload['latitude'], 'longitude' => $payload['longitude'], 'accuracy_m' => $payload['accuracy_m'] ?? null]
            : null;

        $day = $this->recorder->punch(
            $employee,
            Carbon::parse($payload['punched_at']),
            $position,
            AttendanceDay::SOURCE_SELF_SERVICE,
            isset($payload['note']) ? (string) $payload['note'] : null,
        );

        return $day->toClient();
    }

    public function changesSince(?string $cursor, User $user, int $limit): SyncChanges
    {
        $query = AttendanceDay::query();
        $this->access->scopeAccessibleEmployees($query, $user, 'employee_id');

        $rows = SyncCursor::apply($query, SyncCursor::decode($cursor))->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return new SyncChanges(
            $page->map(fn (AttendanceDay $day): array => $day->toClient())->values()->all(),
            [],
            $last ? SyncCursor::fromModel($last)->encode() : $cursor,
            $hasMore,
        );
    }
}
