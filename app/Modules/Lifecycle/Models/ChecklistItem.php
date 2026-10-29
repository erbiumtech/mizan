<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a template.
 *
 * `owner_role` is a role name — "IT", "HR", "Line manager" — rather than a person.
 * Templates outlive whoever happens to hold the job, and one pointing at somebody who
 * left is a checklist nobody owns.
 */
class ChecklistItem extends Model
{
    public const KIND_MANUAL = 'manual';

    public const KIND_LINK = 'link';

    public const KIND_AUTO = 'auto';

    public const KINDS = [self::KIND_MANUAL => 'Manual — somebody ticks it', self::KIND_LINK => 'Link — a page to open, then tick', self::KIND_AUTO => 'Auto — closes from the records'];

    /** What an auto item checks — docs/saas-launch-plan.md §14.5. */
    public const AUTO_CHECKS = ['user_created' => 'A login exists', 'package_in_effect' => 'A salary package is in effect', 'contract_active' => 'A contract is active', 'asset_issued' => 'An asset has been issued'];

    protected $fillable = ['checklist_template_id', 'title', 'kind', 'link_url', 'auto_check', 'owner_role', 'due_offset_days', 'sort'];

    protected $casts = ['due_offset_days' => 'integer', 'sort' => 'integer'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }
}
