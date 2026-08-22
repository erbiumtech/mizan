<?php

namespace App\Events;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user account has been created from the panel, and belongs to the company being served.
 *
 * Fired so that modules can attach whatever a new person needs. Today that is Employees, which creates the
 * linked employee record — behaviour that lived in `Core\Filament\Resources\Users\Pages\CreateUser` and
 * made Core call `Employee::create()`. See docs/module-packaging-plan.md §9.
 *
 * **Only fired when the user belongs to the current tenant**, which is the condition that page already
 * checked and the reason it is worth stating here: a super admin creating an account for another company
 * from this page must not get an employee row in *this* company's database. The check stays with the page,
 * because the page is what knows which tenant it is serving; a listener that had to re-derive it would be
 * the same decision made twice.
 */
class UserCreated
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
