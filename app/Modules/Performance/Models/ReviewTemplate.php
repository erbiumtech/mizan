<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a review asks about — docs/saas-launch-plan.md §14.6: competencies with
 * weights that sum to 100, so a weighted rating means something.
 */
class ReviewTemplate extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    protected $fillable = ['name', 'description', 'is_default', 'is_active'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(function (self $template): void {
            if ($template->is_default) {
                static::query()->whereKeyNot($template->getKey())->update(['is_default' => false]);
            }
        });
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(ReviewCompetency::class)->orderBy('sort')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function totalWeight(): float
    {
        return round((float) $this->competencies()->sum('weight'), 2);
    }

    /** The sentence the form shows when the weights do not add up; null when they do. */
    public function weightProblem(): ?string
    {
        $total = $this->totalWeight();

        if ($this->competencies()->count() === 0) {
            return 'No competencies yet.';
        }

        return abs($total - 100) < 0.01 ? null : sprintf('The weights add up to %s, not 100.', rtrim(rtrim(number_format($total, 2), '0'), '.'));
    }

    public static function forCycle(): ?self
    {
        return static::query()->active()->where('is_default', true)->first() ?? static::query()->active()->orderBy('id')->first();
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationUniqueFields(): array
    {
        return ['name' => self::suggestCopyOf('name')];
    }

    public function replicationResets(): array
    {
        return ['is_default' => false];
    }

    public function replicationRelations(): array
    {
        return ['competencies'];
    }
}
