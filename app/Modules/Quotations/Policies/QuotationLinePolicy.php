<?php

namespace App\Modules\Quotations\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Quotations\Models\QuotationLine;

/** A quote and its lines are one document, so one group covers both. */
class QuotationLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('QuotationView');
    }

    public function view(User $user, QuotationLine $record): bool
    {
        return $user->hasPermissionTo('QuotationView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('QuotationCreate');
    }

    public function update(User $user, QuotationLine $record): bool
    {
        return $user->hasPermissionTo('QuotationUpdate');
    }

    public function delete(User $user, QuotationLine $record): bool
    {
        return $user->hasPermissionTo('QuotationDelete');
    }
}
