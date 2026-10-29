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

    /** Closed for everyone; offered but not closed; closed for some — docs/saas-launch-plan.md §14.4. */
    public const KIND_PUBLIC = 'public';

    public const KIND_OPTIONAL = 'optional';

    public const KIND_RESTRICTED = 'restricted';

    public const KINDS = [self::KIND_PUBLIC => 'Public — the company is closed', self::KIND_OPTIONAL => 'Optional — offered, not closed', self::KIND_RESTRICTED => 'Restricted — closed for some'];

    protected $fillable = ['date', 'name', 'kind', 'location_id', 'is_recurring', 'notes'];

    /** Only a public holiday closes the company: the calendar counts no working day lost to an optional one. */
    public function closes(): bool
    {
        return ($this->kind ?? self::KIND_PUBLIC) === self::KIND_PUBLIC;
    }

    /** The site this holiday is local to; null is company-wide — plan §7.9. */
    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function appliesTo(?int $locationId): bool
    {
        return $this->location_id === null || (int) $this->location_id === (int) $locationId;
    }

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
