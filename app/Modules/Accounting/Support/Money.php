<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use InvalidArgumentException;

/**
 * Turning a foreign amount into the base currency, and saying so.
 *
 * One place, because the rule is easy to state and easy to get backwards: a rate is
 * base units per one unit of the foreign currency, so base = foreign × rate. Written
 * twice, one of them would eventually divide.
 */
class Money
{
    /**
     * @return array{base: float, rate: float}
     *
     * @throws InvalidArgumentException when there is no rate for that day
     */
    public static function toBase(float $amount, ?string $currencyCode, ?string $on = null, ?float $rate = null): array
    {
        $base = Currency::baseCode();
        $code = strtoupper((string) ($currencyCode ?: $base));

        if ($code === $base) {
            return ['base' => round($amount, 2), 'rate' => 1.0];
        }

        // A rate given explicitly wins: an invoice raised at an agreed rate keeps that
        // rate even if the table later says something else about the day.
        $rate ??= ExchangeRate::for($code, $on);

        if ($rate === null) {
            throw new InvalidArgumentException(
                "No exchange rate for {$code} on or before ".($on ?? 'today')
                .'. Record one before posting an amount in it — a guessed rate is how a book goes quietly wrong.'
            );
        }

        if ($rate <= 0) {
            throw new InvalidArgumentException("The rate for {$code} must be greater than zero.");
        }

        return ['base' => round($amount * $rate, 2), 'rate' => round($rate, 8)];
    }

    /** Formatted with its own symbol and decimals, for anything a person reads. */
    public static function format(float $amount, ?string $currencyCode = null): string
    {
        $code = strtoupper((string) ($currencyCode ?: Currency::baseCode()));
        $currency = Currency::where('code', $code)->first();

        return trim(($currency?->symbol ? $currency->symbol.' ' : $code.' ')
            .number_format($amount, $currency?->decimals ?? 2));
    }
}
