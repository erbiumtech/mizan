<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Services\FieldVisits;
use App\Modules\Employees\Models\Employee;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The phone's side of a visit — docs/saas-launch-plan.md §8.5. The caller's own
 * visits, or nothing; same stack as /my-attendance/clock.
 */
class FieldVisitController extends Controller
{
    public function index(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $visits = FieldVisit::query()
            ->with('subject')
            ->where('employee_id', $employee->getKey())
            ->whereNull('checked_out_at')
            ->orderBy('planned_at')
            ->get();

        return ApiResponse::success(['data' => $visits->map(fn (FieldVisit $v) => $this->payload($v))->all()]);
    }

    public function checkIn(Request $request, int $visit, FieldVisits $visits)
    {
        return $this->punch($request, $visit, fn (FieldVisit $v, ?array $position, array $data) => $visits->checkIn($v, $position));
    }

    public function checkOut(Request $request, int $visit, FieldVisits $visits)
    {
        return $this->punch($request, $visit, fn (FieldVisit $v, ?array $position, array $data) => $visits->checkOut($v, $position, $data['notes'] ?? null, $data['outcome'] ?? null));
    }

    private function punch(Request $request, int $id, callable $do)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $visit = FieldVisit::query()->where('employee_id', $employee->getKey())->find($id);

        if (! $visit) {
            return ApiResponse::error('Visit not found', 404);
        }

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'outcome' => ['nullable', 'string', 'in:'.implode(',', array_keys(FieldVisit::OUTCOMES))],
        ]);

        $position = isset($data['latitude'], $data['longitude']) ? $data : null;

        try {
            $visit = $do($visit, $position, $data);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->payload($visit->load('subject'))]);
    }

    private function employee(Request $request): ?Employee
    {
        return Employee::where('user_id', $request->user()->id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(FieldVisit $visit): array
    {
        return [
            'id' => $visit->getKey(),
            'subject' => $visit->subjectLabel(),
            'subject_type' => FieldVisit::subjectTypes()[$visit->subject_type] ?? $visit->subject_type,
            'planned_at' => $visit->planned_at->toIso8601String(),
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            'outcome' => $visit->outcome,
            'confirmed_at' => $visit->confirmed_at?->toIso8601String(),
            'map' => $visit->mapLink(),
        ];
    }
}
