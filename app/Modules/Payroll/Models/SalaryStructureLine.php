<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureLine extends Model
{
    public const MODE_SHARE = 'share';

    public const MODE_FIXED = 'fixed';

    public const MODE_BALANCE = 'balance';

    public const MODES = [
        self::MODE_SHARE => '% of gross',
        self::MODE_FIXED => 'Fixed amount',
        self::MODE_BALANCE => 'The balance of gross',
    ];

    protected $fillable = ['salary_structure_id', 'pay_component_id', 'mode', 'value', 'sort'];

    protected $casts = ['value' => 'decimal:2', 'sort' => 'integer'];

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
