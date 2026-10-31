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

        // The same shape the sync read hands back — AttendanceDay::toClient().
        return ApiResponse::success(['data' => $day->toClient()], $day->check_out_at ? 200 : 201);
    }
}
