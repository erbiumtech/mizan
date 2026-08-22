<?php

namespace App\Modules\Leave\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual correction to an entitlement.
 *
 * A row rather than a column, because two adjustments in one year must both
 * survive: a goodwill grant in March and a correction in August cannot share two
 * columns without the second erasing the first and taking its reason with it.
 */
class LeaveAdjustment extends Model
{
    use Auditable;

    protected $fillable = ['leave_entitlement_id', 'days', 'reason', 'made_by', 'made_at'];

    protected $casts = [
        'days' => 'decimal:1',
        'made_at' => 'datetime',
    ];

    /**
     * Stamp who and when, so the caller cannot forget to.
     *
     * "Who gave me these three days, and when" is the first question a disputed
     * balance opens with. Leaving it to each call site means the one path that
     * forgets produces exactly the unexplainable number this table exists to
     * prevent.
     */
    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            $adjustment->made_at ??= now();
            $adjustment->made_by ??= auth()->id();
        });
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlement::class, 'leave_entitlement_id');
    }

    /** Soft reference: users are a landlord table, so this is not a constrained key. */
    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by');
    }
}
