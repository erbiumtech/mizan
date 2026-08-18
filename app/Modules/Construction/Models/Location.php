<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Concerns\HasMaterialisedPath;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where something is on a job: site, building, block, level, zone, room, grid, chainage or structure.
 *
 * `docs/construction-management-plan.md` §16.5, and it is built in Phase 1 rather than with the field module
 * because five subsystems reference it — punch items, diary photos, inspections, NCRs and incidents all have
 * to say *where*. Five free-text location columns is five spellings of "Level 3 East", and the report that
 * matters most before handover, **every open item in this room**, becomes impossible to write.
 *
 * Separate from the WBS on purpose. A WBS node is a commercial breakdown that carries budget and gets
 * measured; a location is a physical place that carries observations. Merging them would put a budget on a
 * room and an inspection on a cost package.
 */
class Location extends Model
{
    use Auditable;
    use HasMaterialisedPath;

    public const TYPE_SITE = 'site';

    public const TYPE_BUILDING = 'building';

    public const TYPE_LEVEL = 'level';

    public const TYPE_ZONE = 'zone';

    public const TYPE_ROOM = 'room';

    protected $table = 'construction_locations';

    protected $fillable = [
        'job_id', 'parent_id', 'path', 'code', 'name', 'type', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'type' => self::TYPE_ZONE,
        'sort_order' => 0,
    ];

    /** One job's places, so a subtree query cannot cross into another job's site. */
    public static function pathScopeColumn(): ?string
    {
        return 'job_id';
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * `Tower B › Level 4 › Room 412` — the whole place, not just the last part of it.
     *
     * A punch list showing "Room 412" is ambiguous the moment a second tower has one, and this is the string
     * every such screen needs. Built from the stored path so it is one query for the ancestors rather than one
     * per level, and ordered by path length so the parts come out top-down.
     */
    public function fullName(string $separator = ' › '): string
    {
        $ids = array_values(array_filter(explode('/', (string) $this->path)));

        if ($ids === []) {
            return $this->name;
        }

        $names = static::query()
            ->whereIn('id', $ids)
            ->orderByRaw('LENGTH(path)')
            ->pluck('name', 'id');

        return implode($separator, $names->values()->all());
    }
}
