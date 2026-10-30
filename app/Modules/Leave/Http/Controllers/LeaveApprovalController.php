<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalChain;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * A manager's leave approvals, from the phone — docs/desktop-mobile-plan.md A4/M3. Versioned surface only.
 *
 * Nothing here decides anything itself. The list is the pending requests the caller may decide, asked of
 * the same policy that shows or hides the button in the panel; approving and refusing go through
 * LeaveRequestService, so the API cannot approve what the form would refuse — the self-approval rule, the
 * chain's next role, a refusal without a reason — and the claimant is told the same way.
 */
class LeaveApprovalController extends Controller
{
    public function index(Request $request, LeaveApprovalChain $chain): JsonResponse
    {
        $user = $request->user();

        $pending = LeaveRequest::query()
            ->pending()
            ->with(['employee', 'leaveType'])
            ->orderBy('from_date')
            ->get()
            ->filter(fn (LeaveRequest $leave): bool => $user->can('decide', $leave))
            ->values();

        return ApiResponse::success([
            'count' => $pending->count(),
            'data' => $pending->map(fn (LeaveRequest $leave): array => $this->shape($leave, $chain))->all(),
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $service, LeaveApprovalChain $chain): JsonResponse
    {
        abort_unless($request->user()->can('decide', $leaveRequest), 403, 'You may not decide this request.');

        try {
            $leaveRequest = $service->approve($leaveRequest, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($leaveRequest->refresh(), $chain)]);
    }

    public function refuse(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $service, LeaveApprovalChain $chain): JsonResponse
    {
        abort_unless($request->user()->can('decide', $leaveRequest), 403, 'You may not decide this request.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $leaveRequest = $service->refuse($leaveRequest, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($leaveRequest->refresh(), $chain)]);
    }

    /** @return array<string, mixed> */
    private function shape(LeaveRequest $leave, LeaveApprovalChain $chain): array
    {
        return [
            'id' => $leave->getKey(),
            'employee' => [
                'id' => $leave->employee_id,
                'name' => $leave->employee?->name,
                'code' => $leave->employee?->employee_id,
            ],
            'leave_type' => $leave->leaveType?->label,
            'from_date' => $leave->from_date?->toDateString(),
            'to_date' => $leave->to_date?->toDateString(),
            'days' => (float) $leave->days,
            'is_half_day' => (bool) $leave->is_half_day,
            'reason' => $leave->reason,
            'status' => $leave->status,
            'awaiting' => $leave->isPending() ? $chain->awaitingLabel($leave) : null,
            'refusal_reason' => $leave->refusal_reason,
            'submitted_at' => $leave->created_at?->toIso8601String(),
        ];
    }
}
