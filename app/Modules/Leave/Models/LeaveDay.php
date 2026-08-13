<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One calendar day a request consumed.
 *
 * Deliberately not Auditable: these rows are generated in bulk from an approval
 * that is itself audited, and an audit entry per day of a fortnight's leave would
 * bury the decision that matters under fourteen that do not.
 */
class LeaveDay extends Model
{
    use StoresPlainDates;

    /** Stored as a plain date: the month split and the unique key both compare it as one. */
    protected array $plainDates = ['date'];

    public const PORTION_FULL = 1.0;

    public const PORTION_HALF = 0.5;

    protected $fillable = ['leave_request_id', 'date', 'portion', 'is_paid', 'settled_payslip_id'];

    protected $casts = [
        'date' => 'date',
        'portion' => 'decimal:1',
        'is_paid' => 'boolean',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    /**
     * Days falling inside a calendar month, which is how a request spanning a
     * month boundary reaches two payslips.
     */
    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('date', $year)->whereMonth('date', $month);
    }

    /**
     * Days no payslip has counted yet.
     *
     * The resting state. A day stays unsettled until a payslip picks it up, which is what
     * lets leave approved for a month already signed off carry forward to the next open
     * one rather than being lost or counted every month for ever.
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereNull('settled_payslip_id');
    }

    public function isSettled(): bool
    {
        return $this->settled_payslip_id !== null;
    }

    /**
     * Days belonging to approved requests only.
     *
     * Every consumer wants this — a pending request has consumed nothing, and a
     * cancelled one has given its days back. The join is here rather than repeated
     * at each call site because forgetting it silently overstates what somebody has
     * taken, which is the one error a balance cannot show as a discrepancy.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereHas('request', fn (Builder $request) => $request->approved());
    }
}
