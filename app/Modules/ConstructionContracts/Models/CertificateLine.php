<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a payment certificate — the G703 continuation sheet, row by row.
 *
 * `docs/construction-management-plan.md` §8.4 maps it column for column:
 *
 * | Column | Here |
 * |---|---|
 * | A, B, C — item, description, scheduled value | `item_no`, `description`, `scheduled_value` |
 * | D — work completed from previous applications | `previous_work_value` |
 * | E — work completed this period | `cumulative_work_value − previous_work_value` |
 * | F — materials presently stored | `cumulative_materials_value` |
 * | G — total completed and stored to date | D + E + F |
 * | H — balance to finish | C − G |
 * | I — retainage | `line_retention` |
 *
 * **`previous_*` is a frozen snapshot of the prior certificate**, not a join to it. Column D then prints without
 * reaching for a certificate that may since have been voided — and a voided certificate is exactly the moment
 * somebody needs to read the one after it.
 *
 * The item number and description are snapshotted for the same reason: the printed sheet has to be reproducible
 * years later, and a line superseded by a variation must not silently change what an issued certificate says.
 */
class CertificateLine extends Model
{
    use Auditable;

    protected $table = 'construction_certificate_lines';

    protected $fillable = [
        'payment_certificate_id', 'contract_item_id', 'item_no', 'description', 'scheduled_value',
        'previous_work_value', 'previous_materials_value', 'cumulative_work_value', 'cumulative_materials_value',
        'line_retention', 'measurement_input', 'cumulative_percent', 'cumulative_quantity',
    ];

    protected $attributes = [
        'scheduled_value' => 0,
        'previous_work_value' => 0,
        'previous_materials_value' => 0,
        'cumulative_work_value' => 0,
        'cumulative_materials_value' => 0,
        'line_retention' => 0,
        'measurement_input' => ProgressClaimLine::INPUT_PERCENT,
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(PaymentCertificate::class, 'payment_certificate_id');
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'contract_item_id');
    }

    /** Column E — this period's work, derived from two cumulative figures. */
    public function workThisPeriod(): float
    {
        return round((float) $this->cumulative_work_value - (float) $this->previous_work_value, 2);
    }

    public function materialsThisPeriod(): float
    {
        return round((float) $this->cumulative_materials_value - (float) $this->previous_materials_value, 2);
    }

    /** Column G — total completed and stored to date. */
    public function totalToDate(): float
    {
        return round((float) $this->cumulative_work_value + (float) $this->cumulative_materials_value, 2);
    }

    /** Column H — balance to finish. Negative means the line is certified beyond its scheduled value. */
    public function balanceToFinish(): float
    {
        return round((float) $this->scheduled_value - $this->totalToDate(), 2);
    }

    /**
     * Percent complete on this line, derived rather than stored.
     *
     * Null on a line with no scheduled value — an omission line, or a heading — because a percentage of nothing
     * is not zero per cent, and printing 0% against an omission reads as work not started.
     */
    public function percentComplete(): ?float
    {
        $scheduled = (float) $this->scheduled_value;

        if ($scheduled == 0.0) {
            return null;
        }

        return round(($this->totalToDate() / $scheduled) * 100, 2);
    }
}
