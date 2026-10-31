<?php

namespace App\Modules\Advances\Models;

use App\Models\TenantModel as Model;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/** A kind of advance and its limits — docs/saas-launch-plan.md §14.3. */
class AdvanceType extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    protected $fillable = ['name', 'max_amount', 'max_instalments', 'interest_rate_pct', 'active_limit', 'is_active'];

    protected $casts = [
        'max_amount' => 'decimal:2',
        'max_instalments' => 'integer',
        'interest_rate_pct' => 'decimal:2',
        'active_limit' => 'integer',
        'is_active' => 'boolean',
    ];

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Simple interest on the amount, added to what is recovered. */
    public function interestOn(float $amount): float
    {
        return round($amount * (float) $this->interest_rate_pct / 100, 2);
    }

    /** @throws InvalidArgumentException when the request breaks a limit */
    public function check(float $amount, ?int $instalments, int $employeeId, ?int $excludingAdvanceId = null): void
    {
        if ($this->max_amount !== null && $amount > (float) $this->max_amount) {
            throw new InvalidArgumentException(sprintf('%s allows at most %s.', $this->name, number_format((float) $this->max_amount)));
        }

        if ($this->max_instalments !== null && $instalments !== null && $instalments > $this->max_instalments) {
            throw new InvalidArgumentException("{$this->name} allows at most {$this->max_instalments} instalments.");
        }

        if ($this->active_limit !== null) {
            $active = Advance::query()->where('employee_id', $employeeId)->where('advance_type_id', $this->getKey())
                ->where('status', Advance::STATUS_ACTIVE)->when($excludingAdvanceId, fn ($q) => $q->whereKeyNot($excludingAdvanceId))->count();

            if ($active >= $this->active_limit) {
                throw new InvalidArgumentException("{$this->name} allows {$this->active_limit} active advance(s) per person, and this person already has {$active}.");
            }
        }
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationUniqueFields(): array
    {
        return ['name' => self::suggestCopyOf('name')];
    }
}
