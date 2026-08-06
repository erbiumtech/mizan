<?php

namespace App\Modules\PersonalFinance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the tax estimate needs to know about a person for one tax year, beyond
 * the numbers in their ledger.
 *
 * Filer status is recorded and displayed, not applied. Being on the Active
 * Taxpayers List changes the rates at which tax is *withheld* from you — on bank
 * profit, on property, at source — rather than the liability the income
 * brackets produce. Quietly inflating somebody's estimate because they are a
 * non-filer would be wrong arithmetic dressed up as a feature.
 */
class PersonalTaxProfile extends Model
{
    use BelongsToOwner;

    public const FILER = 'filer';

    public const NON_FILER = 'non_filer';

    protected $fillable = [
        'user_id',
        'fiscal_year_id',
        'filer_status',
        'notes',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isFiler(): bool
    {
        return $this->filer_status === self::FILER;
    }
}
