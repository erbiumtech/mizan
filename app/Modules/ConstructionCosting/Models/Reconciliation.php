<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One reconciliation run — `docs/construction-management-plan.md` §4.3.
 *
 * **A run is a dated statement about two ledgers**, and it is kept rather than recomputed for §3.4's reason: "a
 * reconciliation computed later from live data cannot tell you what the figures were on the day somebody signed the
 * certificate."
 *
 * Three statuses and they are not a workflow. `balanced` is the difference being nil. `unbalanced` is a difference nobody
 * has spoken to. `accepted` is a difference somebody has put their name against **with a reason** — and §4.3 is
 * emphatic that accepting is not fixing: "a forced close never fudges the ledger. No plug entry, no balancing figure.
 * Both sides stay true and the difference stays visible in every later period until the cause is fixed."
 */
class Reconciliation extends Model
{
    use Auditable;

    public const STATUS_BALANCED = 'balanced';

    public const STATUS_UNBALANCED = 'unbalanced';

    public const STATUS_ACCEPTED = 'accepted';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_BALANCED => 'Balanced',
        self::STATUS_UNBALANCED => 'Unbalanced',
        self::STATUS_ACCEPTED => 'Difference accepted',
    ];

    /**
     * How close is close enough to call it balanced.
     *
     * One paisa, not zero, and §4.2 asks for rounding to be "isolated so it cannot be used to explain anything else" —
     * which is exactly what a tolerance this tight does. A hundred entries rounded to two decimals can differ from their
     * own sum by a paisa; a tolerance of a rupee would let a real error hide behind arithmetic.
     */
    public const TOLERANCE = 0.01;

    protected $table = 'construction_reconciliations';

    protected $fillable = [
        'period_start', 'run_at', 'run_by',
        'gl_cost', 'unallocated_gl_cost', 'pending_job_cost', 'expected_job_cost', 'job_cost', 'difference',
        'status', 'causes', 'accepted_at', 'accepted_by', 'accepted_reason', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'run_at' => 'datetime',
        'accepted_at' => 'datetime',
        'gl_cost' => 'decimal:2',
        'unallocated_gl_cost' => 'decimal:2',
        'pending_job_cost' => 'decimal:2',
        'expected_job_cost' => 'decimal:2',
        'job_cost' => 'decimal:2',
        'difference' => 'decimal:2',
        'causes' => 'array',
    ];

    public function isBalanced(): bool
    {
        return $this->status === self::STATUS_BALANCED;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * Whether this run blocks a period close — §4.3's second mechanism.
     *
     * An accepted difference does not block. That is the whole of the third mechanism: somebody holding
     * `ConstructionPeriodForceClose` may accept it with a stated reason, and the month goes on. What does not happen is
     * the ledger being adjusted to agree.
     */
    public function blocksClose(): bool
    {
        return $this->status === self::STATUS_UNBALANCED;
    }

    public function scopeForPeriod(Builder $query, string $periodStart): Builder
    {
        return $query->whereDate('period_start', CostPeriod::startFor($periodStart)->toDateString());
    }

    public function scopeUnbalanced(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNBALANCED);
    }

    /** The run that stands for a period: the most recent one. */
    public static function latestFor(string $periodStart): ?self
    {
        return static::query()->forPeriod($periodStart)->orderByDesc('run_at')->orderByDesc('id')->first();
    }

    /** @return array<int, array<string, mixed>> */
    public function causeRows(): array
    {
        return $this->causes ?? [];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function displayName(): string
    {
        return 'Reconciliation — '.$this->period_start->format('F Y').' ('.$this->statusLabel().')';
    }
}
