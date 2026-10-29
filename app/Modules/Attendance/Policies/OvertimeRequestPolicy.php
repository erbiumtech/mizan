<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Core\Models\User;

/** Asking is every employee's; deciding is the regularization approver's — the same split as corrections. */
class OvertimeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationView');
    }

    public function view(User $user, OvertimeRequest $request): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationCreate');
    }

    public function update(User $user, OvertimeRequest $request): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationApprove') && $request->isPending();
    }

    public function delete(User $user, OvertimeRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function decide(User $user, OvertimeRequest $request): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationApprove') && $request->isPending();
    }
}
