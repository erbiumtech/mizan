<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who does field visits — docs/saas-launch-plan.md §14.10.
 *
 * A row rather than a column on `employees`, because CRM may not add one to another
 * module's table, and because the list is short: a company of eighty has six people
 * on the road.
 *
 * **Enrolment is off until a company switches it on.** Until then anybody may be
 * named on a visit, which is how it has always worked; a company that has fifty
 * employees and six reps turns it on and stops the other forty-four appearing in the
 * picker.
 */
class FieldVisitStaff extends Model
{
    use Auditable;

    protected $table = 'field_visit_staff';

    protected $fillable = ['employee_id', 'enrolled_at', 'enrolled_by'];

    protected $casts = ['enrolled_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->enrolled_at ??= now();
            $row->enrolled_by ??= auth()->id();
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return array<int, int> the employee ids enrolled, or every active employee when enrolment is off */
    public static function allowedEmployeeIds(): array
    {
        if (! self::isRequired()) {
            return Employee::query()->where('is_active', true)->pluck('id')->all();
        }

        return static::query()->pluck('employee_id')->all();
    }

    public static function isRequired(): bool
    {
        return (bool) setting('crm.field_visits.enrolment_required', false);
    }

    public static function allows(?int $employeeId): bool
    {
        return $employeeId !== null && (! self::isRequired() || static::query()->where('employee_id', $employeeId)->exists());
    }
}
