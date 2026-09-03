<?php

namespace Database\Seeders;

use App\Modules\Core\Models\OptionValue;
use App\Support\OptionLists;
use Illuminate\Database\Seeder;

/**
 * What every admin-managed dropdown starts with: the values it had when they were
 * hardcoded in the form.
 *
 * The lists themselves are declared by the module that owns them, so this seeder never
 * has to be edited again — a module adding a list gets it seeded by declaring it. See
 * App\Support\OptionLists.
 *
 * Every declared list is seeded, including those of modules this company has not
 * licensed. Rows of an unlicensed module are hidden from the settings screen and cost
 * nothing until the licence arrives, whereas seeding on licence state would mean a
 * company that buys Construction QHSE in March gets an empty dropdown and no obvious
 * way to know what it should have held.
 *
 * firstOrCreate on (list, value), so re-running tops up what is missing and never
 * renames or re-enables what a company has edited.
 */
class OptionListSeeder extends Seeder
{
    public function run(): void
    {
        foreach (OptionLists::all() as $key => $list) {
            $sort = 0;

            foreach ($list['defaults'] as $value => $label) {
                $sort += 10;

                OptionValue::firstOrCreate(
                    ['list' => $key, 'value' => $value],
                    ['label' => $label, 'sort' => $sort, 'is_active' => true],
                );
            }
        }
    }
}
