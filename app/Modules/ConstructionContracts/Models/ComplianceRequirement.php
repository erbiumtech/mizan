<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a subcontractor has to produce, and what its absence stops — `docs/construction-management-plan.md` §12.
 *
 * **A null `contract_id` is a company-level template**: required of every subcontract unless one overrides it. Without
 * the template every contract restates the same six insurance requirements, and the seventh is forgotten on the
 * contract where it mattered.
 *
 * **`blocks` defaults to `certification`**, which is §12's argument rather than a convenience: blocking at *payment*
 * leaves "an approved payable in the ledger that finance cannot pay", and that is a worse state than a refusal because
 * the liability already exists and the stuck payment has nobody's name on it.
 *
 * `grace_days` exists because a renewal in the post is ordinary. A register that stopped a certificate on the day a
 * policy lapsed would be overridden every month until somebody set the grace period they should have set at the start —
 * and an override that happens every month is not a control.
 */
class ComplianceRequirement extends Model
{
    use Auditable;

    public const BLOCKS_NONE = 'none';

    public const BLOCKS_CERTIFICATION = 'certification';

    public const BLOCKS_PAYMENT = 'payment';

    public const BLOCKS_BOTH = 'both';

    /** The kinds that stop a certificate when they block — certification or both. */
    public const CERTIFICATION_BLOCKERS = [self::BLOCKS_CERTIFICATION, self::BLOCKS_BOTH];

    protected $table = 'construction_compliance_requirements';

    protected $fillable = [
        'contract_id', 'kind', 'blocks', 'grace_days', 'minimum_cover', 'notes',
    ];

    protected $casts = [
        'grace_days' => 'integer',
    ];

    protected $attributes = [
        'blocks' => self::BLOCKS_CERTIFICATION,
        'grace_days' => 0,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** The company-level template: what every subcontract needs unless it says otherwise. */
    public function scopeTemplate(Builder $query): Builder
    {
        return $query->whereNull('contract_id');
    }

    public function isTemplate(): bool
    {
        return $this->contract_id === null;
    }

    public function blocksCertification(): bool
    {
        return in_array($this->blocks, self::CERTIFICATION_BLOCKERS, true);
    }

    public function label(): string
    {
        return str_replace('_', ' ', ucfirst($this->kind));
    }
}
