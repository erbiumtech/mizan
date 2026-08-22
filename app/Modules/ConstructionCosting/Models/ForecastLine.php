<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One control account's cost to complete, in one forecast run — §3.5 and §14.
 *
 * `eac_method` records which of §14's three standard methods produced the estimate at completion, and **the report
 * shows it per line** — because "the forecast went up" and "somebody changed the method" are different facts and
 * only one of them is news.
 */
class ForecastLine extends Model
{
    use Auditable;

    /** Actual plus a cost to complete the surveyor typed. */
    public const EAC_MANUAL = 'manual_etc';

    /** Actual plus whatever budget remains — used where the variance so far is judged atypical. */
    public const EAC_REMAINING_BUDGET = 'remaining_budget';

    /** Budget divided by the cost performance index — used where the variance is judged typical and will continue. */
    public const EAC_CPI = 'cpi_based';

    protected $table = 'construction_forecast_lines';

    protected $fillable = [
        'forecast_run_id', 'job_id', 'wbs_node_id', 'cost_code_id',
        'cost_to_complete', 'eac_method',
        'actual_to_date', 'accrued_to_date', 'budget_at_completion', 'forecast_final_cost',
        'open_commitment', 'is_below_commitment', 'below_commitment_reason', 'notes',
    ];

    protected $casts = [
        'cost_to_complete' => 'decimal:2',
        'actual_to_date' => 'decimal:2',
        'accrued_to_date' => 'decimal:2',
        'budget_at_completion' => 'decimal:2',
        'forecast_final_cost' => 'decimal:2',
        'open_commitment' => 'decimal:2',
        'is_below_commitment' => 'boolean',
    ];

    protected $attributes = [
        'eac_method' => self::EAC_MANUAL,
        'cost_to_complete' => 0,
        'is_below_commitment' => false,
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    /** Variance at completion: budget less forecast. Negative is an overspend. */
    public function varianceAtCompletion(): float
    {
        return round((float) $this->budget_at_completion - (float) $this->forecast_final_cost, 2);
    }
}
