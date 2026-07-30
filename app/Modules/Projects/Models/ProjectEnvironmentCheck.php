<?php

namespace App\Modules\Projects\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One health-check result. Append-only history behind the uptime figure and the
 * latency chart — deliberately not Auditable, since auditing an audit trail
 * only doubles the write volume.
 */
class ProjectEnvironmentCheck extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = [
        'project_environment_id', 'checked_at', 'is_up', 'status_code', 'latency_ms', 'error',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'is_up' => 'boolean',
    ];

    public function prunable(): Builder
    {
        return static::where(
            'checked_at',
            '<',
            now()->subDays((int) config('projects.health.retention_days', 30))
        );
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'project_environment_id');
    }
}
