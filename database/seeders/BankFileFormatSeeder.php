<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\BankFileFormat;
use Illuminate\Database\Seeder;

/** The shipped format, as a row — docs/saas-launch-plan.md §14.1 — so the list shows what a company has. */
class BankFileFormatSeeder extends Seeder
{
    public function run(): void
    {
        if (BankFileFormat::query()->where('kind', BankFileFormat::KIND_IPAYMENTS)->exists()) {
            return;
        }

        BankFileFormat::create(['name' => 'Standard Chartered iPayments', 'kind' => BankFileFormat::KIND_IPAYMENTS, 'is_active' => true]);
    }
}
