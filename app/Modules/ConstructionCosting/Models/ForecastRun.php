<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One month's forecast of the final cost — §3.5.
 *
 * **A forecast is a snapshot, not a mutable row.** The whole value of forecasting is comparing last month's
 * estimate at completion with this month's — *we said 4.2 in March and 4.9 in April; what moved* — and a single
 * mutable row destroys the only report that makes forecasting worth doing.
 *
 * So each run is its own set of lines with its own snapshotted actuals, and the budget version it was made
 * against is recorded — which is what lets a comparison between two runs say whether the *budget* moved or the
 * *forecast* did. Those are different facts and only one of them is news.
 */
class ForecastRun extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    protected $table = 'construction_forecast_runs';

    protected $fillable = [
        'job_id', 'period_start', 'name', 'status', 'budget_version_id',
        'prepared_by', 'issued_at', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'issued_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ForecastLine::class, 'forecast_run_id');
    }

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(JobBudget::class, 'budget_version_id');
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    /** The forecast final cost this run arrived at — the headline figure a board paper quotes. */
    public function forecastFinalCost(): float
    {
        return (float) $this->lines()->sum('forecast_final_cost');
    }

    /**
     * Lines forecast below their open commitment — §3.5's exception report.
     *
     * A cost code with a purchase order worth more than its remaining budget is already overspent, and a forecast
     * saying otherwise is forecasting money that has already been promised away. Allowed with a reason, and
     * listed here so somebody reads the reasons.
     */
    public function linesBelowCommitment(): HasMany
    {
        return $this->lines()->where('is_below_commitment', true);
    }
}
