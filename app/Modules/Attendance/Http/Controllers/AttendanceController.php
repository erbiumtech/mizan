<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Employees\Models\Employee;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The phone's clock-in — docs/saas-launch-plan.md §7.1. Same stack as
 * /my-leave-requests: the caller's own employee record, or nothing.
 */
class AttendanceController extends Controller
{
    public function clock(Request $request, AttendanceRecorder $recorder)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0'],
        ]);

        $position = isset($data['latitude'], $data['longitude']) ? $data : null;

        try {
            $day = $recorder->clock($employee, $position);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->payload($day)], $day->check_out_at ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function payload(AttendanceDay $day): array
    {
        return [
            'date' => $day->date->toDateString(),
            'status' => $day->status,
            'check_in_at' => $day->check_in_at?->toIso8601String(),
            'check_out_at' => $day->check_out_at?->toIso8601String(),
            'worked_minutes' => $day->worked_minutes,
            'late_minutes' => $day->late_minutes,
            'overtime_minutes' => $day->overtime_minutes,
            'latitude' => $day->latitude !== null ? (float) $day->latitude : null,
            'longitude' => $day->longitude !== null ? (float) $day->longitude : null,
            'accuracy_m' => $day->accuracy_m,
            'source' => $day->source,
        ];
    }
}
