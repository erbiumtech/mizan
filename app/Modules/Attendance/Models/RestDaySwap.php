<?php

namespace App\Modules\Attendance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rest day worked and a working day rested, for one person — docs/saas-launch-plan.md §14.4.
 * WorkPatternResolver reads it, so attendance, leave and payroll all see the swapped week.
 */
class RestDaySwap extends Model
{
    use Auditable;

    protected $fillable = ['employee_id', 'work_on', 'rest_on', 'reason', 'created_by'];

    protected $casts = ['work_on' => 'date', 'rest_on' => 'date'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->created_by ??= auth()->id());
        $forget = function (): void {
            \App\Modules\Attendance\Services\WorkPatternResolver::forgetSwaps();
            app(\App\Modules\Attendance\Services\WorkPatternResolver::class)->flush();
        };
        static::saved($forget);
        static::deleted($forget);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
