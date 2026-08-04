<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A standing agreement to invoice somebody every month.
 */
class RecurringInvoice extends Model
{
    use Auditable;

    protected $fillable = [
        'contact_id', 'kind', 'description', 'memo', 'day_of_month', 'due_days',
        'tax_inclusive', 'starts_on', 'ends_on', 'is_active', 'notes',
    ];

    protected $casts = [
        'day_of_month' => 'integer',
        'due_days' => 'integer',
        'tax_inclusive' => 'boolean',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'kind' => Invoice::KIND_SALE,
        'day_of_month' => 1,
        'due_days' => 15,
        'tax_inclusive' => false,
        'is_active' => true,
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceLine::class)->orderBy('sort')->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Is this agreement running in the month starting $period?
     *
     * By month, not by day: one starting on the 15th bills for that month, and one
     * ending on the 3rd bills its last month in full. A monthly agreement is not
     * pro-rated here.
     */
    public function coversPeriod(Carbon $period): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $month = $period->copy()->startOfMonth();

        if ($this->starts_on->copy()->startOfMonth()->greaterThan($month)) {
            return false;
        }

        return $this->ends_on === null
            || $this->ends_on->copy()->startOfMonth()->greaterThanOrEqualTo($month);
    }

    /** The invoice date for a month: the agreed day, clamped to a short month. */
    public function invoiceDateFor(Carbon $period): Carbon
    {
        $month = $period->copy()->startOfMonth();

        return $month->copy()->day(min($this->day_of_month, $month->daysInMonth));
    }

    public function total(): float
    {
        return round($this->lines->sum(fn (RecurringInvoiceLine $line): float => $line->lineTotal()), 2);
    }
}
