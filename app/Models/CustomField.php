<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-tenant custom field definition attached to a domain model type.
 */
class CustomField extends Model
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'boolean', 'select'];

    protected $fillable = [
        'model_type', 'code', 'name', 'type', 'options', 'is_required',
        'min', 'max', 'regex', 'help', 'placeholder', 'sort', 'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function scopeForModel(Builder $query, string $modelClass): Builder
    {
        return $query->where('model_type', $modelClass)->where('is_active', true)->orderBy('sort')->orderBy('id');
    }
}
