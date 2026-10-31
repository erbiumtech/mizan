<?php

namespace App\Modules\Leave\Dashboard;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Filament\Pages\LeaveCalendar;
use App\Modules\Leave\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalQueue;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Support\DashboardApprovals;
use App\Support\DashboardSummaries;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * What Leave puts on the Material dashboard: who is off today, and the requests waiting for a decision.
 *
 * Registered rather than read by Core, so the dashboard names no module. The approvals go through
 * LeaveApprovalQueue and LeaveRequestService — the queue the `/v1` endpoints list from and the service the
 * panel decides through — so the dashboard offers exactly what those would accept, no more.
 */
final class LeaveDashboard
{
    public static function register(): void
    {
        DashboardSummaries::register(
            'leave.on-leave-today',
            visible: fn (): bool => modules()->enabled('leave') && (bool) auth()->user()?->can('LeaveRequestView'),
            value: function (): array {
                $today = Carbon::today()->toDateString();

                $count = LeaveRequest::query()
                    ->approved()
                    ->overlapping($today, $today)
                    ->distinct()
                    ->count('employee_id');

                return [
                    'label' => __('On leave today'),
                    'value' => (string) $count,
                    'link' => __('See calendar'),
                    'url' => rescue(fn (): string => LeaveCalendar::getUrl(), null, false),
                    'tone' => 'secondary',
                ];
            },
            sort: 20,
        );

        DashboardApprovals::register(
            'leave',
            visible: fn (User $user): bool => modules()->enabled('leave') && $user->can(LeaveRequest::APPROVE_PERMISSION),
            pending: fn (User $user): array => app(LeaveApprovalQueue::class)->pendingFor($user)
                ->map(fn (LeaveRequest $leave): array => [
                    'id' => (int) $leave->getKey(),
                    'initials' => DashboardApprovals::initials($leave->employee?->name),
                    'title' => __(':type · :days days', [
                        'type' => $leave->leaveType?->label ?? __('Leave'),
                        'days' => rtrim(rtrim(number_format((float) $leave->days, 1), '0'), '.'),
                    ]),
                    'meta' => __(':name · :from to :to', [
                        'name' => $leave->employee?->name ?? '—',
                        'from' => $leave->from_date?->format('d M'),
                        'to' => $leave->to_date?->format('d M'),
                    ]),
                ])
                ->all(),
            approve: function (User $user, int $id): void {
                app(LeaveRequestService::class)->approve(self::find($user, $id), $user);
            },
            refuse: function (User $user, int $id, string $reason): void {
                app(LeaveRequestService::class)->refuse(self::find($user, $id), $user, $reason);
            },
            url: fn (): ?string => rescue(fn (): string => LeaveRequestResource::getUrl('index'), null, false),
            sort: 10,
        );
    }

    private static function find(User $user, int $id): LeaveRequest
    {
        return app(LeaveApprovalQueue::class)->findFor($user, $id)
            ?? throw new InvalidArgumentException(__('This item is no longer waiting for you.'));
    }
}
