<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A currency the company deals in, and whether it is the one the books are kept in.
 */
class Currency extends Model
{
    use Auditable;

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'is_base', 'is_active'];

    protected $casts = [
        'decimals' => 'integer',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'decimals' => 2,
        'is_base' => false,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $currency): void {
            $currency->code = strtoupper((string) $currency->code);
        });

        /*
         * Exactly one base currency, and it never changes once anything is posted.
         *
         * The base currency is what every stored debit and credit means. Changing it
         * would not restate those figures, it would silently reinterpret them: a
         * balance of 6,140,253 would stop meaning rupees and start meaning euros
         * without a single row changing.
         */
        static::saved(function (self $currency): void {
            if (! $currency->is_base) {
                return;
            }

            static::whereKey($currency->getKey())->update(['is_base' => true]);
            static::whereKeyNot($currency->getKey())->where('is_base', true)->update(['is_base' => false]);
        });

        static::updating(function (self $currency): void {
            if (! $currency->isDirty('is_base') || $currency->is_base) {
                return;
            }

            if (JournalEntryLine::whereNotNull('currency_code')->exists() || JournalEntryLine::exists()) {
                throw new InvalidArgumentException(
                    "{$currency->code} cannot stop being the base currency: every amount already posted is "
                    .'recorded in it, and changing it would reinterpret those figures rather than restate them.'
                );
            }
        });
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'currency_code', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function base(): ?self
    {
        return static::where('is_base', true)->first();
    }

    public static function baseCode(): string
    {
        return static::base()?->code ?? 'PKR';
    }

    public function isBase(): bool
    {
        return (bool) $this->is_base;
    }

    /**
     * The rate to the base currency on a date.
     *
     * The most recent rate on or before that date, not the latest one: an invoice
     * issued in July is worth what it was worth in July, and re-translating it every
     * time somebody adds a rate would rewrite history.
     */
    public function rateOn(?string $date = null): ?float
    {
        if ($this->isBase()) {
            return 1.0;
        }

        return ExchangeRate::for($this->code, $date);
    }
}
