<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Support\Geo;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where a client actually is — docs/saas-launch-plan.md §14.10.
 *
 * A contact carries an address, not a point, and `FieldVisit` says so: a distance to
 * an address is a geocoder and a dependency. This is the other answer — somebody
 * standing at the place drops the pin once, and every visit after it is judged
 * against that.
 *
 * Kept on the morph the visit already uses rather than on the contact, for two
 * reasons: Invoicing owns contacts and CRM may not add a column to them, and a lead
 * or an opportunity needs the same thing.
 */
class FieldVisitPlace extends Model
{
    use Auditable;

    /** The default radius. A shop front is twenty metres; a factory yard is two hundred. */
    public const DEFAULT_RADIUS_M = 150;

    protected $fillable = ['subject_type', 'subject_id', 'latitude', 'longitude', 'radius_m', 'note', 'pinned_by'];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'radius_m' => 'integer',
    ];

    protected $attributes = ['radius_m' => self::DEFAULT_RADIUS_M];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function distanceTo(float $latitude, float $longitude): float
    {
        return Geo::metresBetween((float) $this->latitude, (float) $this->longitude, $latitude, $longitude);
    }

    public function contains(float $latitude, float $longitude): bool
    {
        return $this->distanceTo($latitude, $longitude) <= (float) $this->radius_m;
    }

    /** The pin for one visit's subject, or nothing. */
    public static function forSubject(?string $type, int|string|null $id): ?self
    {
        if (blank($type) || blank($id)) {
            return null;
        }

        return static::query()->where('subject_type', $type)->where('subject_id', $id)->first();
    }

    public function mapLink(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.$this->latitude.','.$this->longitude;
    }
}
