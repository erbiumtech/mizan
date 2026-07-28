<?php

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;

/**
 * Dummy trading partners. The real customers and suppliers live in
 * Database\Seeders\Production\RealContactSeeder, outside the default seed run.
 *
 * InvoiceSeeder and InventorySeeder look these up by name, so the names here
 * and the ones they reference have to stay in step.
 */
class ContactSeeder extends Seeder
{
    /** Names other seeders reference, kept as constants so they cannot drift. */
    public const CUSTOMER_PRIMARY = 'Northwind Trading GmbH';

    public const CUSTOMER_SECONDARY = 'Lakeside Retail Outlet';

    public const SUPPLIER_HARDWARE = 'Summit Hardware Supply (Pvt) Ltd';

    public const SUPPLIER_STATIONERY = 'Cedar Office Supplies';

    public function run()
    {
        $contacts = [
            ['name' => self::CUSTOMER_PRIMARY, 'kind' => 'customer', 'email' => 'billing@example.test', 'address_line_1' => 'Berlin, Germany'],
            ['name' => self::CUSTOMER_SECONDARY, 'kind' => 'customer', 'email' => 'orders@example.test'],
            ['name' => self::SUPPLIER_HARDWARE, 'kind' => 'supplier', 'email' => 'sales@example.test', 'address_line_1' => 'Karachi'],
            ['name' => self::SUPPLIER_STATIONERY, 'kind' => 'supplier', 'email' => 'supplies@example.test', 'address_line_1' => 'Lahore'],
            ['name' => 'Riverside Wholesale', 'kind' => 'both', 'address_line_1' => 'Islamabad'],
        ];

        foreach ($contacts as $data) {
            Contact::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
