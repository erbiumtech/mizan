<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Cheque;
use App\Support\Reporting\ReportShapes;

/** The register behind the ChequeRegister page — docs/saas-launch-plan.md §8.6. */
class ChequeReports
{
    use ReportShapes;

    public function register(string $asOf): array
    {
        $cheques = Cheque::query()
            ->with(['companyBankAccount', 'payment'])
            ->whereDate('cheque_date', '<=', $asOf)
            ->orderBy('company_bank_account_id')
            ->orderBy('cheque_no')
            ->get();

        $outstanding = $cheques->where('status', Cheque::STATUS_PRINTED);
        $cleared = $cheques->where('status', Cheque::STATUS_CLEARED);

        $rows = $cheques->map(fn (Cheque $c): array => [
            $c->cheque_no,
            $c->companyBankAccount?->title ?? '',
            $c->cheque_date->format('d-m-Y'),
            $c->payee_name,
            $c->status === Cheque::STATUS_VOID ? '' : number_format((float) $c->amount, 2),
            ucfirst($c->status).($c->void_reason ? ' — '.$c->void_reason : ''),
        ])->all();

        return $this->table(
            'ChequeRegister',
            'Cheque Register',
            $this->subtitle('to '.$asOf),
            ['Cheque no', 'Account', 'Date', 'Payee', 'Amount', 'Status'],
            '8rem 12rem 7rem minmax(0, 1fr) 9rem 14rem',
            [4],
            $rows,
            [
                ['label' => 'UNPRESENTED', 'value' => round((float) $outstanding->sum('amount'), 2), 'accent' => true],
                ['label' => 'CLEARED', 'value' => round((float) $cleared->sum('amount'), 2), 'accent' => false],
            ],
            mb_strtoupper(sprintf(
                '%d printed · %d outstanding · %d void',
                $cheques->count(),
                $outstanding->count(),
                $cheques->where('status', Cheque::STATUS_VOID)->count(),
            )),
            null,
            'No cheques printed to this date.',
        );
    }
}
