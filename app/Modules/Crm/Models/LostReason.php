<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Why deals are lost.
 *
 * A table rather than free text because win/loss BY REASON is the report §8 says is worth
 * more than the forecast — and free text gives you "price", "Price" and "too expensive" as
 * three reasons with three rates, none of them right.
 */
class LostReason extends Model
{
    use Auditable;

    protected $fillable = ['name', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean', 'sort' => 'integer'];

    protected $attributes = ['is_active' => true, 'sort' => 0];

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'lost_reason_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
