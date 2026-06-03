<?php

namespace Database\Seeders;

use App\Traits\ReferenceTrait;
use Illuminate\Database\Seeder;

class OpenPayrollSeeder extends Seeder
{
    use ReferenceTrait;

    /**
     * Run the database seeds.
     */
    public function run()
    {
        $this->seedReferences();
    }
}
