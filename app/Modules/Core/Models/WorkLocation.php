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
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geofence_radius_m' => 'integer',
        'is_active' => 'boolean',
    ];

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
