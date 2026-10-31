<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employment contract — docs/saas-launch-plan.md §14.5: type, number, dates, gross,
 * probation; draft, active, ended. Rendered from a template with the verification QR,
 * accepted by the employee in the panel with the time and the address it came from.
 */
class EmploymentContract extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    // Contract types are an admin-managed list: options('lifecycle.employment_contract_type'),
    // declared in the Lifecycle module's module.php. Nothing here computes from the type.

    protected $fillable = [
        'employee_id', 'number', 'type', 'starts_on', 'ends_on', 'gross', 'probation_months', 'status',
        'accepted_at', 'accepted_ip', 'witnesses', 'ip_disclosure', 'signed_document_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'gross' => 'decimal:2', 'probation_months' => 'integer',
        'accepted_at' => 'datetime', 'witnesses' => 'array', 'ip_disclosure' => 'boolean',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected static function booted(): void
    {
        static::creating(function (self $contract): void {
            $contract->created_by ??= auth()->id();
            $contract->number ??= static::nextNumber();
        });
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $count = static::query()->where('number', 'like', "CON-{$year}-%")->count() + 1;

        return sprintf('CON-%d-%04d', $year, $count);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'signed_document_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function typeLabel(): string
    {
        // The types are an admin-managed list now (lifecycle.employment_contract_type), not a constant.
        return options('lifecycle.employment_contract_type', $this->type)[$this->type] ?? ucfirst((string) $this->type);
    }
}
