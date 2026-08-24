<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photograph of an incident — `docs/construction-management-plan.md` §17.3.
 *
 * A file on a row, not a container in the ISO 19650 register — the same decision §16.1 argued for the diary's photographs
 * and §16.4 for a punch item's, and for the same reason: the register exists so somebody can find the current issue of a
 * drawing, and incident photographs belong to the incident's own record.
 */
class IncidentPhoto extends Model
{
    protected $table = 'construction_incident_photos';

    protected $fillable = [
        'incident_id', 'caption', 'file_path', 'file_name', 'file_size', 'file_mime', 'taken_at', 'taken_by',
    ];

    protected $casts = [
        'taken_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $photo): void {
            $photo->taken_by ??= auth()->id();
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function displayName(): string
    {
        return $this->caption;
    }
}
