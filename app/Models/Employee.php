<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id', 'employee_id', 'phone', 'gender',
        'is_active', 'designation', 'department',
        'date_of_joining', 'nic', 'bank_id', 'bank_code', 'bank_short_code', 'bank_account_no', 'iban_no',
        'address_line_1', 'address_line_2',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
    ];

    protected static function booted()
    {
        static::saving(function ($employee) {
            if ($employee->bank_id) {
                $bank = Bank::find($employee->bank_id);
                if ($bank) {
                    $employee->bank_code = $bank->bank_code;
                    $employee->bank_short_code = $bank->bank_short_code;
                }
            } else {
                $employee->bank_code = null;
                $employee->bank_short_code = null;
            }

            $employee->routeChangesThroughApproval();
        });
    }

    /**
     * Self-service edits become a pending EmployeeChangeRequest instead
     * of touching the record; approvers' edits apply directly. The
     * transient user_name / user_email attributes write to the linked
     * user (directly for approvers, via the request for employees).
     */
    protected function routeChangesThroughApproval(): void
    {
        $userChanges = [];

        foreach (['user_name' => 'name', 'user_email' => 'email'] as $key => $column) {
            if (array_key_exists($key, $this->attributes)) {
                $value = $this->attributes[$key];
                unset($this->attributes[$key]);

                if ($value !== null && $value !== $this->user?->{$column}) {
                    $userChanges[$key] = $value;
                }
            }
        }

        $actor = auth()->user();

        $selfService = $this->exists
            && $actor
            && $actor->id === $this->user_id
            && ! $actor->hasAnyRole(['Administrator', 'Manager', 'CEO']);

        if (! $selfService) {
            if ($userChanges && $this->user_id) {
                User::where('id', $this->user_id)->update([
                    'name' => $userChanges['user_name'] ?? $this->user?->name,
                    'email' => $userChanges['user_email'] ?? $this->user?->email,
                ]);
            }

            return;
        }

        $changes = collect($this->getDirty())
            ->only(EmployeeChangeRequest::ALLOWED_FIELDS)
            ->merge($userChanges);

        if ($changes->isEmpty()) {
            // Nothing requestable changed; also drop any non-allowed edits.
            $this->setRawAttributes($this->getRawOriginal());

            return;
        }

        EmployeeChangeRequest::create([
            'employee_id' => $this->id,
            'requested_by' => $actor->id,
            'requested_changes' => $changes->all(),
            'original_values' => $changes->keys()->mapWithKeys(fn ($key) => [
                $key => match ($key) {
                    'user_name' => $this->user?->name,
                    'user_email' => $this->user?->email,
                    default => $this->getRawOriginal($key),
                },
            ])->all(),
        ]);

        // Leave the record untouched until the request is approved.
        $this->setRawAttributes($this->getRawOriginal());
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(EmployeeSetting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changeRequests()
    {
        return $this->hasMany(EmployeeChangeRequest::class);
    }
}
