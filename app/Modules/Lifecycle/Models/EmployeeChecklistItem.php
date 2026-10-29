<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeChecklistItem extends Model
{
    use StoresPlainDates;

    protected array $plainDates = ['due_on'];

    protected $fillable = [
        'employee_checklist_id', 'title', 'kind', 'link_url', 'auto_check', 'owner_role', 'assignee_employee_id',
        'due_on', 'completed_at', 'completed_by', 'waived_at', 'waived_by', 'waive_reason', 'note', 'sort',
    ];

    protected $casts = ['due_on' => 'date', 'completed_at' => 'datetime', 'waived_at' => 'datetime', 'sort' => 'integer'];

    public function isDone(): bool
    {
        return $this->completed_at !== null || $this->waived_at !== null;
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EmployeeChecklist::class, 'employee_checklist_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }
}
