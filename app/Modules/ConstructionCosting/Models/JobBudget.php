<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One version of a job's budget — `docs/construction-management-plan.md` §3.5.
 *
 * **Versioned because earned value needs a baseline that does not move and a cost report needs a budget that
 * does.** Without the split, cost variance is measured against a number somebody edited last Tuesday and
 * schedule variance is meaningless. Superseded versions stay for comparison, which is the same reasoning
 * `budgets.is_active` already gives.
 *
 * `is_baseline` and `is_current` are **separate flags answering different questions**, and conflating them is the
 * mistake this class exists to prevent. The baseline is what earned value measures against and must not move once
 * work has been claimed against it; the current budget is what the cost report compares actuals to and moves
 * every time a variation is approved. On a job with no variations they are the same version — the moment one is
 * approved they diverge.
 */
class JobBudget extends Model
{
    use Auditable;

    public const KIND_ESTIMATE = 'estimate';

    public const KIND_ORIGINAL = 'original_budget';

    public const KIND_REVISION = 'revision';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $table = 'construction_budget_versions';

    protected $fillable = [
        'job_id', 'version_no', 'name', 'kind', 'status', 'is_baseline', 'is_current',
        'effective_from', 'approved_at', 'approved_by', 'notes',
    ];

    protected $casts = [
        'is_baseline' => 'boolean',
        'is_current' => 'boolean',
        'effective_from' => 'date',
        'approved_at' => 'datetime',
        'version_no' => 'integer',
    ];

    protected $attributes = [
        'kind' => self::KIND_ESTIMATE,
        'status' => self::STATUS_DRAFT,
        'is_baseline' => false,
        'is_current' => false,
    ];

    /**
     * Exactly one baseline and one current version per job, enforced here.
     *
     * The shape is `FiscalYear::booted()`'s, and so is the reason: everything asks the same way —
     * `where is_current, first()` — so a second one does not read as an error anywhere. It reads as the wrong
     * budget, which is worse.
     *
     * The re-assert on the row itself is deliberate and is the subtle half of that precedent: a model loaded
     * while it was current, and stood down since by another version being made current, is **not dirty** when it
     * is set current again — Eloquent writes nothing, and the next line then stands every other version down.
     * That would leave the job with no current budget at all.
     */
    protected static function booted(): void
    {
        static::saved(function (self $version): void {
            foreach (['is_current', 'is_baseline'] as $flag) {
                if (! $version->{$flag}) {
                    continue;
                }

                static::withoutEvents(function () use ($version, $flag): void {
                    static::whereKey($version->getKey())->update([$flag => true]);

                    static::where('job_id', $version->job_id)
                        ->whereKeyNot($version->getKey())
                        ->where($flag, true)
                        ->update([$flag => false]);
                });
            }
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JobBudgetLine::class, 'budget_version_id');
    }

    /** What the cost report compares actuals against. */
    public static function currentFor(Job $job): ?self
    {
        return static::query()->where('job_id', $job->getKey())->where('is_current', true)->first();
    }

    /** What earned value measures against, and what must not move. */
    public static function baselineFor(Job $job): ?self
    {
        return static::query()->where('job_id', $job->getKey())->where('is_baseline', true)->first();
    }

    /**
     * The current versions across a job **and every job under it** — §1.2's roll-up.
     *
     * A development's report has to add its towers' budgets up, because budgets are held on the job that was
     * tendered and the reporting is done at whatever level somebody asks. Reading only the root's own version is
     * the failure this exists to prevent: cost rolls up the tree while budget does not, so a development that is
     * exactly on budget reports a variance equal to its entire spend and looks like a disaster.
     *
     * @return Collection<int, int>
     */
    public static function currentIdsForTree(Job $root): Collection
    {
        return static::idsForTree($root, 'is_current');
    }

    /**
     * The baselines across a job and every job under it — the same roll-up, for earned value.
     *
     * @return Collection<int, int>
     */
    public static function baselineIdsForTree(Job $root): Collection
    {
        return static::idsForTree($root, 'is_baseline');
    }

    /** @return Collection<int, int> */
    private static function idsForTree(Job $root, string $flag): Collection
    {
        return static::query()
            ->whereIn('job_id', Job::query()->inSubtree($root)->select('id'))
            ->where($flag, true)
            ->pluck('id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Budget at completion: the whole of this version.
     *
     * Named for the earned-value term rather than "total", because BAC is what every formula in §14 refers to and
     * a reader coming from that section should find the word they are looking for.
     */
    public function budgetAtCompletion(): float
    {
        return (float) $this->lines()->sum('amount');
    }

    /**
     * Whether this version is time-phased at all.
     *
     * §14: an unphased budget cannot produce a schedule variance, and the report must say so rather than showing
     * a zero that means "no data". This is the question it asks.
     */
    public function isTimePhased(): bool
    {
        return $this->lines()->whereNotNull('period_start')->exists();
    }
}
