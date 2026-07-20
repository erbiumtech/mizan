<?php

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
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
