<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Services\ExpenseClaimApprovalChain;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * A manager's expense-claim approvals, from the phone — docs/desktop-mobile-plan.md A4/M3. Versioned
 * surface only.
 *
 * Same posture as the leave approvals: the list is what the caller's policy lets them decide, within the
 * reporting line ExpenseClaimResource shows them (EmployeeAccess) and under the eligibility the model will
 * enforce — not their own claim, and the chain's next role — asked explicitly because an Administrator's
 * Gate::before bypass would otherwise list claims approve() then refuses. The decisions are the claim's own
 * approve() and refuse(), which hold those rules, the accrual posting and the claimant's notification; the
 * API adds nothing to them. Records are loaded after the company middleware, not route-bound, for the reason
 * LeaveApprovalController gives.
 */
class ExpenseClaimApprovalController extends Controller
{
    public function index(Request $request, ExpenseClaimApprovalChain $chain): JsonResponse
    {
        $pending = $this->decidable($request->user())
            ->orderBy('claimed_on')
            ->get()
            ->filter(fn (ExpenseClaim $claim): bool => $this->mayDecide($request->user(), $claim, $chain))
            ->values();

        return ApiResponse::success([
            'count' => $pending->count(),
            'data' => $pending->map(fn (ExpenseClaim $claim): array => $this->shape($claim))->all(),
        ]);
    }

    public function approve(Request $request, int $expenseClaim): JsonResponse
    {
        $claim = $this->find($request->user(), $expenseClaim);

        try {
            $claim->approve($request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            // The panel answers both the same way: the rule that refused, or the ledger that could not book
            // the accrual (no expense account to post to), as a message for the person to act on.
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($claim->refresh())]);
    }

    public function refuse(Request $request, int $expenseClaim): JsonResponse
    {
        $claim = $this->find($request->user(), $expenseClaim);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $claim->refuse($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($claim->refresh())]);
    }

    /** Pending claims within the caller's reporting line — the same boundary ExpenseClaimResource draws. */
    private function decidable(User $user): Builder
    {
        $query = ExpenseClaim::query()->pending()->with(['employee']);

        return app(EmployeeAccess::class)->scopeAccessibleEmployees($query, $user, 'employee_id');
    }

    private function find(User $user, int $id): ExpenseClaim
    {
        $claim = $this->decidable($user)->whereKey($id)->first();

        abort_unless($claim, 404, 'No such pending expense claim.');
        abort_unless($user->can('decide', $claim), 403, 'You may not decide this claim.');

        return $claim;
    }

    /** The policy, plus the two checks approve() holds that an Administrator's policy bypass would skip. */
    private function mayDecide(User $user, ExpenseClaim $claim, ExpenseClaimApprovalChain $chain): bool
    {
        return $user->can('decide', $claim)
            && (int) $claim->submitted_by !== (int) $user->getKey()
            && $chain->userMayTakeNextStep($user, $claim);
    }

    /** @return array<string, mixed> */
    private function shape(ExpenseClaim $claim): array
    {
        return [
            'id' => $claim->getKey(),
            'employee' => [
                'id' => $claim->employee_id,
                'name' => $claim->employee?->name,
                'code' => $claim->employee?->employee_id,
            ],
            'claimed_on' => $claim->claimed_on?->toDateString(),
            'description' => $claim->description,
            'amount' => (float) $claim->amount,
            'status' => $claim->status,
            'awaiting' => $claim->isPending() ? $claim->awaitingLabel() : null,
            'refusal_reason' => $claim->refusal_reason,
            'has_receipt' => filled($claim->receipt_path),
            'submitted_at' => ($claim->submitted_at ?? $claim->created_at)?->toIso8601String(),
        ];
    }
}
