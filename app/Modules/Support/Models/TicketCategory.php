<?php

namespace App\Modules\Support\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of ticket, and what the company has committed to for it.
 *
 * The SLA minutes are nullable: a category with no commitment is not a breach waiting to
 * happen, and a zero would read as "instantly overdue".
 */
class TicketCategory extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'default_priority', 'sla_response_minutes', 'sla_resolution_minutes', 'is_active',
    ];

    protected $casts = [
        'sla_response_minutes' => 'integer',
        'sla_resolution_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = ['default_priority' => 'normal', 'is_active' => true];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
