<?php

namespace Database\Seeders\Production;

use App\Modules\Invoicing\Models\Contact;
use Illuminate\Database\Seeder;

/**
 * REAL PRODUCTION DATA — kept out of the default `db:seed` run.
 *
 * The seeders in Database\Seeders create dummy data so a fresh install (or a
 * demo, or a developer's machine) never carries real people, salaries or trading
 * partners. The genuine values live here instead, and only run when named
 * explicitly:
 *
 *     php artisan db:seed --class="Database\Seeders\Production\RealContactSeeder"
 *
 * Tenant-scoped: a company must be current, so run this from a context that has
 * made one current (or via `php artisan tenants:artisan`).
 */
class RealContactSeeder extends Seeder
{
    public function run()
    {
        $contacts = [
            ['name' => '4sure AG', 'kind' => 'customer', 'email' => 'billing@4sure.ag', 'address_line_1' => 'Zurich, Switzerland'],
            ['name' => 'Erbium Retail Store', 'kind' => 'customer', 'email' => 'store@erbium.tech'],
            ['name' => 'TechDistributors (Pvt) Ltd', 'kind' => 'supplier', 'email' => 'sales@techdist.pk', 'address_line_1' => 'Karachi'],
            ['name' => 'Office Depot Lahore', 'kind' => 'supplier', 'email' => 'orders@officedepot.pk', 'address_line_1' => 'Lahore'],
            ['name' => 'Metro Cash & Carry', 'kind' => 'both', 'address_line_1' => 'Islamabad'],
        ];

        foreach ($contacts as $data) {
            Contact::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
