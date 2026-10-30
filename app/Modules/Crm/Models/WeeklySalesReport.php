<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rep's week, typed in — docs/saas-launch-plan.md §14.10.
 *
 * **Only where CRM does not already know it.** A company with the pipeline has every
 * one of these figures as a consequence of working the week; a company without it has
 * a sales team and no pipeline, and the choice is a short form each Friday or no
 * report at all.
 *
 * Five numbers and a sentence, deliberately. A form with twenty fields is a form
 * people stop filling in, and a weekly report nobody fills in is worse than none
 * because the gaps read as nothing happening.
 */
class WeeklySalesReport extends Model
{
    use Auditable;

    protected $fillable = ['employee_id', 'week_starting', 'visits', 'calls', 'quotes', 'orders', 'order_value', 'note'];

    protected $casts = [
        'week_starting' => 'date',
        'visits' => 'integer',
        'calls' => 'integer',
        'quotes' => 'integer',
        'orders' => 'integer',
        'order_value' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeForWeek(Builder $query, string|Carbon $monday): Builder
    {
        return $query->whereDate('week_starting', self::weekStarting($monday));
    }

    /** The Monday of whatever week a date falls in, so two people cannot mean different weeks. */
    public static function weekStarting(string|Carbon|null $date = null): string
    {
        return Carbon::parse($date ?? now())->startOfWeek(Carbon::MONDAY)->toDateString();
    }
}
