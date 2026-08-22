<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who was at a toolbox talk — `docs/construction-management-plan.md` §17.5.
 *
 * **A register row where there is one, and a plain name where there is not.** §17.5: "a person's name must work without
 * an employee record, because most attendees on most sites are a subcontractor's labourers."
 *
 * The name is copied onto the row even when the register is linked, and that is deliberate rather than redundant: a
 * register entry can be renamed or removed, and the attendance record has to keep saying who was actually there. It is
 * the same snapshot reasoning as the diary's trade label.
 */
class ToolboxTalkAttendee extends Model
{
    protected $table = 'construction_toolbox_talk_attendees';

    protected $fillable = [
        'toolbox_talk_id', 'site_personnel_id', 'name', 'employer', 'signed',
    ];

    protected $casts = [
        'signed' => 'boolean',
    ];

    protected $attributes = [
        'signed' => false,
    ];

    public function toolboxTalk(): BelongsTo
    {
        return $this->belongsTo(ToolboxTalk::class, 'toolbox_talk_id');
    }

    public function sitePersonnel(): BelongsTo
    {
        return $this->belongsTo(SitePersonnel::class, 'site_personnel_id');
    }

    /** In the induction register, rather than only a name on a sheet. */
    public function isOnTheRegister(): bool
    {
        return $this->site_personnel_id !== null;
    }

    public function displayName(): string
    {
        return $this->name.($this->employer ? " ({$this->employer})" : '');
    }
}
