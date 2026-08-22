<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Services\HolidayCalendar;
use App\Traits\Auditable;

/**
 * One day the company does not work.
 *
 * Deliberately thin: this table knows which dates are holidays and nothing else.
 * Whether a *weekend* is a working day comes from work patterns, which belong to
 * attendance — asking this model would mean answering with a guess.
 */
class Holiday extends Model
{
    use Auditable;

    protected $fillable = ['date', 'name', 'is_recurring', 'notes'];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];

    /**
     * Keep the calendar's per-request cache honest.
     *
     * HolidayCalendar reads the whole table once and answers from memory, which
     * is the point of it — the leave-day generator calls it once per calendar day
     * of a request. Without this, adding a holiday and then generating leave in
     * the same request reads the calendar as it was before the write, and the
     * generator is precisely where that would happen.
     */
    protected static function booted(): void
    {
        $flush = fn () => app(HolidayCalendar::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }
}
