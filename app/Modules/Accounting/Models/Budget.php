<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Traits\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the company (or the person) intends to earn and spend in a fiscal year.
 *
 * The plan is held as one row per account per month — see BudgetLine — and the
 * annual figure people actually type is spread across those months by
 * BudgetService. Storing the months rather than the year is what lets "how are
 * we doing so far" be answered honestly: comparing eleven months of spending
 * against a full year's budget is the standard way to make an overspend look
 * fine.
 */
class Budget extends Model
{
    use Auditable;

    protected $fillable = ['fiscal_year_id', 'name', 'notes', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * The first day of every month this budget covers, in order.
     *
     * Read from the fiscal year rather than assumed to be twelve: a company's
     * first year on the system is routinely a short one, and a budget that
     * invented four months it has no dates for would compare them against no
     * actuals at all and report the whole thing as underspent.
     *
     * @return array<int, CarbonImmutable>
     */
    public function months(): array
    {
        $year = $this->fiscalYear;

        if ($year?->start_date === null || $year->end_date === null) {
            return [];
        }

        $months = [];
        $cursor = CarbonImmutable::parse($year->start_date)->startOfMonth();
        $last = CarbonImmutable::parse($year->end_date)->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $months[] = $cursor;
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /** The planned total for one account across the whole year. */
    public function annualFor(int $accountId): float
    {
        return round((float) $this->lines()->where('account_id', $accountId)->sum('amount'), 2);
    }
}
