<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\OptionValue;
use App\Support\OptionLists;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A designation as a row — docs/saas-launch-plan.md §14.2: level and pay grade.
 * The grade is a min–max band that warns, never blocks, when a package falls outside it.
 */
class Designation extends Model
{
    use Auditable;

    public const LIST = 'employees.designation';

    protected $fillable = ['name', 'code', 'level', 'pay_grade_min', 'pay_grade_max', 'is_active'];

    protected $casts = ['level' => 'integer', 'pay_grade_min' => 'decimal:2', 'pay_grade_max' => 'decimal:2', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn (self $row) => $row->syncOptionList());
        static::deleted(fn (self $row) => $row->forgetOption());
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation', 'name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasPayGrade(): bool
    {
        return $this->pay_grade_min !== null || $this->pay_grade_max !== null;
    }

    /** The sentence the package form shows, or null when the amount sits inside the band. */
    public function payGradeWarning(float $amount): ?string
    {
        if (! $this->hasPayGrade()) {
            return null;
        }

        if ($this->pay_grade_min !== null && $amount < (float) $this->pay_grade_min) {
            return sprintf('%s is below the %s grade (%s – %s).', number_format($amount), $this->name, number_format((float) $this->pay_grade_min), $this->pay_grade_max !== null ? number_format((float) $this->pay_grade_max) : 'no ceiling');
        }

        if ($this->pay_grade_max !== null && $amount > (float) $this->pay_grade_max) {
            return sprintf('%s is above the %s grade (%s – %s).', number_format($amount), $this->name, $this->pay_grade_min !== null ? number_format((float) $this->pay_grade_min) : 'no floor', number_format((float) $this->pay_grade_max));
        }

        return null;
    }

    public function syncOptionList(): void
    {
        $previous = $this->getOriginal('name');

        if ($previous !== null && $previous !== $this->name) {
            OptionValue::query()->where('list', static::LIST)->where('value', $previous)->delete();
            Employee::query()->where('designation', $previous)->update(['designation' => $this->name]);
        }

        OptionValue::query()->updateOrCreate(
            ['list' => static::LIST, 'value' => $this->name],
            ['label' => $this->name, 'is_active' => (bool) ($this->is_active ?? true), 'sort' => $this->level ?? $this->getKey()],
        );
        OptionLists::flush();
    }

    public function forgetOption(): void
    {
        OptionValue::query()->where('list', static::LIST)->where('value', $this->name)->delete();
        OptionLists::flush();
    }
}
