<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FiscalYear;

class FiscalYearSeeder extends Seeder
{
    public function run()
    {
        FiscalYear::updateOrCreate(
            ['name' => '2026-2027'],
            ['is_active' => true]
        );
    }
}
