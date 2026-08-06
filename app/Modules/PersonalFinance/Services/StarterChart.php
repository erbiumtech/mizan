<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Support\TenantTransaction;

/**
 * The accounts a person starts with, so the first thing they see is not an empty
 * table and a blank form asking them to invent a chart of accounts.
 *
 * Per user rather than per company, which is why this is an action they trigger
 * rather than something TenantBaselineSeeder does — a company has many people
 * and each needs their own copy.
 *
 * Nothing here is mandatory: every account can be renamed, closed or deleted,
 * and anyone who wants a different structure can build one. Education is on the
 * list by name because it was asked for specifically.
 */
class StarterChart
{
    /**
     * code, name, type, tax regime (income only).
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3?: string}>
     */
    private const ACCOUNTS = [
        // Where money sits.
        ['1000', 'Cash', PersonalAccount::TYPE_ASSET],
        ['1100', 'Bank account', PersonalAccount::TYPE_ASSET],

        // What is owed.
        ['2000', 'Loans', PersonalAccount::TYPE_LIABILITY],
        ['2100', 'Credit card', PersonalAccount::TYPE_LIABILITY],

        // What comes in. Salary is tagged salaried so the tax estimate works
        // out of the box for the commonest case.
        ['4000', 'Salary', PersonalAccount::TYPE_INCOME, TaxSchedule::REGIME_SALARIED],
        ['4100', 'Business income', PersonalAccount::TYPE_INCOME, TaxSchedule::REGIME_BUSINESS],
        ['4200', 'Rental income', PersonalAccount::TYPE_INCOME, TaxSchedule::REGIME_RENTAL],
        ['4900', 'Other income', PersonalAccount::TYPE_INCOME],

        // What goes out.
        ['5100', 'Food & groceries', PersonalAccount::TYPE_EXPENSE],
        ['5200', 'Rent', PersonalAccount::TYPE_EXPENSE],
        ['5300', 'Education', PersonalAccount::TYPE_EXPENSE],
        ['5400', 'Utilities', PersonalAccount::TYPE_EXPENSE],
        ['5500', 'Transport', PersonalAccount::TYPE_EXPENSE],
        ['5600', 'Medical', PersonalAccount::TYPE_EXPENSE],
        ['5900', 'Other expenses', PersonalAccount::TYPE_EXPENSE],
    ];

    /**
     * Create whatever the signed-in person is missing, and return how many were
     * added.
     *
     * Safe to run twice: it skips codes they already have, so somebody who
     * deleted "Rent" on purpose does not get it back, and somebody who ran it
     * before does not get duplicates.
     */
    public function createFor(): int
    {
        return TenantTransaction::run(function (): int {
            $existing = PersonalAccount::pluck('code')->all();
            $created = 0;

            foreach (self::ACCOUNTS as $account) {
                [$code, $name, $type] = $account;

                if (in_array($code, $existing, true)) {
                    continue;
                }

                PersonalAccount::create([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'tax_regime' => $account[3] ?? null,
                    'opening_balance' => 0,
                    'is_active' => true,
                ]);

                $created++;
            }

            return $created;
        });
    }
}
