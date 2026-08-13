<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was offered.
 *
 * `components` is JSON on purpose: at offer time the allowances are a proposal, not rows
 * anybody can point at. The EmployeeSetting and its component rows are created from them
 * at acceptance, in one transaction with the Employee — see HireService.
 */
class Offer extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['joining_date'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'application_id', 'salary', 'components', 'joining_date', 'status',
        'issued_at', 'responded_at', 'decline_reason',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'components' => 'array',
        'joining_date' => 'date',
        'issued_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ISSUED], true);
    }
}
