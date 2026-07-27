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

    /** Employee-editable attributes; user_* keys write to the linked user. */
    public const ALLOWED_FIELDS = [
        'user_name', 'user_email', 'personal_email', 'nic', 'date_of_joining', 'phone', 'gender',
        'bank_id', 'bank_account_no', 'iban_no', 'address_line_1', 'address_line_2',
    ];

    protected $fillable = [
        'employee_id', 'requested_by', 'requested_changes', 'original_values', 'status',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
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

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function approve(User $reviewer): self
    {
        if (! $this->isPending()) {
            throw new InvalidArgumentException("Only pending change requests can be approved (request is {$this->status}).");
        }

        return DB::transaction(function () use ($reviewer) {
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
                $this->employee->update($changes->all());
            }

            $this->update([
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $this;
        });
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
