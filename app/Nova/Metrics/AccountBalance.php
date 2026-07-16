<?php

namespace App\Nova\Metrics;

use App\Models\Account;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

/**
 * Posted ledger balance of one or more accounts, summed by code —
 * one metric class reused for Cash/Bank, A/R, A/P, etc.
 */
class AccountBalance extends Value
{
    public function __construct(
        public string $label,
        public array $codes,
    ) {
        parent::__construct();
        $this->name = $label;
    }

    public function calculate(NovaRequest $request): ValueResult
    {
        $balance = (float) Account::whereIn('code', $this->codes)->sum('balance');

        return $this->result(round($balance, 2))
            ->currency('PKR')
            ->allowZeroResult();
    }

    public function ranges(): array
    {
        return [];
    }

    public function uriKey(): string
    {
        return 'account-balance-'.implode('-', $this->codes);
    }
}
