<?php

namespace App\Modules\Advances\Models;

use App\Models\TenantModel as Model;
use App\Modules\Payroll\Models\Payslip;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instalment taken back, almost always by a payslip.
 *
 * `payslip_id` is unique per advance so a payslip that is edited and re-saved —
 * which payroll does routinely, recalculating as it goes — cannot recover the
 * same instalment twice. A recovery entered by hand (cash repaid directly) has
 * no payslip and carries a note instead.
 */
class AdvanceRecovery extends Model
{
    use Auditable;

    protected $fillable = ['advance_id', 'payslip_id', 'amount', 'recovered_on', 'note'];

    protected $casts = [
        'amount' => 'decimal:2',
        'recovered_on' => 'date',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
