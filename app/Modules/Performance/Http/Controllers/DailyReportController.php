<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Services\DailyReports;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The phone's daily report — docs/saas-launch-plan.md §8.3. The caller's own, or nothing. */
class DailyReportController extends Controller
{
    public function index(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $rows = DailyReport::query()
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('report_date')
            ->limit(30)
            ->get()
            ->map(fn (DailyReport $r): array => $this->payload($r));

        return ApiResponse::success(['count' => $rows->count(), 'data' => $rows]);
    }

    public function store(Request $request, DailyReports $reports)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $data = $request->validate([
            'report_date' => ['nullable', 'date', 'before_or_equal:today'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $report = $reports->submit($employee, $data['report_date'] ?? now()->toDateString(), $data['body'], $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->payload($report)], 201);
    }

    /** The verdict stays the manager's until a decision — the employee's payload says "filed". */
    private function payload(DailyReport $r): array
    {
        return [
            'id' => $r->getKey(),
            'report_date' => $r->report_date->toDateString(),
            'body' => $r->body,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'status' => $r->isDecided() ? $r->manager_decision : 'filed',
            'points' => $r->isDecided() ? (int) ($r->meritEntry?->points ?? 0) : null,
            'manager_note' => $r->isDecided() ? $r->manager_note : null,
        ];
    }
}
