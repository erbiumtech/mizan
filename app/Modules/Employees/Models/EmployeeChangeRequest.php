<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Notifications\EmployeeChangeRequestSubmitted;
use App\Support\TenantTransaction;
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

    /**
     * A NEW salary package rather than a change to one — the increment an appraisal
     * proposes (docs/saas-launch-plan.md §7.10). Approving creates the version,
     * from `start_date`, and closes the one before it.
     */
    public const TARGET_NEW_SETTING = 'new_employee_setting';

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
        // The record's depth — docs/saas-launch-plan.md §14.2 — which the form offers the person
        // to change and which were dropped until listed here. The two addresses are array casts:
        // the request carries them as arrays (see Employee::routeChangesThroughApproval()), and
        // displayValue() is how every screen and email shows one.
        'father_name', 'ntn', 'nationality', 'marital_status',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'current_address', 'permanent_address',
    ];

    /**
     * A requested or original value as one line, for the list, the diff and the email.
     *
     * Most are strings already. An address is an array of parts, printed in order with the empty
     * ones left out — the shape Employee::postalAddress() gives the current one.
     */
    public static function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(fn (mixed $part): string => is_array($part) ? '' : trim((string) $part), $value), fn (string $part): bool => $part !== ''));
        }

        return (string) $value;
    }

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

    /** @var array<int, string> */
    public const NEW_SETTING_FIELDS = ['start_date', 'end_date', 'fiscal_year_id', ...self::SETTING_FIELDS];

    protected $fillable = [
        'employee_id', 'target_type', 'target_id', 'review_id', 'requested_by', 'requested_changes',
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
            $approvers = User::holdingPermission('EmployeeChangeApprove')
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
        return match ($targetType) {
            self::TARGET_SETTING => self::SETTING_FIELDS,
            self::TARGET_NEW_SETTING => self::NEW_SETTING_FIELDS,
            default => self::ALLOWED_FIELDS,
        };
    }

    public function proposesNewSetting(): bool
    {
        return $this->target_type === self::TARGET_NEW_SETTING;
    }

    /** Human label for the target, for the approver's list and emails. */
    public function targetLabel(): string
    {
        return match ($this->target_type) {
            self::TARGET_SETTING => 'Salary settings',
            self::TARGET_NEW_SETTING => 'New salary package',
            default => 'Employee profile',
        };
    }

    public function approve(User $reviewer): self
    {
        if (! $this->isPending()) {
            throw new InvalidArgumentException("Only pending change requests can be approved (request is {$this->status}).");
        }

        // The only approval that spans both databases: name and email live on the
        // landlord `users` row, everything else on this company's. No transaction
        // covers both connections, so the tenant side goes first and commits, and
        // the user row follows.
        //
        // That order is deliberate. The other way round — the user write inside a
        // tenant transaction, as it was — meant a failure anywhere after it rolled
        // back the request while leaving the new name and login email applied, so
        // a request that was never approved had already changed how someone signs
        // in. This way a failure leaves the approval and the employee record
        // agreeing with each other, and one landlord field visibly not caught up.
        $userChanges = [];

        TenantTransaction::run(function () use ($reviewer, &$userChanges) {
            if ($this->targetsSetting()) {
                $this->applyToSetting();
            } elseif ($this->proposesNewSetting()) {
                $this->applyAsNewSetting();
            } else {
                $userChanges = $this->applyToEmployee();
            }

            $this->update([
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
        });

        if ($userChanges !== []) {
            $this->employee->user->update($userChanges);
        }

        return $this;
    }

    /**
     * Writes the approved profile edits.
     *
     * Returns the user_* keys for approve() to apply afterwards rather than
     * writing them here: they are the one part of this that lands on the landlord
     * connection, outside the transaction wrapping the rest.
     *
     * @return array<string, mixed> columns for the linked user
     */
    protected function applyToEmployee(): array
    {
        $changes = collect($this->requested_changes)->only(self::ALLOWED_FIELDS);

        $userChanges = [];
        foreach (['user_name' => 'name', 'user_email' => 'email'] as $key => $column) {
            if ($changes->has($key)) {
                $userChanges[$column] = $changes->pull($key);
            }
        }

        if ($changes->isNotEmpty()) {
            // The approval is the authority — don't let the interception turn
            // this write into yet another request just because the requester is
            // the one currently signed in.
            Employee::withoutApprovalRouting(fn () => $this->employee->update($changes->all()));
        }

        return $userChanges;
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

    /**
     * The approval that makes a proposed package real — plan §7.10.
     *
     * Creates the version from the requested start date, closes the package
     * before it the day before, and carries that package's data-driven
     * components across so an allowance a company added as a row survives the
     * increment. The request then points at what it made.
     */
    protected function applyAsNewSetting(): void
    {
        $fields = collect($this->requested_changes)->only(self::NEW_SETTING_FIELDS);

        if (blank($fields->get('start_date')) || blank($fields->get('fiscal_year_id'))) {
            throw new InvalidArgumentException('A new package needs a start date and a fiscal year.');
        }

        $start = \Illuminate\Support\Carbon::parse($fields->get('start_date'))->startOfDay();

        $previous = EmployeeSetting::query()
            ->where('employee_id', $this->employee_id)
            ->where('start_date', '<', $start->toDateString())
            ->orderByDesc('start_date')
            ->first();

        $setting = EmployeeSetting::withoutApprovalRouting(fn () => EmployeeSetting::create(
            ['employee_id' => $this->employee_id] + $fields->all(),
        ));

        if ($previous && ($previous->end_date === null || $previous->end_date->greaterThanOrEqualTo($start))) {
            EmployeeSetting::withoutApprovalRouting(fn () => $previous->update([
                'end_date' => $start->copy()->subDay()->toDateString(),
            ]));
        }

        if ($previous && modules()->enabled('payroll')) {
            foreach ($previous->components as $component) {
                $setting->components()->create([
                    'pay_component_id' => $component->pay_component_id,
                    'amount' => $component->amount,
                ]);
            }
        }

        $this->forceFill(['target_id' => $setting->getKey()])->save();

        // An approved increment writes the letter that records it — docs/saas-launch-plan.md §14.7.
        if ($this->review_id !== null && $this->employee !== null) {
            app(\App\Modules\Employees\Listeners\IssueEventLetter::class)->issue($this->employee, 'increment', [
                'effective_from' => (string) ($this->requested_changes['start_date'] ?? ''),
                'old_value' => number_format((float) ($this->original_values['basic_wage'] ?? 0), 2),
                'new_value' => number_format((float) ($this->requested_changes['basic_wage'] ?? 0), 2),
                'amount' => number_format((float) ($this->requested_changes['basic_wage'] ?? 0), 2),
            ]);
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
