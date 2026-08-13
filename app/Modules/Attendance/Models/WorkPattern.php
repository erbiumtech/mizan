<?php

namespace App\Modules\Attendance\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Which days of the week a company works, and how long a working day is.
 *
 * The table `leave.weekend_days` was a stopgap for. A pattern rather than columns on
 * `employees` because companies here run more than one — a factory floor on six days
 * and an office on five is the ordinary case.
 */
class WorkPattern extends Model
{
    use Auditable;

    protected $fillable = ['name', 'is_default', 'week_start', 'notes'];

    protected $casts = [
        'is_default' => 'boolean',
        'week_start' => 'integer',
    ];

    protected $attributes = [
        'is_default' => false,
        'week_start' => 1,
    ];

    /**
     * At most one default, enforced here because it is not a portable column
     * constraint and its failure is silent: with two defaults, which pattern a new
     * employee falls under depends on insertion order.
     */
    protected static function booted(): void
    {
        static::saved(function (self $pattern): void {
            if ($pattern->is_default) {
                static::query()->whereKeyNot($pattern->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkPatternDay::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkPattern::class);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('id')->first();
    }

    /** Whether a given ISO-8601 weekday (1 = Mon) is worked under this pattern. */
    public function worksOn(int $weekday): bool
    {
        return (bool) $this->days->firstWhere('weekday', $weekday)?->is_working;
    }

    /**
     * How long the working day is on a given weekday, or null when it is not worked.
     *
     * Null rather than 0 deliberately: phase 3a divides by this to derive an hourly
     * rate, and dividing by a zero that meant "not a working day" would produce an
     * infinite rate rather than an error somebody notices.
     */
    public function expectedHoursOn(int $weekday): ?float
    {
        $day = $this->days->firstWhere('weekday', $weekday);

        return $day && $day->is_working && $day->expected_hours !== null
            ? (float) $day->expected_hours
            : null;
    }

    /** Working days per week, which the monthly contracted-days figure builds on. */
    public function workingDaysPerWeek(): int
    {
        return $this->days->where('is_working', true)->count();
    }
}
