<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * IBFT bank directory from docs/IMD CODES IBFT.xlsx (IMD/BIN codes).
     * Trailing commas in two source codes cleaned. Idempotent via bank_code.
     */
    public function run()
    {
        $banks = [
            ['bank_code' => '505895', 'bank_name' => 'Advans Pakistan Microfinance Ltd.', 'bank_short_code' => null],
            ['bank_code' => '639530', 'bank_name' => 'AlBaraka Bank', 'bank_short_code' => 'ABPL'],
            ['bank_code' => '589430', 'bank_name' => 'Allied Bank', 'bank_short_code' => 'ABL'],
            ['bank_code' => '581862', 'bank_name' => 'Apna Microfinance Bank', 'bank_short_code' => null],
            ['bank_code' => '603011', 'bank_name' => 'Askari Bank', 'bank_short_code' => 'AKBL'],
            ['bank_code' => '627100', 'bank_name' => 'Bank Alfalah', 'bank_short_code' => 'BAFL'],
            ['bank_code' => '627197', 'bank_name' => 'Bank AL Habib', 'bank_short_code' => 'BAHL'],
            ['bank_code' => '639357', 'bank_name' => 'BankIslami', 'bank_short_code' => 'BIPL'],
            ['bank_code' => '627618', 'bank_name' => 'Bank of Khyber', 'bank_short_code' => 'BOK'],
            ['bank_code' => '623977', 'bank_name' => 'Bank of Punjab', 'bank_short_code' => 'BOP'],
            ['bank_code' => '508117', 'bank_name' => 'Citi Bank', 'bank_short_code' => 'CITI'],
            ['bank_code' => '604786', 'bank_name' => 'Dawood Islamic Bank', 'bank_short_code' => null],
            ['bank_code' => '428273', 'bank_name' => 'Dubai Islamic Bank', 'bank_short_code' => 'DIB'],
            ['bank_code' => '601373', 'bank_name' => 'Faysal Bank', 'bank_short_code' => 'FBL'],
            ['bank_code' => '502841', 'bank_name' => 'FINCA Microfinance Bank', 'bank_short_code' => null],
            ['bank_code' => '628138', 'bank_name' => 'First Women Bank Limited', 'bank_short_code' => 'FWBL'],
            ['bank_code' => '600648', 'bank_name' => 'Habib Bank Limited', 'bank_short_code' => 'HBL'],
            ['bank_code' => '627408', 'bank_name' => 'Habib Metropolitan Bank', 'bank_short_code' => 'HMB'],
            ['bank_code' => '621464', 'bank_name' => 'ICBC', 'bank_short_code' => 'ICBC'],
            ['bank_code' => '603733', 'bank_name' => 'JS Bank Limited', 'bank_short_code' => 'JSBL'],
            ['bank_code' => '628999', 'bank_name' => 'KASB Bank Limited', 'bank_short_code' => 'KASB'],
            ['bank_code' => '589388', 'bank_name' => 'MCB Bank', 'bank_short_code' => 'MCB'],
            ['bank_code' => '507642', 'bank_name' => 'MCB Islamic Banking', 'bank_short_code' => 'MIB'],
            ['bank_code' => '627873', 'bank_name' => 'Meezan Bank Limited', 'bank_short_code' => 'MBL'],
            ['bank_code' => '585953', 'bank_name' => 'Mobilink Microfinance Bank Limited', 'bank_short_code' => null],
            ['bank_code' => '958600', 'bank_name' => 'National Bank of Pakistan', 'bank_short_code' => 'NBP'],
            ['bank_code' => '586010', 'bank_name' => 'NRSP Microfinance Bank Ltd.', 'bank_short_code' => null],
            ['bank_code' => '606101', 'bank_name' => 'Samba Bank', 'bank_short_code' => 'SAMBA'],
            ['bank_code' => '627544', 'bank_name' => 'Silkbank', 'bank_short_code' => 'SILK'],
            ['bank_code' => '505439', 'bank_name' => 'Sindh Bank', 'bank_short_code' => 'SNDB'],
            ['bank_code' => '604889', 'bank_name' => 'SME Bank Limited', 'bank_short_code' => 'SME'],
            ['bank_code' => '786110', 'bank_name' => 'Soneri Bank', 'bank_short_code' => 'SNBL'],
            ['bank_code' => '604781', 'bank_name' => 'Summit Bank', 'bank_short_code' => 'SMBL'],
            ['bank_code' => '639390', 'bank_name' => 'Telenor Bank', 'bank_short_code' => null],
            ['bank_code' => '581886', 'bank_name' => 'U Microfinance Bank', 'bank_short_code' => 'UBANK'],
            ['bank_code' => '588974', 'bank_name' => 'United Bank Limited', 'bank_short_code' => 'UBL'],
        ];

        foreach ($banks as $bank) {
            Bank::updateOrCreate(['bank_code' => $bank['bank_code']], $bank);
        }

        $this->command?->info('Seeded '.count($banks).' banks (IMD codes for IBFT).');
    }
}
