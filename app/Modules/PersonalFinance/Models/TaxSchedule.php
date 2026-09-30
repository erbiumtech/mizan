<?php

namespace App\Modules\PersonalFinance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Support\TaxRegimes;
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
    // Aliases onto the shared list. Accounting needs the same names to offer
    // the setting on an income account, and neither module should depend on the
    // other, so App\Support\TaxRegimes owns them.
    public const REGIME_SALARIED = TaxRegimes::SALARIED;

    public const REGIME_BUSINESS = TaxRegimes::BUSINESS;

    public const REGIME_RENTAL = TaxRegimes::RENTAL;

    public const REGIME_CAPITAL_GAINS = TaxRegimes::CAPITAL_GAINS;

    public const REGIME_EXPORT_SERVICES = TaxRegimes::EXPORT_SERVICES;

    public const REGIMES = TaxRegimes::ALL;

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

    /**
     * The year a tax screen should open on: the active year when its rates are
     * seeded, otherwise the most recent year that has any.
     *
     * Not simply FiscalYear::current(): rates are seeded per year, and the
     * active year is routinely the one whose Finance Act has not been enacted
     * yet — so defaulting to it greets everybody with "no brackets for this
     * year" instead of a figure. Picking a rate-less year stays possible; it
     * just has to be a choice somebody made rather than where the screen dumps
     * them. Lives here because the question is entirely about which years have
     * schedules; TaxEstimate and the return pack both open on its answer.
     */
    public static function defaultYearId(): ?int
    {
        $current = FiscalYear::current();

        if ($current && static::where('fiscal_year_id', $current->id)->exists()) {
            return $current->id;
        }

        return FiscalYear::query()
            ->whereIn('id', static::select('fiscal_year_id'))
            ->orderByDesc('start_date')
            ->value('id') ?? $current?->id;
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
