<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * One cost month — `docs/construction-management-plan.md` §3.4.
 *
 * **A different clock from the fiscal year**, which is §3.1's third reason this ledger exists: a job runs three
 * years and reports monthly against a valuation date, while the general ledger closes on a fiscal calendar.
 * Forcing job cost onto the fiscal clock makes the monthly cost report a fiscal-year artefact, which is not what
 * a certificate is measured on.
 *
 * `period_start` is a **date, not a 1–12 index**. A month index cannot say which of three years' Marches it
 * means, and the job outlives the fiscal year.
 */
class CostPeriod extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_RECONCILED = 'reconciled';

    protected $table = 'construction_cost_periods';

    protected $fillable = [
        'period_start', 'period_end', 'status', 'closed_at', 'closed_by', 'reconciled_at',
        'gl_control_total', 'jc_control_total', 'difference', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'closed_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'gl_control_total' => 'decimal:2',
        'jc_control_total' => 'decimal:2',
        'difference' => 'decimal:2',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN];

    /** The first day of the month a date falls in — the canonical form of a posting period. */
    public static function startFor(string|Carbon $date): Carbon
    {
        return Carbon::parse($date)->startOfMonth();
    }

    /**
     * The period beginning on a date — **the only way this table should be looked up by date.**
     *
     * One scope because the alternative bit four times in one sitting. `period_start` is `date`-cast, so Laravel
     * writes `2026-09-01 00:00:00`; an equality match on `'2026-09-01'` therefore finds nothing on SQLite while
     * MySQL, which truncates a `DATE` column, would match. That difference is the worst kind: `forDate()`
     * inserted duplicates, and `CostEntry::isEditable()` could not find a closed period at all — so an entry in
     * a signed-off month looked editable, which is the whole rule §3.3 exists to enforce.
     *
     * Anything comparing a date to this column goes through here.
     */
    public function scopeStarting(Builder $query, string|Carbon $date): Builder
    {
        return $query->whereDate('period_start', self::startFor($date)->toDateString());
    }

    /**
     * The period a date belongs to, creating it if this is the first cost in that month.
     *
     * Created on demand rather than seeded a year ahead: a job's first cost is what says the month exists, and
     * a table of empty future periods is a list of things that look closable.
     *
     * Looked up through `starting()` rather than `firstOrCreate`, for the reason that scope documents.
     */
    public static function forDate(string|Carbon $date): self
    {
        $start = self::startFor($date);

        return static::query()->starting($start)->first()
            ?? static::create([
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
            ]);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Closed or reconciled: either way no new cost lands in it. */
    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * The open period a late cost should land in: the earliest one still open.
     *
     * §3.4's rule — a supplier invoice dated into a closed month goes into the open period with `incurred_on`
     * preserved and `is_late_for_period` set, because reopening a signed-off month invalidates the WIP
     * snapshot, the client certificate and the GL summary that all depended on that period's total.
     */
    public static function earliestOpen(): ?self
    {
        return static::query()->open()->orderBy('period_start')->first();
    }

    public function label(): string
    {
        return $this->period_start->format('F Y');
    }
}
