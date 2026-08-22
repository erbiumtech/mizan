<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Concerns\HasMaterialisedPath;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One node of a job's work breakdown: *what* is being built, and where.
 *
 * `docs/construction-management-plan.md` §2. **A node is a deliverable or a location, never a cost type** —
 * "Tower B / Level 4 / Facade" belongs here and "welding labour" does not, which lives in the per-tenant
 * cost-code library instead. The distinction is the reason a construction system is not a generic project
 * tracker: the intersection of a WBS node and a cost code is the control account that carries a budget, and
 * ANSI/EIA-748 defines that intersection as the point where scope, budget and actuals meet.
 *
 * Mixing the two would make the same money classifiable two ways with no way to reconcile them.
 */
class WbsNode extends Model
{
    use Auditable;
    use HasMaterialisedPath;

    public const KIND_PHASE = 'phase';

    public const KIND_ZONE = 'zone';

    public const KIND_LEVEL = 'level';

    public const KIND_ELEMENT = 'element';

    public const KIND_PACKAGE = 'package';

    protected $table = 'construction_wbs_nodes';

    protected $fillable = [
        'job_id', 'parent_id', 'path', 'code', 'name', 'kind', 'sort_order', 'is_leaf',
    ];

    protected $casts = [
        'is_leaf' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'kind' => self::KIND_PACKAGE,
        'sort_order' => 0,
        'is_leaf' => true,
    ];

    /**
     * A node's tree belongs to one job.
     *
     * Without this the subtree scope would `LIKE '/1/%'` across every job in the company, and two jobs whose
     * node ids happen to prefix-match would roll one's cost into the other's report.
     */
    public static function pathScopeColumn(): ?string
    {
        return 'job_id';
    }

    protected static function booted(): void
    {
        static::created(fn (self $node) => $node->markParentAsBranch());

        // Also on a move, and the CSV import is why: it creates rows as roots and links parents in a second
        // pass, so without this an imported heading still looks bookable. See refreshLeafFlags().
        static::updated(function (self $node): void {
            if ($node->wasChanged('parent_id')) {
                $node->refreshLeafFlags();
            }
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * `1.2.3 Facade` — how a surveyor refers to a node out loud.
     *
     * One accessor because it appears in the budget screen, the cost report, the measurement sheet and every
     * picker, and four different concatenations would eventually disagree about the separator.
     */
    public function label(): string
    {
        return trim("{$this->code} {$this->name}");
    }
}
