<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Proof that a phone number or an address is really this person's —
 * docs/saas-launch-plan.md §14.8.
 *
 * **It proves a value, not a person.** The number or the address that was proved is
 * stored on the row, and {@see provesCurrent()} compares it against what the record
 * says today. So somebody who changes their phone is unverified again immediately,
 * with nothing having to watch for the change: the proof is simply no longer about
 * the number they now have.
 *
 * The code is never stored. A six-digit code in a table is a code anybody with the
 * table can type, and the only thing that has to be answered is "is the code they
 * typed the one we sent".
 */
class EmployeeVerification extends Model
{
    /** The primary mobile number, by a WhatsApp code. */
    public const KIND_PHONE = 'phone';

    /** The emergency contact's number, by a WhatsApp code to that number. */
    public const KIND_EMERGENCY_PHONE = 'emergency_phone';

    /** The home address, by a code printed on a letter and typed back (§14.7). */
    public const KIND_ADDRESS = 'address';

    public const KINDS = [
        self::KIND_PHONE => 'Primary phone',
        self::KIND_EMERGENCY_PHONE => 'Emergency contact',
        self::KIND_ADDRESS => 'Home address',
    ];

    /** How long a code is good for. Long enough for a posted letter is not the same as for WhatsApp. */
    public const MINUTES = [
        self::KIND_PHONE => 15,
        self::KIND_EMERGENCY_PHONE => 15,
        self::KIND_ADDRESS => 60 * 24 * 30,
    ];

    /** Wrong tries before the code is burnt, so a six-digit code cannot be guessed. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['employee_id', 'kind', 'target', 'code_hash', 'sent_at', 'expires_at', 'attempts', 'verified_at'];

    protected $casts = [
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected $hidden = ['code_hash'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** What this kind of check is about, as the record holds it today. */
    public static function valueOn(Employee $employee, string $kind): ?string
    {
        return self::normalise($kind, match ($kind) {
            self::KIND_PHONE => $employee->phone,
            self::KIND_EMERGENCY_PHONE => $employee->emergency_contact_phone,
            self::KIND_ADDRESS => $employee->postalAddress(),
            default => null,
        });
    }

    /**
     * The comparable form of a value.
     *
     * A number becomes E.164 so "0300 1234567" and "+923001234567" are one value. An
     * address becomes its own letters and digits, lowercased, so a comma or a line
     * break added later does not un-verify somebody's house.
     */
    public static function normalise(string $kind, ?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($kind === self::KIND_ADDRESS) {
            return Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', trim($value)) ?? '') ?: null;
        }

        return PhoneNumber::e164($value);
    }

    /** Whether this row proves what the record says now. */
    public function provesCurrent(Employee $employee): bool
    {
        return $this->verified_at !== null
            && $this->target !== null
            && $this->target === self::valueOn($employee, (string) $this->kind);
    }

    public function isUsable(): bool
    {
        return $this->code_hash !== null
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at instanceof Carbon
            && $this->expires_at->isFuture();
    }
}
