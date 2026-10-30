<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clock-in that was turned away — docs/saas-launch-plan.md §14.10.
 *
 * Until now a refusal threw and vanished, and the wrong half of the story was kept. A
 * rep standing in the right place whose phone put them three hundred metres away has
 * a problem the company should be able to see; so does a rep who tries from home every
 * morning. The two look nothing alike on this list and identical in an exception log
 * nobody reads.
 *
 * No `UPDATED_AT`: a refusal happened once.
 */
class FieldVisitRefusal extends Model
{
    public const UPDATED_AT = null;

    /** Too far from the pin. */
    public const REASON_AWAY = 'away';

    /** The reading was too vague to say either way. */
    public const REASON_INACCURATE = 'inaccurate';

    /** Not enrolled to do visits at all. */
    public const REASON_NOT_ENROLLED = 'not_enrolled';

    /** No coordinates sent, where the company requires them. */
    public const REASON_NO_POSITION = 'no_position';

    public const REASONS = [
        self::REASON_AWAY => 'Too far from the place',
        self::REASON_INACCURATE => 'The reading was too vague',
        self::REASON_NOT_ENROLLED => 'Not enrolled for field visits',
        self::REASON_NO_POSITION => 'No position sent',
    ];

    protected $fillable = ['field_visit_id', 'employee_id', 'reason', 'latitude', 'longitude', 'accuracy_m', 'distance_m'];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'accuracy_m' => 'integer',
        'distance_m' => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(FieldVisit::class, 'field_visit_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /** Recorded whatever else happens: a log that can refuse to write is not a log. */
    public static function record(array $attributes): ?self
    {
        try {
            return static::create($attributes);
        } catch (\Throwable) {
            return null;
        }
    }
}
