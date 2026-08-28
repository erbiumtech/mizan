<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row of the withholding table: a section of the Ordinance and what it costs — Phase 4.
 *
 * See the migration for why this is its own table rather than rows in `tax_rates`. What matters here is
 * that a section is *dated*: a Finance Act changes a rate, and the deduction made last September has to
 * stay the rate that applied last September. So `effective_from`/`effective_to` bound each row and a new
 * rate is a new row, which is also why `on()` takes a date rather than reading `is_active` alone.
 */
class WithholdingSection extends Model
{
    use Auditable;

    /**
     * Where a deduction posts when the section does not name an account.
     *
     * 2100 in the seeded chart, whose own description is "withholding tax deducted from salaries, payable
     * to FBR" — §153 is the same tax withheld from a different party, remitted on the same challan. A
     * company that files the two separately gives the section its own account, which is what the column is
     * for; nothing is lost either way, because the §165 statement is assembled from
     * `withholding_deductions` and never from the ledger.
     */
    public const DEFAULT_ACCOUNT_CODE = '2100';

    protected $fillable = [
        'section', 'label', 'rate_filer', 'rate_non_filer',
        'per_payment_threshold', 'annual_threshold',
        'account_id', 'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'rate_filer' => 'decimal:3',
        'rate_non_filer' => 'decimal:3',
        'per_payment_threshold' => 'decimal:2',
        'annual_threshold' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function deductions()
    {
        return $this->hasMany(WithholdingDeduction::class);
    }

    /** In force on a date: active, started, and not yet superseded. */
    public function scopeOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date));
    }

    /** The rate this party pays, which is the whole reason for the two columns. */
    public function rateFor(bool $isFiler): float
    {
        return round((float) ($isFiler ? $this->rate_filer : $this->rate_non_filer), 3);
    }

    /** `153(1)(b) — Services` , for a picker and for the statement. */
    public function describe(): string
    {
        return trim("{$this->section} — {$this->label}");
    }
}
