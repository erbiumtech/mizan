<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee asking HR for a letter — docs/saas-launch-plan.md §14.7. */
class LetterRequest extends Model
{
    use Auditable;

    /**
     * The state that means "waiting for someone to approve it": its list puts these rows first
     * (App\Filament\Support\PendingFirst). [column, values], or [column, null] for "while empty".
     */
    public const PENDING_APPROVAL = ['status', [self::STATUS_PENDING]];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_REFUSED = 'refused';

    protected $fillable = ['employee_id', 'kind', 'purpose', 'status', 'requested_by', 'decided_by', 'decided_at', 'refusal_reason'];

    protected $casts = ['decided_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->requested_by ??= auth()->id());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function kindLabel(): string
    {
        return LetterTemplate::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', (string) $this->kind));
    }
}
