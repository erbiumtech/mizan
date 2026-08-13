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
    protected $fillable = ['checklist_template_id', 'title', 'owner_role', 'due_offset_days', 'sort'];

    protected $casts = ['due_offset_days' => 'integer', 'sort' => 'integer'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }
}
