<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

/**
 * The IBFT bank list: a code, a name, a short code, and whether it is still in use.
 *
 * In Core rather than in Accounting, for the reason FiscalYear and Holiday are: three modules ask
 * "which bank is this" — Employees for an employee's salary account, Accounting for the company's own
 * accounts and its beneficiaries, Invoicing for a contact's — and none of them owns the answer. Filed
 * under Accounting it made `Employee::bank()` an `employees → accounting` edge and `Bank::employees()`
 * an `accounting → employees` edge in return, and that pair sat on seven of the cycles that stop a
 * module being extractable. See docs/module-packaging-plan.md §7.
 *
 * **This model has no relations, and that is the point.** Core is the one module every other module may
 * depend on, which it can only stay if it depends on nothing itself. A convenience relation here — even
 * a read-only one — would put Core in a cycle with whatever it pointed at.
 *
 * Where it lives and what gates it are deliberately different questions. The list is always readable,
 * because an employee's bank has to resolve whether or not the company licenses Accounting; the screen
 * that maintains it is `Accounting\Filament\Resources\Banks\BankResource`, behind Accounting's own
 * `Bank` permission group. The alias is unchanged (`App\Models\Bank`), so nothing stored moves.
 */
class Bank extends Model
{
    use Auditable;

    protected $fillable = ['bank_code', 'bank_name', 'bank_short_code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
