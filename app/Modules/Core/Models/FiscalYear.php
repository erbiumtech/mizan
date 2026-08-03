<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Modules\Payroll\Models\SalarySlab;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

class FiscalYear extends Model
{
    use Auditable;

    protected $fillable = ['name', 'start_date', 'end_date', 'is_active', 'closed_at', 'closed_by'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'closed_at' => 'datetime',
    ];

    /**
     * One active year at a time, enforced.
     *
     * Everything that asks for the current year asks the same way — `where
     * is_active, first()` — so a second active year does not read as an error
     * anywhere. It reads as the wrong year: whichever has the lower id wins, and
     * on the company this was found on that was a year containing not one of its
     * entries. Activating a year now stands the others down.
     */
    protected static function booted(): void
    {
        static::saved(function (self $year): void {
            if (! $year->is_active) {
                return;
            }

            // Asserted, not assumed. A model loaded while it was active, and stood
            // down in the database since by another year being activated, is not
            // dirty when it is activated again — Eloquent writes nothing, and the
            // row stays false while the next line stands every other year down.
            // That leaves no active year at all.
            static::whereKey($year->getKey())->update(['is_active' => true]);

            static::whereKeyNot($year->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);
        });
    }

    public function salarySlabs()
    {
        return $this->hasMany(SalarySlab::class);
    }

    /**
     * The one active year, or none.
     *
     * Worth going through rather than repeating the query: it is the single place
     * that decides what "current" means.
     */
    public static function current(): ?self
    {
        return static::where('is_active', true)->orderByDesc('start_date')->first();
    }

    /** A closed year's ledger is frozen: nothing may post into it. */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('closed_at');
    }

    /**
     * The year whose date range contains $date, if any.
     *
     * Used to decide whether a journal entry falls inside a closed period.
     * Years with no dates recorded cannot contain anything, so they are skipped.
     */
    public static function containing(string $date): ?self
    {
        return static::whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }
}
