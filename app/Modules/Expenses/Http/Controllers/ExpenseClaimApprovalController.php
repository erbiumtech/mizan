<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\ExpenseClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * A manager's expense-claim approvals, from the phone — docs/desktop-mobile-plan.md A4/M3. Versioned
 * surface only.
 *
 * Same posture as the leave approvals: the list is what the caller's policy lets them decide, and the
 * decisions are the claim's own approve() and refuse(), which hold the not-your-own-claim rule, the
 * chain's next role, the accrual posting and the claimant's notification. The API adds nothing to them.
 */
class ExpenseClaimApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $pending = ExpenseClaim::query()
            ->pending()
            ->with(['employee'])
            ->orderBy('claimed_on')
            ->get()
            ->filter(fn (ExpenseClaim $claim): bool => $user->can('decide', $claim))
            ->values();

        return ApiResponse::success([
            'count' => $pending->count(),
            'data' => $pending->map(fn (ExpenseClaim $claim): array => $this->shape($claim))->all(),
        ]);
    }

    public function approve(Request $request, ExpenseClaim $expenseClaim): JsonResponse
    {
        abort_unless($request->user()->can('decide', $expenseClaim), 403, 'You may not decide this claim.');

        try {
            $expenseClaim->approve($request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($expenseClaim->refresh())]);
    }

    public function refuse(Request $request, ExpenseClaim $expenseClaim): JsonResponse
    {
        abort_unless($request->user()->can('decide', $expenseClaim), 403, 'You may not decide this claim.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $expenseClaim->refuse($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->shape($expenseClaim->refresh())]);
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
