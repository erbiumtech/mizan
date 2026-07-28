<?php

namespace Database\Seeders;

use App\Models\FiscalYear;
use Illuminate\Database\Seeder;

class FiscalYearSeeder extends Seeder
{
    public function run()
    {
        FiscalYear::updateOrCreate(
            ['name' => '2025-2026'],
            [
                'start_date' => '2025-07-01',
                'end_date' => '2026-06-30',
                'is_active' => true,
            ]
        );

        FiscalYear::updateOrCreate(
            ['name' => '2026-2027'],
            [
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'is_active' => true,
            ]
        );
    }
}
