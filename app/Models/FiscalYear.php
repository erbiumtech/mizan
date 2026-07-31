<?php

namespace App\Models;

use App\Models\TenantModel as Model;
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

    public function salarySlabs()
    {
        return $this->hasMany(SalarySlab::class);
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
