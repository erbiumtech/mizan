<?php

namespace App\Modules\PersonalFinance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bracket of one Pakistani tax schedule, for one tax year.
 *
 * `min_amount` is the *exceeding* threshold and `fixed_tax` is the cumulative
 * tax of every bracket below, so the bracket computes
 * `fixed_tax + percentage% x (income - min_amount)`. Copied from payroll's
 * salary_slabs because that representation is proven; kept in its own table
 * because TaxCalculatorService queries salary_slabs on fiscal_year_id alone and
 * would pick up the wrong regime's rows.
 */
class TaxSchedule extends Model
{
    public const REGIME_SALARIED = 'salaried';

    public const REGIME_BUSINESS = 'business';

    public const REGIME_RENTAL = 'rental';

    public const REGIME_CAPITAL_GAINS = 'capital_gains';

    public const REGIMES = [
        self::REGIME_SALARIED => 'Salaried',
        self::REGIME_BUSINESS => 'Business / self-employed',
        self::REGIME_RENTAL => 'Rental / property income',
        self::REGIME_CAPITAL_GAINS => 'Capital gains',
    ];

    protected $fillable = [
        'fiscal_year_id',
        'regime',
        'min_amount',
        'max_amount',
        'fixed_tax',
        'percentage',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'fixed_tax' => 'decimal:2',
        'percentage' => 'decimal:2',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isTopBracket(): bool
    {
        return $this->max_amount === null;
    }

    /** How this bracket reads on screen, e.g. "Over 600,000 up to 1,200,000 — 1%". */
    public function label(): string
    {
        $from = number_format((float) $this->min_amount);

        $range = $this->isTopBracket()
            ? "Over {$from}"
            : "Over {$from} up to ".number_format((float) $this->max_amount);

        $rate = rtrim(rtrim(number_format((float) $this->percentage, 2), '0'), '.');

        return "{$range} — {$rate}%";
    }
}
