<?php

namespace App\Modules\Construction\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A `/1/7/23/` ancestor path on a self-referencing tree, so a rollup is one `LIKE` and needs no recursion.
 *
 * Three trees in the construction suite need this — jobs (§1.2), WBS nodes (§2) and locations (§16.5) — and
 * they need it for the same reason: every report takes a node and rolls up its descendants. A cost report that
 * silently omits a lot, or a punch list that misses a floor, is a wrong number that looks like a right one.
 *
 * **Shared rather than copied because the lifecycle is the subtle part.** The first version of this on `Job`
 * wrote the path in `saving`, where a new record has no id yet — so every root got `path = '/'` and the
 * subtree scope matched the entire table. Writing it three times would have been three chances to reintroduce
 * that. The rules:
 *
 *  - the path is written **after** the insert, because it contains the node's own id;
 *  - it is written with a query and no events, so nothing re-fires;
 *  - moving a node re-paths its whole subtree, parents before children, each derived from its parent's
 *    *stored* path — so a subtree that was already inconsistent comes out right rather than carrying the
 *    error down.
 *
 * A consumer supplies `pathScopeColumn()` when its tree is partitioned. Jobs are company-wide; a WBS node and
 * a location belong to one job, and a subtree query that crossed jobs would roll one job's cost into another's
 * report — so those return `'job_id'` and every scope is filtered by it.
 */
trait HasMaterialisedPath
{
    /**
     * The column that partitions the tree, or null when it is company-wide.
     */
    public static function pathScopeColumn(): ?string
    {
        return null;
    }

    public static function bootHasMaterialisedPath(): void
    {
        static::created(function (Model $node): void {
            $node->writePath();
        });

        // Only on an actual move: re-saving a node is routine and re-pathing a subtree on every save is
        // waste, not safety.
        static::updated(function (Model $node): void {
            if ($node->wasChanged('parent_id')) {
                $node->writePath();
            }
        });
    }

    /** `/parent/…/self/`, derived from the parent's stored path — one query rather than a walk up. */
    public function buildPath(): string
    {
        if (! $this->getKey()) {
            throw new LogicException(static::class.' needs to exist before it has a path: build it after the insert.');
        }

        if (! $this->parent_id) {
            return "/{$this->getKey()}/";
        }

        $parent = static::query()->find($this->parent_id);
        $prefix = $parent?->path ?: "/{$this->parent_id}/";

        return $prefix.$this->getKey().'/';
    }

    /** This node's path and every descendant's, parents first. */
    public function writePath(): void
    {
        $path = $this->buildPath();

        static::withoutEvents(fn () => static::whereKey($this->getKey())->update(['path' => $path]));

        $this->path = $path;
        $this->syncOriginalAttribute('path');

        static::query()->where('parent_id', $this->getKey())->get()->each->writePath();
    }

    /**
     * A node that gains a child stops being bookable.
     *
     * A parent carrying cost *and* children makes every rollup count it twice, which is the one arithmetic
     * error a tree like this produces with nothing reporting it. Shared by the WBS and the cost-code library
     * because both are booked against and both roll up; called from each model's own `created` hook rather
     * than registered here, since not every tree has an `is_leaf` column.
     */
    protected function markParentAsBranch(): void
    {
        if ($this->parent_id) {
            static::withoutEvents(fn () => static::whereKey($this->parent_id)->update(['is_leaf' => false]));
        }
    }

    /**
     * Keep the leaf flags right on **both** sides of a move.
     *
     * `markParentAsBranch()` on its own is not enough, and the CSV import is what proved it: that importer
     * writes every row first and resolves `parent_code` to an id in a second pass, so the child is *created*
     * as a root and only later *updated* to have a parent. The `created` hook therefore never fires for the
     * real parent, and an imported library ends up full of headings that still look bookable — which is
     * precisely the double-count this flag exists to prevent.
     *
     * The other half is symmetric and just as easy to miss: a node that gave up its last child becomes
     * bookable again, and leaving it flagged as a heading hides it from every picker with no explanation.
     */
    protected function refreshLeafFlags(): void
    {
        $this->markParentAsBranch();

        $previous = $this->getOriginal('parent_id');

        if (! $previous || (int) $previous === (int) $this->parent_id) {
            return;
        }

        if (static::query()->where('parent_id', $previous)->doesntExist()) {
            static::withoutEvents(fn () => static::whereKey($previous)->update(['is_leaf' => true]));
        }
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * This node and everything under it.
     *
     * Filtered by the partition column as well as the path, because two jobs' trees share one table and a
     * `LIKE '/1/%'` that crossed them would roll one job's figures into another's report.
     */
    public function scopeInSubtree(Builder $query, Model $root): Builder
    {
        $scope = static::pathScopeColumn();

        return $query
            ->when($scope !== null, fn (Builder $q) => $q->where($scope, $root->{$scope}))
            ->where('path', 'like', $root->path.'%');
    }

    /** The roots of one partition, or of the whole tree when it has none. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** How deep this node sits, counted from its path rather than by walking. */
    public function depth(): int
    {
        return max(0, substr_count((string) $this->path, '/') - 2);
    }
}
