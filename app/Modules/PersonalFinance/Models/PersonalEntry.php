<?php

namespace App\Modules\PersonalFinance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One transaction in a person's own books, as a set of balanced lines.
 *
 * There is no draft/approval workflow here and there cannot usefully be one:
 * JournalEntryService::approve() refuses an entry whose approver is its creator
 * (segregation of duties), so a person keeping their own books could never
 * approve anything. Entries are simply recorded, the way
 * RegisterEntryService::bookRow() records a register row.
 */
class PersonalEntry extends Model
{
    use BelongsToOwner;

    protected $fillable = [
        'user_id',
        'date',
        'description',
        'fiscal_year_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PersonalEntryLine::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return bccomp(
            number_format($this->totalDebit(), 2, '.', ''),
            number_format($this->totalCredit(), 2, '.', ''),
            2
        ) === 0;
    }
}
