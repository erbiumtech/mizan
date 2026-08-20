<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who was on site, by trade and by company — §16.1.
 *
 * **This is the row §17's safety rate cannot exist without.** An incident rate is incidents per so many hours worked,
 * and the hours are the denominator nobody has: without a manpower return there is no exposure figure, and §17.6 is
 * explicit that a safety page must refuse to print a rate it cannot compute rather than print a flattering one.
 *
 * `trade_id` is **unconstrained** and `trade_label` sits beside it: `construction_trades` belongs to
 * `construction_costing`, and `construction_field` requires only `construction` (§18). A diary on a job with no cost
 * module still has to say what the men were doing, so the label carries it and the picker is simply absent.
 */
class DailyLogManpower extends Model
{
    use Auditable;

    protected $table = 'construction_daily_log_manpower';

    protected $fillable = [
        'daily_log_id', 'trade_id', 'trade_label', 'contact_id', 'company_label',
        'headcount', 'hours', 'overtime_hours', 'notes',
    ];

    protected $casts = [
        'headcount' => 'integer',
    ];

    protected $attributes = [
        'headcount' => 0,
        'hours' => 0,
        'overtime_hours' => 0,
    ];

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class, 'daily_log_id');
    }

    /** Who supplied them, where Invoicing is present to name them. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function totalHours(): float
    {
        return round((float) $this->hours + (float) $this->overtime_hours, 2);
    }

    /** The trade in words: the register's name where the module is there, the written label where it is not. */
    public function tradeName(): ?string
    {
        return $this->trade_label ?: null;
    }

    public function companyName(): ?string
    {
        return $this->contact?->name ?? $this->company_label;
    }
}
