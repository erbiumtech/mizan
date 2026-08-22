<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['applied_on'];

    public const STAGE_APPLIED = 'applied';

    public const STAGE_SCREENING = 'screening';

    public const STAGE_INTERVIEW = 'interview';

    public const STAGE_OFFER = 'offer';

    public const STAGE_HIRED = 'hired';

    public const STAGE_REJECTED = 'rejected';

    public const STAGE_WITHDRAWN = 'withdrawn';

    /** @var array<int, string> */
    public const OPEN_STAGES = [
        self::STAGE_APPLIED, self::STAGE_SCREENING, self::STAGE_INTERVIEW, self::STAGE_OFFER,
    ];

    protected $fillable = [
        'vacancy_id', 'applicant_id', 'stage', 'applied_on',
        'rejected_reason', 'rejected_at', 'rating', 'employee_id',
    ];

    protected $casts = [
        'applied_on' => 'date',
        'rejected_at' => 'datetime',
        'rating' => 'integer',
    ];

    protected $attributes = ['stage' => self::STAGE_APPLIED];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class)->orderBy('round');
    }

    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class);
    }

    /** Guarded: set by the hire conversion, and the trail from vacancy to payroll. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('stage', self::OPEN_STAGES);
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    public function isHired(): bool
    {
        return $this->stage === self::STAGE_HIRED;
    }
}
