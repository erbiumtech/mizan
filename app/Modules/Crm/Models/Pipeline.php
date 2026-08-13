<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales process. Most companies have one; a company selling two different things has two.
 */
class Pipeline extends Model
{
    use Auditable;

    protected $fillable = ['name', 'is_default', 'is_active'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = ['is_default' => false, 'is_active' => true];

    /** At most one default, for the same reason work patterns have one. */
    protected static function booted(): void
    {
        static::saved(function (self $pipeline): void {
            if ($pipeline->is_default) {
                static::query()->whereKeyNot($pipeline->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('id')->first();
    }

    /** The stage a new deal starts in: the first by sort that is not terminal. */
    public function firstStage(): ?PipelineStage
    {
        return $this->stages()->where('is_won', false)->where('is_lost', false)->first();
    }

    public function wonStage(): ?PipelineStage
    {
        return $this->stages()->where('is_won', true)->first();
    }

    public function lostStage(): ?PipelineStage
    {
        return $this->stages()->where('is_lost', true)->first();
    }
}
