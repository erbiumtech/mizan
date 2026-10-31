<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * How a designation's score is weighted — docs/saas-launch-plan.md §14.10.
 *
 * **The ledger records what happened; this says what it is worth to this job.** A
 * salesman's week should count volume more heavily than a draughtsman's, and the
 * ledger cannot know that: it knows a task was missed and that a missed task is worth
 * minus two.
 *
 * Per designation rather than per person, because a company setting it per person
 * would be setting it forty times and arguing about it forty times.
 *
 * **Quality and volume are shown beside the net, never folded into it.** Two people
 * on the same net can be opposite employees — one who does little carefully and one
 * who does a great deal badly — and a single number hides exactly the distinction a
 * manager is looking for.
 */
class ScorecardProfile extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    /** What a merit source counts as. The ledger's own sources, in two buckets. */
    public const QUALITY_SOURCES = [MeritEntry::SOURCE_REPORT, MeritEntry::SOURCE_BADGE];

    public const VOLUME_SOURCES = [MeritEntry::SOURCE_TASK, MeritEntry::SOURCE_LATE_MARK];

    /** The weights a profile may set, and what each one is about. */
    public const SOURCES = [
        MeritEntry::SOURCE_REPORT => 'Daily reports',
        MeritEntry::SOURCE_BADGE => 'Badges',
        MeritEntry::SOURCE_TASK => 'Recurring tasks',
        MeritEntry::SOURCE_LATE_MARK => 'Late marks',
    ];

    /** Unweighted. A profile that sets nothing counts everything once, as the ledger does. */
    public const DEFAULT_WEIGHT = 1.0;

    protected $fillable = ['designation', 'weights', 'is_active'];

    protected $casts = ['weights' => 'array', 'is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The weight for one source, or one. */
    public function weightFor(string $source): float
    {
        $weights = (array) ($this->weights ?? []);

        return array_key_exists($source, $weights) && is_numeric($weights[$source])
            ? (float) $weights[$source]
            : self::DEFAULT_WEIGHT;
    }

    /**
     * The profile for a designation, or nothing.
     *
     * Nothing is a perfectly good answer: a company with no profiles gets the ledger
     * unweighted, which is what it had before any of this existed.
     */
    public static function forDesignation(?string $designation): ?self
    {
        return blank($designation)
            ? null
            : static::query()->active()->where('designation', $designation)->first();
    }

    public static function bucketOf(string $source): string
    {
        return in_array($source, self::QUALITY_SOURCES, true) ? 'quality' : 'volume';
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationUniqueFields(): array
    {
        return ['designation' => self::suggestCopyOf('designation')];
    }
}
