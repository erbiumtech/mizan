<?php

namespace App\Modules\Campaigns\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who a campaign goes to.
 *
 * The definition is resolved **at send time**, so a segment means "everybody who matches now"
 * rather than a frozen list that silently goes stale. A saved list would be the more obvious
 * implementation and the wrong one: a campaign sent next month to last month's list is a
 * campaign that misses everybody who joined since.
 */
class Segment extends Model
{
    use Auditable;

    protected $fillable = ['name', 'definition', 'is_active'];

    protected $casts = ['definition' => 'array', 'is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The filters, with sane defaults.
     *
     * @return array{include_leads: bool, include_contacts: bool, lead_source_id: ?int, city: ?string}
     */
    public function filters(): array
    {
        $definition = (array) $this->definition;

        return [
            'include_leads' => (bool) ($definition['include_leads'] ?? true),
            'include_contacts' => (bool) ($definition['include_contacts'] ?? false),
            'lead_source_id' => $definition['lead_source_id'] ?? null,
            'city' => $definition['city'] ?? null,
        ];
    }
}
