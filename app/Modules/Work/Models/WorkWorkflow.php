<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A set of statuses and the rules between them. A project points at one — docs/project-management-plan.md §4.1. */
class WorkWorkflow extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    // `review_status_id`: where a pull request being opened moves an item — §8.4. Null means
    // "the status named In review, if the workflow has one".
    protected $fillable = ['name', 'description', 'is_default', 'review_status_id'];

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

    /**
     * The workflow a project's items move through: its own, or the company default, statuses loaded.
     *
     * Read from the column rather than through a relation on Project for the reason WorkItem::workflow()
     * gives — `work` requires `projects`, never the other way round.
     */
    public static function forProject(\App\Modules\Projects\Models\Project $project): ?self
    {
        $workflow = $project->work_workflow_id !== null
            ? static::query()->whereKey($project->work_workflow_id)->first()
            : static::default();

        return $workflow?->load('statuses');
    }

    /** The first status of a category, which is where the engine lands an item it moves by category. */
    public function firstOf(string $category): ?WorkStatus
    {
        return $this->statuses()->where('category', $category)->orderBy('sort')->first();
    }

    // ─── Replicate — docs/replicate-records.md ───

    /**
     * A new workflow built from this one, under its own name: the same statuses in the same order and
     * categories, and the same rules. Rules and the pull-request status point at statuses by id, so the
     * copy's point at the copy's own statuses, never back at this workflow's. It is not the default,
     * and no project or board uses it until someone picks it.
     */
    public function replicationUniqueFields(): array
    {
        return ['name' => self::suggestCopyOf('name')];
    }

    public function replicationResets(): array
    {
        return [
            'is_default' => false,
            'review_status_id' => null,
        ];
    }

    public function afterReplication(EloquentModel $original): void
    {
        /** @var self $original */
        $original->loadMissing(['statuses', 'rules']);

        /** @var array<int, int> $map the original's status id => the copy's */
        $map = [];

        foreach ($original->statuses as $status) {
            $copy = $this->statuses()->save($status->replicate(['created_at', 'updated_at']));
            $map[(int) $status->getKey()] = (int) $copy->getKey();
        }

        foreach ($original->rules as $rule) {
            $copy = $rule->replicate(['created_at', 'updated_at']);
            $copy->work_status_id = $rule->work_status_id === null ? null : ($map[(int) $rule->work_status_id] ?? null);
            $copy->to_status_ids = $rule->to_status_ids === null
                ? null
                : array_values(array_filter(array_map(fn ($id): ?int => $map[(int) $id] ?? null, $rule->to_status_ids)));

            $this->rules()->save($copy);
        }

        if ($original->review_status_id !== null && isset($map[(int) $original->review_status_id])) {
            $this->forceFill(['review_status_id' => $map[(int) $original->review_status_id]])->save();
        }
    }
}
