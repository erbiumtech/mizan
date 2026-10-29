<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One badge, earned once, by one person — docs/saas-launch-plan.md §8.4. */
class EmployeeBadge extends Model
{
    protected $fillable = ['badge_id', 'employee_id', 'awarded_on', 'awarded_by', 'reason', 'merit_entry_id'];

    protected $casts = ['awarded_on' => 'date'];

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
