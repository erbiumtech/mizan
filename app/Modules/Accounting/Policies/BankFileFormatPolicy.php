<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\BankFileFormat;
use App\Modules\Core\Models\User;

class BankFileFormatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BankFileFormatView');
    }

    public function view(User $user, BankFileFormat $format): bool
    {
        return $user->hasPermissionTo('BankFileFormatView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BankFileFormatUpdate');
    }

    public function update(User $user, BankFileFormat $format): bool
    {
        return $user->hasPermissionTo('BankFileFormatUpdate');
    }

    public function delete(User $user, BankFileFormat $format): bool
    {
        return $user->hasPermissionTo('BankFileFormatUpdate') && $format->isTemplate();
    }
}
