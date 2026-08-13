<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vacancy extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['opened_on', 'closed_on'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_FILLED = 'filled';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'code', 'title', 'department', 'designation', 'employment_type', 'openings',
        'status', 'hiring_manager_employee_id', 'salary_min', 'salary_max',
        'currency_code', 'description', 'opened_on', 'closed_on',
    ];

    protected $casts = [
        'openings' => 'integer',
        'salary_min' => 'decimal:2',
        'salary_max' => 'decimal:2',
        'opened_on' => 'date',
        'closed_on' => 'date',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'openings' => 1];

    /** Guarded: a company hiring its first person has no employee to name. */
    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hiring_manager_employee_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_ON_HOLD]);
    }

    /** How many of the openings are still unfilled. */
    public function remainingOpenings(): int
    {
        return max(0, $this->openings - $this->applications()->where('stage', Application::STAGE_HIRED)->count());
    }
}
