<?php

namespace App\Nova\Fields;

use Brick\Money\Money;
use Laravel\Nova\Fields\Currency as BaseCurrency;
use Symfony\Polyfill\Intl\Icu\Currencies;

/**
 * Nova Currency that tolerates values with more decimal places than the
 * currency scale (float artifacts, 4-dp decimals, computed sums). brick/money
 * refuses to round implicitly (RoundingNecessaryException) — round here.
 */
class Currency extends BaseCurrency
{
    public function toMoneyInstance(mixed $value, ?string $currency = null): Money
    {
        if (! $this->minorUnits && is_numeric($value)) {
            $scale = Currencies::getFractionDigits($currency ?? ($this->currency ?? $this->defaultCurrency));
            $value = number_format((float) $value, $scale, '.', '');
        }

        return parent::toMoneyInstance($value, $currency);
    }
}
