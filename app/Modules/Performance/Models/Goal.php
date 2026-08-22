<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Goal extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['due_on'];

    public const STATUS_OPEN = 'open';

    public const STATUS_ACHIEVED = 'achieved';

    public const STATUS_MISSED = 'missed';

    public const STATUS_DROPPED = 'dropped';

    protected $fillable = [
        'employee_id', 'review_cycle_id', 'title', 'description', 'metric',
        'target', 'actual', 'weight', 'status', 'due_on',
    ];

    protected $casts = ['weight' => 'decimal:2', 'due_on' => 'date'];

    protected $attributes = ['status' => self::STATUS_OPEN];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
