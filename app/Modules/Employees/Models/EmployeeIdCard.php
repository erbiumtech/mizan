<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff card, and whether it is still good — docs/saas-launch-plan.md §14.10.
 *
 * **The serial is not the employee code.** Somebody who loses a card is issued
 * another, and a guard holding a card has to be able to tell which one it is: a
 * found card and a replacement would otherwise be indistinguishable, and cancelling
 * the lost one would cancel both.
 *
 * Revoked rather than deleted, for the same reason. A cancelled card is exactly the
 * card somebody might present, and the verification page has to be able to say what
 * happened to it.
 */
class EmployeeIdCard extends Model
{
    use Auditable;

    /** What `DocumentVerification` files these under. */
    public const KIND = 'id-card';

    protected $fillable = ['employee_id', 'serial', 'issued_on', 'expires_on', 'revoked_at', 'revoked_reason', 'issued_by'];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'revoked_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(?string $asOf = null): bool
    {
        return $this->expires_on !== null
            && $this->expires_on->lt(Carbon::parse($asOf ?? now()->toDateString()));
    }

    /** Good, expired, or cancelled — the three things a reader needs. */
    public function state(?string $asOf = null): string
    {
        return match (true) {
            $this->isRevoked() => 'Cancelled',
            $this->hasExpired($asOf) => 'Expired',
            default => 'Valid',
        };
    }

    /**
     * The next serial for this company.
     *
     * Year and a running number — `2026-0041` — because a serial is read aloud down a
     * phone and a bare sequence tells nobody when the card was made.
     */
    public static function nextSerial(?string $on = null): string
    {
        $year = Carbon::parse($on ?? now()->toDateString())->year;

        $highest = static::query()
            ->where('serial', 'like', $year.'-%')
            ->pluck('serial')
            ->map(fn (string $serial): int => (int) substr($serial, 5))
            ->max() ?? 0;

        return $year.'-'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /** What the QR on the card answers with, and what the verify page shows. */
    public function verificationFacts(): array
    {
        $this->loadMissing('employee.user');

        return [
            'Card' => (string) $this->serial,
            'Name' => (string) $this->employee?->fullName(),
            'Employee code' => (string) $this->employee?->employee_id,
            'Designation' => (string) ($this->employee?->designation ?: '—'),
            'Issued' => (string) $this->issued_on?->toDateString(),
            'Expires' => (string) ($this->expires_on?->toDateString() ?: 'does not expire'),
            'Status' => $this->state(),
        ];
    }
}
