<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\OptionLists;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One entry in one admin-managed dropdown. See the migration for why the table exists,
 * and App\Support\OptionLists for which dropdowns are declared.
 */
class OptionValue extends Model
{
    use Auditable;

    protected $fillable = ['list', 'value', 'label', 'is_active', 'sort'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $option): void {
            // The stored value is decided once. An admin renaming "Cook" to "Chef" is
            // relabelling, not reclassifying: every employee row already reads "Cook",
            // and rewriting the value here would orphan all of them at once. New rows
            // take the label as their value, so nothing has to explain the difference
            // to whoever is typing.
            $option->value = $option->exists
                ? $option->getOriginal('value')
                : ((string) $option->value !== '' ? $option->value : $option->label);
        });

        static::saved(fn () => OptionLists::flush());
        static::deleted(fn () => OptionLists::flush());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
