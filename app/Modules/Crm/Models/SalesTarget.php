<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What somebody is expected to bring in, and over what period.
 *
 * Phase 7. Attainment is computed against won deals; **paying it is not automatic.** §3 is
 * explicit: a commission is a `pay_components` amount on a payslip that a human enters
 * after approval, because the first disputed deal would otherwise become a payroll
 * incident — the same reason §4.5 keeps performance ratings away from pay.
 */
class SalesTarget extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['period_start', 'period_end'];

    public const KIND_WON_VALUE = 'won_value';

    public const KIND_NEW_LEADS = 'new_leads';

    public const KIND_ACTIVITIES = 'activities';

    protected $fillable = [
        'employee_id', 'period_start', 'period_end', 'target_amount', 'currency_code', 'kind',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'target_amount' => 'decimal:2',
    ];

    protected $attributes = ['kind' => self::KIND_WON_VALUE];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeCovering(Builder $query, string|Carbon $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return $query->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date);
    }
}
