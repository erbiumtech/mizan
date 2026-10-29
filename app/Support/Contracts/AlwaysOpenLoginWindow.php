<?php

namespace App\Support\Contracts;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;

class AlwaysOpenLoginWindow implements LoginWindow
{
    public function isOpen(User $user, Company $company): bool
    {
        return true;
    }
}
