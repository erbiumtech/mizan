<?php

namespace Database\Seeders;

use App\Modules\Crm\Models\LeadSource;
use Illuminate\Database\Seeder;

/**
 * The lead sources a company starts with.
 *
 * Deliberately generic and deliberately short. These are the channels almost every
 * business here has, and a company adds its own — "Expo 2026", "Chamber of Commerce"
 * — as it goes.
 *
 * `web` is seeded even though nothing writes to it yet: the lead-capture endpoint of
 * phase 3.5 creates leads with this source, and the row existing first means that
 * endpoint does not have to create reference data on the fly from an unauthenticated
 * request. That is the one exception here to "no column that never fills" — a *row*
 * a person can also select is not a column nothing writes to.
 *
 * firstOrCreate on `name`, so re-running adds what is missing and never renames what
 * a company has edited.
 */
class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['name' => 'Referral', 'sort' => 10],
            ['name' => 'Website', 'sort' => 20],
            ['name' => 'Cold outreach', 'sort' => 30],
            ['name' => 'Existing customer', 'sort' => 40],
            ['name' => 'Event or expo', 'sort' => 50],
            ['name' => 'Social media', 'sort' => 60],
            // Reserved for the phase 3.5 capture endpoint, which will look it up by
            // name rather than create it.
            ['name' => 'Web form', 'sort' => 70],
            ['name' => 'Other', 'sort' => 999],
        ];

        foreach ($sources as $source) {
            LeadSource::firstOrCreate(['name' => $source['name']], $source);
        }
    }
}
