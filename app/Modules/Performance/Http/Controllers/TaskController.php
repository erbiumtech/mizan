<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Services\TaskRoller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The phone's task list — docs/saas-launch-plan.md §8.2. The caller's own
 * occurrences, or nothing; completing one is theirs alone.
 */
class TaskController extends Controller
{
    public function index(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $rows = TaskOccurrence::query()
            ->where('employee_id', $employee->getKey())
            ->where('due_on', '>=', now()->subDays(14)->toDateString())
            ->with('task')
            ->orderByDesc('due_at')
            ->get()
            ->map(fn (TaskOccurrence $o): array => $this->payload($o));

        return ApiResponse::success(['count' => $rows->count(), 'data' => $rows]);
    }

    public function complete(Request $request, TaskOccurrence $occurrence, TaskRoller $roller)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee || (int) $occurrence->employee_id !== (int) $employee->getKey()) {
            return ApiResponse::error('Task not found', 404);
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $roller->complete($occurrence, $data['note'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->payload($occurrence->fresh('task'))]);
    }

    /** @return array<string, mixed> */
    private function payload(TaskOccurrence $o): array
    {
        return [
            'id' => $o->getKey(),
            'title' => (string) $o->task?->title,
            'description' => $o->task?->description,
            'due_at' => $o->due_at?->toIso8601String(),
            'status' => $o->status(),
            'completed_at' => $o->completed_at?->toIso8601String(),
            'penalty_points' => (int) ($o->task?->penalty_points ?? 0),
        ];
    }
}
