<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A set of statuses and the rules between them. A project points at one — docs/project-management-plan.md §4.1. */
class WorkWorkflow extends Model
{
    use Auditable;

    protected $fillable = ['name', 'description', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function statuses(): HasMany
    {
        return $this->hasMany(WorkStatus::class)->orderBy('sort');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(WorkWorkflowRule::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(\App\Modules\Projects\Models\Project::class);
    }

    /** The one a project with no workflow of its own falls back to. */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('id')->first();
    }

    /** The first status of a category, which is where the engine lands an item it moves by category. */
    public function firstOf(string $category): ?WorkStatus
    {
        return $this->statuses()->where('category', $category)->orderBy('sort')->first();
    }
}
