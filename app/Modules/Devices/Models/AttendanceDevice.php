<?php

namespace App\Modules\Devices\Models;

use App\Modules\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A registered device — landlord, found by serial — docs/saas-launch-plan.md §8.7. */
class AttendanceDevice extends Model
{
    protected $fillable = ['company_id', 'serial_no', 'name', 'model', 'last_seen_at', 'silence_notified_at', 'is_active'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'silence_notified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function findBySerial(?string $serial): ?self
    {
        $serial = trim((string) $serial);

        return $serial === '' ? null : static::query()->active()->where('serial_no', $serial)->first();
    }

    /** Quiet for longer than a day, and never yet seen counts as quiet from registration. */
    public function isSilent(): bool
    {
        $since = $this->last_seen_at ?? $this->created_at;

        return $since !== null && $since->lt(now()->subDay());
    }
}
