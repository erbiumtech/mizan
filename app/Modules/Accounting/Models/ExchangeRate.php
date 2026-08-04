<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Support\Carbon;

/**
 * What one unit of a currency was worth in the base currency, on a day.
 */
class ExchangeRate extends Model
{
    use Auditable;

    protected $fillable = ['currency_code', 'effective_on', 'rate', 'source'];

    protected $casts = [
        'effective_on' => 'date',
        'rate' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rate): void {
            $rate->currency_code = strtoupper((string) $rate->currency_code);

            if ((float) $rate->rate <= 0) {
                throw new \InvalidArgumentException('A rate must be greater than zero.');
            }
        });
    }

    /**
     * The rate in force for a currency on a date.
     *
     * The most recent one on or before the date. Null when there is none — which is a
     * real answer and has to be handled, because posting a foreign amount at a guessed
     * rate is how a book becomes quietly wrong.
     */
    public static function for(string $currencyCode, ?string $date = null): ?float
    {
        $on = Carbon::parse($date ?? now())->toDateString();

        $rate = static::where('currency_code', strtoupper($currencyCode))
            ->whereDate('effective_on', '<=', $on)
            ->orderByDesc('effective_on')
            ->value('rate');

        return $rate === null ? null : (float) $rate;
    }
}
