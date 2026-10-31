<?php

namespace App\Modules\Expenses\Dashboard;

use App\Modules\Core\Models\User;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Services\ExpenseClaimApprovalQueue;
use App\Support\CompanyCurrency;
use App\Support\DashboardApprovals;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Expense claims waiting for a decision, on the Material dashboard's "Waiting for you" card.
 *
 * Listed by ExpenseClaimApprovalQueue — the queue the `/v1` endpoints use — and decided by the claim's own
 * approve() and refuse(), which hold the rules, the accrual posting and the claimant's notification. An
 * Administrator is never offered their own claim: the queue asks for that explicitly, past Gate::before.
 */
final class ExpensesDashboard
{
    public static function register(): void
    {
        DashboardApprovals::register(
            'expenses',
            visible: fn (User $user): bool => modules()->enabled('expenses') && $user->can(ExpenseClaim::APPROVE_PERMISSION),
            pending: fn (User $user): array => app(ExpenseClaimApprovalQueue::class)->pendingFor($user)
                ->map(fn (ExpenseClaim $claim): array => [
                    'id' => (int) $claim->getKey(),
                    'initials' => DashboardApprovals::initials($claim->employee?->name),
                    'title' => __('Expense claim · :amount', [
                        'amount' => CompanyCurrency::code().' '.number_format((float) $claim->amount, 2),
                    ]),
                    'meta' => __(':name · :what', [
                        'name' => $claim->employee?->name ?? '—',
                        'what' => Str::limit((string) $claim->description, 48),
                    ]),
                ])
                ->all(),
            approve: function (User $user, int $id): void {
                self::find($user, $id)->approve($user);
            },
            refuse: function (User $user, int $id, string $reason): void {
                self::find($user, $id)->refuse($user, $reason);
            },
            url: fn (): ?string => rescue(fn (): string => ExpenseClaimResource::getUrl('index'), null, false),
            sort: 20,
        );
    }

    private static function find(User $user, int $id): ExpenseClaim
    {
        return app(ExpenseClaimApprovalQueue::class)->findFor($user, $id)
            ?? throw new InvalidArgumentException(__('This item is no longer waiting for you.'));
    }
}
