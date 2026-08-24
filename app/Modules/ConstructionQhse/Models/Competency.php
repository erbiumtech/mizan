<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket somebody holds — `docs/construction-management-plan.md` §17.5.
 *
 * Training, a licence, a certification, a medical, an authorisation. Each with an expiry, and each with
 * `expiry_notified_at_days` — copied from `employee_documents` and `construction_compliance_items` for the reason both
 * give: the column records *which* warning has already gone out, so a daily run warns once per threshold rather than
 * every morning until somebody acts. Whoever receives a warning every day stops reading them.
 *
 * **`is_mandatory` is the column that turns an expiry into a stoppage.** A first-aid certificate lapsing is a gap; a
 * confined-space ticket lapsing on somebody who is in a chamber this morning is an emergency, and only the flag can tell
 * them apart.
 *
 * `expires_on` is nullable because some things genuinely do not expire, and a required expiry would make people type a
 * date they do not have — which is worse than an honest blank.
 */
class Competency extends Model
{
    /** @var array<string, string> */
    public const KINDS = [
        'training' => 'Training',
        'licence' => 'Licence',
        'certification' => 'Certification',
        'medical' => 'Medical',
        'authorisation' => 'Authorisation',
    ];

    /**
     * The days before expiry at which somebody is warned, and once per threshold.
     *
     * The same ladder §12's compliance register and §13's notice clock use, and for the same reason: a single warning is
     * one that lands while the person who can act on it is on leave. The tightest is the day itself, which is why the
     * run is daily.
     *
     * @var array<int, int>
     */
    public const WARNING_THRESHOLDS = [60, 30, 14, 7, 0];

    protected $table = 'construction_competencies';

    protected $fillable = [
        'site_personnel_id', 'kind', 'title', 'reference', 'issuing_body',
        'issued_on', 'expires_on', 'is_mandatory', 'expiry_notified_at_days', 'document_path', 'notes',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'is_mandatory' => 'boolean',
        'expiry_notified_at_days' => 'integer',
    ];

    protected $attributes = [
        'kind' => 'training',
        'is_mandatory' => false,
    ];

    public function sitePersonnel(): BelongsTo
    {
        return $this->belongsTo(SitePersonnel::class, 'site_personnel_id');
    }

    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true);
    }

    public function scopeExpiring(Builder $query, int $withinDays, ?string $asAt = null): Builder
    {
        $from = Carbon::parse($asAt ?? now())->startOfDay();

        return $query->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', $from->toDateString())
            ->whereDate('expires_on', '<=', $from->copy()->addDays($withinDays)->toDateString());
    }

    public function scopeExpired(Builder $query, ?string $asAt = null): Builder
    {
        return $query->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', Carbon::parse($asAt ?? now())->toDateString());
    }

    /** Never expires, which is a real and common state. */
    public function neverExpires(): bool
    {
        return $this->expires_on === null;
    }

    public function hasExpired(?string $asAt = null): bool
    {
        return $this->expires_on !== null
            && $this->expires_on->lt(Carbon::parse($asAt ?? now())->startOfDay());
    }

    /** Negative once it has lapsed, which is what a chase list sorts on. */
    public function daysUntilExpiry(?string $asAt = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        return (int) Carbon::parse($asAt ?? now())->startOfDay()->diffInDays($this->expires_on, absolute: false);
    }

    /**
     * The tightest threshold this ticket has reached and not yet been warned at.
     *
     * `array_reverse` because the constant is written loosest-first for readability and the tightest unwarned threshold
     * is the one to fire — the bug Phase 9a made and this suite now guards against by writing it the same way twice.
     */
    public function warningThreshold(?string $asAt = null): ?int
    {
        $days = $this->daysUntilExpiry($asAt);

        if ($days === null) {
            return null;
        }

        foreach (array_reverse(self::WARNING_THRESHOLDS) as $threshold) {
            if ($days <= $threshold && ($this->expiry_notified_at_days === null || $threshold < $this->expiry_notified_at_days)) {
                return $threshold;
            }
        }

        return null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function displayName(): string
    {
        return $this->title.($this->reference ? " ({$this->reference})" : '');
    }
}
