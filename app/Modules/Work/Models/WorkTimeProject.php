<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A time tracker's project, linked to one of ours — docs/project-management-plan.md §8.8. */
class WorkTimeProject extends Model
{
    use Auditable;

    protected $fillable = ['integration_connection_id', 'project_id', 'external_project_id', 'name'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkTimeTask::class);
    }
}
