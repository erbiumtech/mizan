<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A record of which drawings went to whom, and whether they said they got them — §15.
 *
 * The acknowledgement is the point of the whole document: **a transmittal nobody acknowledged is a drawing
 * somebody will later say they never received**, and on a claim that argument is worth money.
 */
class Transmittal extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    protected $table = 'construction_transmittals';

    protected $fillable = [
        'job_id', 'reference', 'subject', 'notes', 'purpose', 'issued_on', 'issued_by', 'status',
    ];

    protected $casts = [
        'issued_on' => 'date',
    ];

    protected $attributes = [
        'purpose' => 'for_information',
        'status' => self::STATUS_DRAFT,
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransmittalItem::class, 'transmittal_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TransmittalRecipient::class, 'transmittal_id');
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    /**
     * Recipients who were notified and have not acknowledged — the chase list.
     *
     * Computed rather than stored: a stored "outstanding" flag drifts the moment somebody acknowledges, and
     * this is the one figure a document controller looks at every morning.
     */
    public function outstandingAcknowledgements(): \Illuminate\Support\Collection
    {
        return $this->recipients
            ->filter(fn (TransmittalRecipient $r): bool => $r->notified_at !== null && $r->acknowledged_at === null)
            ->values();
    }
}
