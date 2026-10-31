<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Rules\Rule;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something worth recognising — docs/saas-launch-plan.md §8.4. Awarded by
 * hand with a reason, or (plan §8.9) by a rule; every award is a merit credit.
 */
class Badge extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    protected $fillable = ['name', 'description', 'icon', 'points', 'rule', 'is_active'];

    protected $casts = [
        'points' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Sanitised against the registries on the way in and out — docs/saas-launch-plan.md §8.9. */
    protected function rule(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?array => ($rule = Rule::from(is_string($value) ? json_decode($value, true) : $value))->isEmpty() ? null : $rule->toArray(),
            set: fn (mixed $value): ?string => ($rule = Rule::from($value))->isEmpty() ? null : json_encode($rule->toArray()),
        );
    }

    public function hasRule(): bool
    {
        return $this->rule !== null;
    }

    public function awards(): HasMany
    {
        return $this->hasMany(EmployeeBadge::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationUniqueFields(): array
    {
        return ['name' => self::suggestCopyOf('name')];
    }
}
