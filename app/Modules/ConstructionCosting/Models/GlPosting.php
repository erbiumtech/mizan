<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\JournalEntry;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One posting run — `docs/construction-management-plan.md` §4.1.
 *
 * **A summary journal per period per (GL account × cost type), and the batch link is what makes that safe.** §4.1 is
 * blunt about the alternative: "per-entry posting is not on the table — a hundred thousand entries a period would make
 * `journal_entry_lines` the largest table in the tenant and the general ledger unreadable." And equally blunt about the
 * condition: "a summary posting without that link is a number in the accounts nobody can explain, and should be treated
 * as a defect rather than a shortcut."
 *
 * So `entries()` exists and is a real query rather than a report: any line of the posted journal explodes into the cost
 * entries behind it through `construction_cost_entries.journal_entry_id`, which every contributing entry carries.
 *
 * **Not a `CostBatch`**, and the reason is that a batch *owns* the entries it created through `batch_id`. These entries
 * already belong to the labour run or the allocation that wrote them, and taking their `batch_id` would break the thing
 * §3.2 built it for — "a reversal that has to re-find its two hundred rows by predicate is a reversal that will one day
 * find a hundred and ninety-nine".
 *
 * **The totals are stored rather than recomputed**, for §3.4's reason about the period's control totals: an entry
 * reversed next month must not change what this run says it posted.
 */
class GlPosting extends Model
{
    use Auditable;

    protected $table = 'construction_gl_postings';

    protected $fillable = [
        'period_start', 'journal_entry_id', 'entry_count', 'total_amount', 'line_count',
        'posted_at', 'posted_by', 'reverses_gl_posting_id', 'reason', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'posted_at' => 'datetime',
        'entry_count' => 'integer',
        'line_count' => 'integer',
        'total_amount' => 'decimal:2',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversedPosting(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_gl_posting_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_gl_posting_id');
    }

    /**
     * **§4.1's explosion, as one query.**
     *
     * The condition under which summary posting is honest rather than a shortcut. A GL line of 1,847,320 on account
     * 5020 is answerable — here are the four hundred and six entries, their jobs, their cost codes and their unit rates
     * — and a summary posting that could not answer it would be a defect.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CostEntry::class, 'journal_entry_id', 'journal_entry_id');
    }

    public function isReversed(): bool
    {
        return static::query()->where('reverses_gl_posting_id', $this->getKey())->exists();
    }

    public function isReversal(): bool
    {
        return $this->reverses_gl_posting_id !== null;
    }

    public function scopeForPeriod(Builder $query, string $periodStart): Builder
    {
        return $query->whereDate('period_start', CostPeriod::startFor($periodStart)->toDateString());
    }

    /** Runs that put something in the books, as against the reversals that took it back out. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('reverses_gl_posting_id');
    }

    public function displayName(): string
    {
        return ($this->isReversal() ? 'Reversal of GL posting ' : 'GL posting ')
            .($this->isReversal() ? $this->reverses_gl_posting_id : $this->getKey())
            .' — '.$this->period_start->format('F Y');
    }
}
