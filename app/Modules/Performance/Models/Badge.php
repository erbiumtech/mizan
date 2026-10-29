<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something worth recognising — docs/saas-launch-plan.md §8.4. Awarded by
 * hand with a reason, or (plan §8.9) by a rule; every award is a merit credit.
 */
class Badge extends Model
{
    use Auditable;

    protected $fillable = ['name', 'description', 'icon', 'points', 'rule', 'is_active'];

    protected $casts = [
        'points' => 'integer',
        'rule' => 'array',
        'is_active' => 'boolean',
    ];

    public function awards(): HasMany
    {
        return $this->hasMany(EmployeeBadge::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
