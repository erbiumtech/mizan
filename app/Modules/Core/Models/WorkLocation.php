<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A branch, site or office — docs/saas-launch-plan.md §7.9.
 *
 * Somewhere employees are, holidays may be local to, and a punch may be
 * judged against. The fence is a point and a radius in metres; a location
 * without them is a place, and nothing judges a punch against it.
 */
class WorkLocation extends Model
{
    use Auditable;

    /**
     * `locations`, as the plan names it. The class is WorkLocation because every
     * morph alias is `App\Models\<basename>` and Construction already holds
     * `Location` for its site register; the columns that point here stay
     * `location_id`, which is what they are.
     */
    protected $table = 'locations';

    protected $fillable = [
        'name', 'code', 'address', 'city', 'latitude', 'longitude', 'geofence_radius_m', 'is_active',
        // docs/saas-launch-plan.md §14.10.
        'phone', 'map_url', 'is_head_office',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geofence_radius_m' => 'integer',
        'is_active' => 'boolean',
        'is_head_office' => 'boolean',
    ];

    /**
     * The branch a document is issued from — docs/saas-launch-plan.md §14.10.
     *
     * The one flagged, else the first active one, else nothing. A company that has
     * never thought about it has one branch and the fallback is right; a company with
     * five has had to choose, and the flag is where it said so.
     */
    protected static function booted(): void
    {
        // One head office, enforced here rather than by a unique index: a partial index
        // on a boolean is not portable, and the rule is "the last one to say so wins"
        // rather than "the second one is refused".
        static::saved(function (self $location): void {
            if ($location->is_head_office) {
                static::query()->whereKeyNot($location->getKey())->where('is_head_office', true)
                    ->update(['is_head_office' => false]);
            }
        });
    }

    public static function headOffice(): ?self
    {
        return static::query()->where('is_head_office', true)->first()
            ?? static::query()->active()->orderBy('name')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<int, string> id => name, for a select */
    public static function options(): array
    {
        return static::query()->active()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function hasGeofence(): bool
    {
        return $this->latitude !== null && $this->longitude !== null && (int) $this->geofence_radius_m > 0;
    }

    /** Great-circle distance from a point to this location, in metres. */
    public function distanceTo(float $latitude, float $longitude): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($latitude - (float) $this->latitude);
        $dLng = deg2rad($longitude - (float) $this->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $this->latitude)) * cos(deg2rad($latitude)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    /** Inside the fence? Null when there is no fence to ask about. */
    public function contains(float $latitude, float $longitude): ?bool
    {
        if (! $this->hasGeofence()) {
            return null;
        }

        return $this->distanceTo($latitude, $longitude) <= (float) $this->geofence_radius_m;
    }
}
