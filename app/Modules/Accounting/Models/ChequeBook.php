<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A numbered range of cheques for one account — docs/saas-launch-plan.md §8.6. */
class ChequeBook extends Model
{
    use Auditable;

    protected $fillable = ['company_bank_account_id', 'prefix', 'first_no', 'last_no', 'next_no', 'is_active'];

    protected $casts = [
        'first_no' => 'integer',
        'last_no' => 'integer',
        'next_no' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChequeBook $book): void {
            $book->next_no ??= $book->first_no;
        });
    }

    public function companyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class);
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(Cheque::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function remaining(): int
    {
        return max(0, $this->last_no - $this->next_no + 1);
    }

    public function isExhausted(): bool
    {
        return $this->next_no > $this->last_no;
    }

    /** The printed form of a number: the prefix and the number zero-padded to the book's width. */
    public function format(int $number): string
    {
        return ($this->prefix ?? '').str_pad((string) $number, strlen((string) $this->last_no), '0', STR_PAD_LEFT);
    }

    public function label(): string
    {
        return $this->format($this->first_no).' – '.$this->format($this->last_no);
    }
}
