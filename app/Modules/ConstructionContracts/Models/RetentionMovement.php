<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retention event — `docs/construction-management-plan.md` §11.
 *
 * **The balance is the sum of these rows and is never stored.** What is stored is the events, because a bond
 * substituting for cash, an early release on a sectional taking-over, a forfeit against uncorrected defects and an
 * agreed one-off adjustment are decisions rather than arithmetic — and none of them is derivable from the
 * certificates.
 *
 * **Signed, one convention:** positive is held, negative is released or forfeited. A release that arrived positive
 * would increase the money being withheld while every screen called it a release.
 */
class RetentionMovement extends Model
{
    use Auditable;

    public const KIND_HELD = 'held';

    public const KIND_RELEASED = 'released';

    public const KIND_FORFEITED = 'forfeited';

    public const KIND_SUBSTITUTED_BY_BOND = 'substituted_by_bond';

    public const KIND_REINSTATED = 'reinstated';

    public const KIND_ADJUSTED = 'adjusted';

    public const STAGE_INTERIM = 'interim';

    public const STAGE_FIRST_RELEASE = 'first_release';

    public const STAGE_FINAL_RELEASE = 'final_release';

    public const STAGE_EARLY_RELEASE = 'early_release';

    /**
     * The kinds that need a stated reason, enforced in `RetentionService`.
     *
     * A forfeit takes money the other party earned; an adjustment is a negotiated figure; a substitution replaces
     * cash with a piece of paper whose value depends on who issued it. Each is defensible and none is obvious a
     * year later.
     *
     * @var array<int, string>
     */
    public const REASON_REQUIRED = [self::KIND_FORFEITED, self::KIND_ADJUSTED, self::KIND_SUBSTITUTED_BY_BOND];

    protected $table = 'construction_retention_movements';

    protected $fillable = [
        'contract_id', 'payment_certificate_id', 'kind', 'stage', 'amount',
        'basis_gross', 'rate_applied', 'cap_reached', 'due_on', 'released_on',
        'invoice_id', 'certificate_deduction_id', 'security_id', 'reason', 'approved_by',
    ];

    protected $casts = [
        'cap_reached' => 'boolean',
        'due_on' => 'date',
        'released_on' => 'date',
    ];

    protected $attributes = [
        'stage' => self::STAGE_INTERIM,
        'cap_reached' => false,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(PaymentCertificate::class, 'payment_certificate_id');
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(CertificateDeduction::class, 'certificate_deduction_id');
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_HELD);
    }

    public function scopeReleases(Builder $query): Builder
    {
        return $query->whereIn('kind', [self::KIND_RELEASED, self::KIND_FORFEITED, self::KIND_SUBSTITUTED_BY_BOND]);
    }

    /** Releasable and not yet released — what the notification run and the register's default view read. */
    public function scopeDue(Builder $query, ?string $asAt = null): Builder
    {
        return $query->whereNotNull('due_on')
            ->whereNull('released_on')
            ->whereDate('due_on', '<=', $asAt ?? now()->toDateString());
    }

    public function isRelease(): bool
    {
        return (float) $this->amount < 0;
    }
}
