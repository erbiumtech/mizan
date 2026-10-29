<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\OptionValue;
use App\Support\OptionLists;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A department as a row — docs/saas-launch-plan.md §14.2: parent, code and head.
 * `employees.department` still carries the name, so every reader is untouched;
 * the option list it came from is kept in step so `options()` answers the same.
 */
class Department extends Model
{
    use Auditable;

    public const LIST = 'employees.department';

    protected $fillable = ['name', 'code', 'parent_id', 'head_employee_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn (self $row) => $row->syncOptionList());
        static::deleted(fn (self $row) => $row->forgetOption());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department', 'name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function syncOptionList(): void
    {
        $previous = $this->getOriginal('name');

        if ($previous !== null && $previous !== $this->name) {
            OptionValue::query()->where('list', static::LIST)->where('value', $previous)->delete();
            // The rename follows the people who hold it, as the option list could not.
            Employee::query()->where('department', $previous)->update(['department' => $this->name]);
        }

        OptionValue::query()->updateOrCreate(
            ['list' => static::LIST, 'value' => $this->name],
            ['label' => $this->name, 'is_active' => (bool) ($this->is_active ?? true), 'sort' => $this->getKey()],
        );
        OptionLists::flush();
    }

    public function forgetOption(): void
    {
        OptionValue::query()->where('list', static::LIST)->where('value', $this->name)->delete();
        OptionLists::flush();
    }
}
