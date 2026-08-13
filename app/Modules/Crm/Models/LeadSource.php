<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where leads come from.
 *
 * A table rather than a free-text column because win/loss by source is the report
 * worth having, and "LinkedIn", "Linkedin" and "linked in" as three sources with
 * three win rates is not a report.
 */
class LeadSource extends Model
{
    use Auditable;

    protected $fillable = ['name', 'is_active', 'sort'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort' => 0,
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
