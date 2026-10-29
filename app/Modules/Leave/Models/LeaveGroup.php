<?php

namespace App\Modules\Leave\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A bundle of leave types assigned to employees — docs/saas-launch-plan.md §14.4.
 * No group, and no default group, means every active type: today's behaviour.
 */
class LeaveGroup extends Model
{
    use Auditable;

    protected $fillable = ['name', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(function (self $group): void {
            if ($group->is_default) {
                static::query()->whereKeyNot($group->getKey())->update(['is_default' => false]);
            }
        });
    }

    public function types(): BelongsToMany
    {
        return $this->belongsToMany(LeaveType::class, 'leave_group_types');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** The types this person may take: their group's, the default group's, or every active type. */
    public static function typesFor(Employee $employee): Collection
    {
        $group = $employee->leave_group_id ? static::query()->find($employee->leave_group_id) : static::query()->where('is_default', true)->first();

        if ($group === null) {
            return LeaveType::query()->active()->orderBy('sort')->get();
        }

        return $group->types()->where('is_active', true)->orderBy('sort')->get();
    }
}
