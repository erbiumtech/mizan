<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalChain;
use App\Modules\Leave\Services\LeaveApprovalQueue;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * A manager's leave approvals, from the phone — docs/desktop-mobile-plan.md A4/M3. Versioned surface only.
 *
 * Nothing here decides anything itself. The list is the pending requests the caller may decide, within
 * the reporting line the panel's resource shows them (EmployeeAccess) and under the same eligibility the
 * service will enforce — the self-approval rule and the chain's next role — asked explicitly rather than
 * only through the policy, because an Administrator passes every policy through Gate::before and would
 * otherwise be shown requests the service then refuses. Approving and refusing go through
 * LeaveRequestService, so the API cannot approve what the form would refuse, and the claimant is told the
 * same way.
 *
 * Records are loaded here, after the company middleware has run, rather than route-bound: SubstituteBindings
 * sits ahead of `api.company` in the stack, and a tenant model bound before the tenant is current is bound
 * against the wrong connection.
 */
class LeaveApprovalController extends Controller
{
    public function index(Request $request, LeaveApprovalChain $chain, LeaveApprovalQueue $queue): JsonResponse
    {
        // The queue the dashboard's "Waiting for you" card lists from too, so the two cannot disagree.
        $pending = $queue->pendingFor($request->user());

        return ApiResponse::success([
            'count' => $pending->count(),
            'data' => $pending->map(fn (LeaveRequest $leave): array => $this->shape($leave, $chain))->all(),
        ]);
    }

    public function approve(Request $request, int $leaveRequest, LeaveRequestService $service, LeaveApprovalChain $chain): JsonResponse
    {
        $leave = $this->find($request->user(), $leaveRequest);

        try {
            $leave = $service->approve($leave, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($leave->refresh(), $chain)]);
    }

    public function refuse(Request $request, int $leaveRequest, LeaveRequestService $service, LeaveApprovalChain $chain): JsonResponse
    {
        $leave = $this->find($request->user(), $leaveRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $leave = $service->refuse($leave, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($leave->refresh(), $chain)]);
    }

    /** One request, within the boundary, that the caller may decide — 404 outside it, 403 within it but not theirs to decide. */
    private function find(User $user, int $id): LeaveRequest
    {
        $leave = app(LeaveApprovalQueue::class)->decidable($user)->whereKey($id)->first();

        abort_unless($leave, 404, 'No such pending leave request.');
        abort_unless($user->can('decide', $leave), 403, 'You may not decide this request.');

        return $leave;
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
