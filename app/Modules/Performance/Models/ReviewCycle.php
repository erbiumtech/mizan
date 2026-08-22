<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewCycle extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['period_start', 'period_end', 'self_review_due_on', 'manager_review_due_on'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CALIBRATING = 'calibrating';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'name', 'period_start', 'period_end', 'status',
        'self_review_due_on', 'manager_review_due_on', 'calibration_notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'self_review_due_on' => 'date',
        'manager_review_due_on' => 'date',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'review_cycle_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class, 'review_cycle_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_CALIBRATING]);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
