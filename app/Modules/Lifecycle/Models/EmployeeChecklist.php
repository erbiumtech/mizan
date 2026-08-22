<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's run through a checklist.
 *
 * Items are COPIED from the template rather than joined to it, so a leaver's checklist
 * says what they were actually asked to do rather than what the template says today.
 */
class EmployeeChecklist extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['started_on', 'completed_on'];

    protected $fillable = ['employee_id', 'checklist_template_id', 'kind', 'started_on', 'completed_on'];

    protected $casts = ['started_on' => 'date', 'completed_on' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeChecklistItem::class)->orderBy('sort');
    }

    public function isComplete(): bool
    {
        return ! $this->items()->whereNull('completed_at')->exists();
    }

    public function outstandingCount(): int
    {
        return $this->items()->whereNull('completed_at')->count();
    }
}
