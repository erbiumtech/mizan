<?php

namespace App\Support\Contracts;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;

/**
 * Whether somebody may still reach a company's panel — docs/saas-launch-plan.md §14.5.
 *
 * Core asks; Lifecycle answers, because the fact that closes the window — a
 * separation's last working day and the grace after it — is Lifecycle's record.
 * Without Lifecycle the window is always open, which is how it behaved before.
 */
interface LoginWindow
{
    public function isOpen(User $user, Company $company): bool;
}
