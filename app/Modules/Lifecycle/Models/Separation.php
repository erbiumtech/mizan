<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leaving as a record — docs/saas-launch-plan.md §14.5: type, notice, last working day,
 * and the clearance list the settlement reads. Closed after the last day; the login
 * follows a configurable number of days later.
 */
class Separation extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const TYPES = ['resignation' => 'Resignation', 'termination' => 'Termination', 'retirement' => 'Retirement', 'end_of_contract' => 'End of contract', 'death' => 'Death in service'];

    /** The employee status each type leaves behind. */
    public const STATUS_AFTER = ['resignation' => Employee::STATUS_RESIGNED, 'termination' => Employee::STATUS_TERMINATED, 'retirement' => Employee::STATUS_RETIRED, 'end_of_contract' => Employee::STATUS_TERMINATED, 'death' => Employee::STATUS_TERMINATED];

    protected $fillable = ['employee_id', 'type', 'notice_on', 'last_working_day', 'reason', 'status', 'closed_on', 'created_by'];

    protected $casts = ['notice_on' => 'date', 'last_working_day' => 'date', 'closed_on' => 'date'];

    protected $attributes = ['status' => self::STATUS_OPEN];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->created_by ??= auth()->id());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClearanceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isCleared(): bool
    {
        return ! $this->items()->whereNull('cleared_at')->whereNull('waived_at')->exists();
    }

    /** @return array{clear: int, total: int} */
    public function clearance(): array
    {
        $items = $this->items()->get();

        return ['clear' => $items->filter(fn (ClearanceItem $i): bool => $i->isClear())->count(), 'total' => $items->count()];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
