<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TransactionType;
use Illuminate\Database\Seeder;

/**
 * Spending categories for a personal account, mapped to the personal chart.
 *
 * A separate seeder from TransactionTypeSeeder rather than a reuse of it, and
 * the reason is a trap rather than tidiness: the business types are keyed to the
 * business chart's account codes, and those codes mean different things here.
 * 5700 is Office Rent in a company and Household & Maintenance in a household;
 * 5600 is Food in a company and Medical here. Running the business seeder
 * against a personal chart would post rent to maintenance and groceries to
 * medical — silently, with no error, producing books that look fine and are
 * wrong.
 *
 * Codes are resolved by looking the account up rather than by hardcoding an id,
 * so a personal account whose owner has renamed or removed a category simply
 * gets no type for it instead of a type pointing at nothing.
 */
class PersonalTransactionTypeSeeder extends Seeder
{
    /** name, slug, personal chart account code. */
    private const TYPES = [
        ['Rent', 'rent', '5200'],
        ['Food & Groceries', 'food', '5100'],
        ['Education', 'education', '5300'],
        ['Domestic Staff Wages', 'domestic-staff', '5350'],
        ['Utilities', 'utilities', '5400'],
        ['Transport & Fuel', 'transport', '5500'],
        ['Medical', 'medical', '5600'],
        ['Household & Maintenance', 'household', '5700'],
        ['Family & Gifts', 'family', '5800'],
        ['Other', 'other', '5900'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as [$name, $code, $accountCode]) {
            $accountId = Account::where('code', $accountCode)->value('id');

            if ($accountId === null) {
                // The category was renamed away or never seeded. A type with no
                // account cannot be booked against (PettyCashService refuses
                // one), so creating it would only offer a dead option.
                continue;
            }

            TransactionType::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_id' => $accountId,
                    'is_active' => true,
                ],
            );
        }
    }
}
