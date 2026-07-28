<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Notifications\EmployeeChangeRequestSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class EmployeeChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** The employee's own profile row (and the linked user). */
    public const TARGET_EMPLOYEE = 'employee';

    /** One of the employee's salary settings rows, named by `target_id`. */
    public const TARGET_SETTING = 'employee_setting';

    /** Employee-editable attributes; user_* keys write to the linked user. */
    /**
     * Fields an employee may request a change to.
     *
     * Anything omitted here is silently dropped from a self-service edit — and
     * if it is the only change, no request is created at all. Adding a personal
     * detail to the employee form means adding it here too.
     */
    public const ALLOWED_FIELDS = [
        'user_name', 'user_email', 'personal_email', 'nic', 'nic_front', 'nic_back',
        'date_of_joining', 'date_of_birth', 'phone', 'secondary_phone', 'gender',
        'bank_id', 'bank_account_no', 'iban_no', 'address_line_1', 'address_line_2',
    ];

    /**
     * Requested values that are file paths on the `public` disk rather than
     * literals, so the reviewer is shown the image instead of a filename.
     */
    public const IMAGE_FIELDS = ['nic_front', 'nic_back'];

    /**
     * Employee-requestable settings attributes: the compensation figures only.
     * The period and fiscal year that decide *which* payslips a settings row
     * governs stay with the administrators who create it.
     */
    public const SETTING_FIELDS = [
        'basic_wage', 'medical_allowance', 'device_allowance', 'petrol_allowance',
        'bonus', 'extra_work_hours', 'advances', 'meal_deduction', 'esi_health_insurance',
    ];

    protected $fillable = [
        'employee_id', 'target_type', 'target_id', 'requested_by', 'requested_changes',
        'original_values', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected $attributes = [
        'target_type' => self::TARGET_EMPLOYEE,
    ];

    protected $casts = [
        'requested_changes' => 'array',
        'original_values' => 'array',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::created(function (EmployeeChangeRequest $request) {
            $approvers = User::permission('EmployeeChangeApprove')
                ->where('id', '!=', $request->requested_by)
                ->where('status', 1)
                ->get();

            Notification::send($approvers, new EmployeeChangeRequestSubmitted($request));
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The settings row this request targets, if it targets one. */
    public function setting()
    {
        return $this->belongsTo(EmployeeSetting::class, 'target_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function targetsSetting(): bool
    {
        return $this->target_type === self::TARGET_SETTING;
    }

    /** Which attributes an employee may request on a given target. */
    public static function allowedFieldsFor(string $targetType): array
    {
        return $targetType === self::TARGET_SETTING
            ? self::SETTING_FIELDS
            : self::ALLOWED_FIELDS;
    }

    /** Human label for the target, for the approver's list and emails. */
    public function targetLabel(): string
    {
        return $this->targetsSetting() ? 'Salary settings' : 'Employee profile';
    }

    public function approve(User $reviewer): self
    {
        if (! $this->isPending()) {
            throw new InvalidArgumentException("Only pending change requests can be approved (request is {$this->status}).");
        }

        return DB::transaction(function () use ($reviewer) {
            $this->targetsSetting() ? $this->applyToSetting() : $this->applyToEmployee();

            $this->update([
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $this;
        });
    }

    /** Writes the approved profile edits; user_* keys land on the linked user. */
    protected function applyToEmployee(): void
    {
        $changes = collect($this->requested_changes)->only(self::ALLOWED_FIELDS);

        $userChanges = [];
        foreach (['user_name' => 'name', 'user_email' => 'email'] as $key => $column) {
            if ($changes->has($key)) {
                $userChanges[$column] = $changes->pull($key);
            }
        }

        if ($userChanges) {
            $this->employee->user->update($userChanges);
        }

        if ($changes->isNotEmpty()) {
            // The approval is the authority — don't let the interception turn
            // this write into yet another request just because the requester is
            // the one currently signed in.
            Employee::withoutApprovalRouting(fn () => $this->employee->update($changes->all()));
        }
    }

    /**
     * Writes the approved compensation figures onto the targeted settings row.
     *
     * The row is reloaded rather than taken from the relation so a settings
     * record deleted while the request sat pending is caught here instead of
     * silently doing nothing.
     */
    protected function applyToSetting(): void
    {
        $setting = EmployeeSetting::find($this->target_id);

        if (! $setting) {
            throw new InvalidArgumentException('The salary settings this request targets no longer exist.');
        }

        $changes = collect($this->requested_changes)->only(self::SETTING_FIELDS);

        if ($changes->isNotEmpty()) {
            // Bypass the self-service interception — this write *is* the
            // approval — while keeping model events, so the audit trail and the
            // end-date defaulting still run.
            EmployeeSetting::withoutApprovalRouting(fn () => $setting->update($changes->all()));
        }
    }

    public function reject(User $reviewer, ?string $reason = null): self
    {
        if (! $this->isPending()) {
            throw new InvalidArgumentException("Only pending change requests can be rejected (request is {$this->status}).");
        }

        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $this;
    }
}
