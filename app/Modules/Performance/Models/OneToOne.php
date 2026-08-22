<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded one-to-one.
 *
 * `private_notes` is manager-and-above only, and worth naming explicitly because
 * EmployeeAccess grants a manager their whole DOWNLINE: without a rule of its own, an
 * employee who happens to manage nobody would still be inside their own manager's scope
 * for reads, and could read the notes about themselves.
 *
 * docs/hrms-plan.md §7.2.
 */
class OneToOne extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['met_on'];

    protected $table = 'one_to_ones';

    protected $fillable = ['employee_id', 'manager_employee_id', 'met_on', 'notes', 'private_notes'];

    protected $casts = ['met_on' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    /**
     * Whether this user may read the private notes.
     *
     * Never the person it is about, whatever else they hold — that is the one case this
     * method exists for. The manager who wrote it and anybody with the privileged
     * permission may.
     */
    public function privateNotesVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->employee?->user_id === $user->getKey()) {
            return false;
        }

        // `can()`, not `hasPermissionTo()`. Outside a policy, hasPermissionTo() THROWS
        // for a permission name the company has not seeded — and this is called from a
        // form field and a table column, so the throw takes the whole page down. That is
        // the trap docs/new-module-checklist.md §6 warns about, and it was found by
        // FilamentResourcesSmokeTest. can() answers false instead, and still respects the
        // super-admin Gate::before.
        return $user->can('ReviewPrivateNotes');
    }
}
