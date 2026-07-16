<?php

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    public function run()
    {
        $contacts = [
            ['name' => '4sure AG', 'kind' => 'customer', 'email' => '[scrubbed]', 'address_line_1' => 'Zurich, Switzerland'],
            ['name' => '[scrubbed]', 'kind' => 'customer', 'email' => '[scrubbed]'],
            ['name' => '[scrubbed]', 'kind' => 'supplier', 'email' => '[scrubbed]', 'address_line_1' => 'Karachi'],
            ['name' => '[scrubbed]', 'kind' => 'supplier', 'email' => '[scrubbed]', 'address_line_1' => 'Lahore'],
            ['name' => '[scrubbed]', 'kind' => 'both', 'address_line_1' => 'Islamabad'],
        ];

        foreach ($contacts as $data) {
            Contact::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
