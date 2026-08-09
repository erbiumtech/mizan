<?php

namespace App\Modules\PersonalFinance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The section 4AB surcharge for one regime in one tax year.
 *
 * Two things about it are easy to get wrong and are worth stating plainly:
 *
 *  - it is a percentage **of the tax**, not of the income. 9% surcharge on a
 *    liability of 3,000,000 is 270,000, not 9% of the income;
 *  - it applies when *taxable income* passes the threshold, so what decides
 *    whether it applies and what it is charged on are different figures.
 *
 * No row means no surcharge. That is how the Finance Act 2026 withdrawing it for
 * salaried individuals is expressed — by not seeding a 2026-2027 salaried row —
 * rather than as a special case in the calculator.
 */
class TaxSurcharge extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'regime',
        'threshold',
        'percentage',
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'percentage' => 'decimal:2',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * The surcharge on a given tax bill, or zero when it does not bite.
     *
     * Not named on() — Eloquent already has a static Model::on() for choosing a
     * connection, and overriding it non-statically is a fatal error.
     */
    public function amountOn(float $taxableIncome, float $tax): float
    {
        if ($taxableIncome <= (float) $this->threshold) {
            return 0.0;
        }

        return round($tax * (float) $this->percentage / 100, 2);
    }
}
