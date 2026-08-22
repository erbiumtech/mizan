<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reusable onboarding or exit checklist. */
class ChecklistTemplate extends Model
{
    use Auditable;

    public const KIND_ONBOARDING = 'onboarding';

    public const KIND_EXIT = 'exit';

    protected $fillable = ['kind', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['kind' => self::KIND_ONBOARDING, 'is_active' => true];

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }
}
