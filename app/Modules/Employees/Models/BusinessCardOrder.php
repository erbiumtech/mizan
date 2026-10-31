<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody asking for business cards, and the queue they wait in —
 * docs/saas-launch-plan.md §14.10.
 *
 * The same shape as a letter request, and for the same reason: asking is not a
 * permission, deciding is. What this adds over an email to the office manager is that
 * the artwork comes out of the record, so the cards say what the employee record says
 * rather than what somebody retyped.
 */
class BusinessCardOrder extends Model
{
    use Auditable;

    /**
     * The state that means "waiting for someone to approve it": its list puts these rows first
     * (App\Filament\Support\PendingFirst). [column, values], or [column, null] for "while empty".
     */
    public const PENDING_APPROVAL = ['status', [self::STATUS_REQUESTED]];

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_REFUSED = 'refused';

    public const STATUSES = [
        self::STATUS_REQUESTED => 'Requested',
        self::STATUS_APPROVED => 'Approved, to print',
        self::STATUS_PRINTED => 'Printed',
        self::STATUS_COLLECTED => 'Collected',
        self::STATUS_REFUSED => 'Refused',
    ];

    /** The states the print queue is about. */
    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_APPROVED];

    protected $fillable = [
        'employee_id', 'quantity', 'status', 'title_override', 'phone_override', 'note',
        'requested_by', 'decided_by', 'decided_at', 'refusal_reason',
    ];

    protected $casts = ['quantity' => 'integer', 'decided_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_REQUESTED, 'quantity' => 100];

    protected static function booted(): void
    {
        static::creating(fn (self $order) => $order->requested_by ??= auth()->id());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** What the card should say, which is the record unless somebody overrode it. */
    public function title(): string
    {
        return (string) ($this->title_override ?: $this->employee?->designation ?: '');
    }

    public function phone(): string
    {
        return (string) ($this->phone_override ?: $this->employee?->phone ?: '');
    }
}
