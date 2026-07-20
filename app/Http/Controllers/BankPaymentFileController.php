<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\TransactionType;
use App\Services\BankPaymentExportService;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class BankPaymentFileController extends Controller
{
    public function __construct(
        private BankPaymentExportService $export,
        private PaymentService $payments,
    ) {
    }

    public function __invoke(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'month' => 'nullable|string',
            'type' => 'nullable|string|exists:transaction_types,code',
            'value_date' => 'nullable|date',
            'download' => 'nullable|boolean',
        ]);

        $fiscalYear = FiscalYear::where('is_active', true)->firstOrFail();

        $months = \App\Models\Payslip::where('fiscal_year_id', $fiscalYear->id)
            ->distinct()->pluck('month')
            ->sortBy(fn ($m) => \Carbon\Carbon::parse("{$m} 1, 2000")->month)
            ->values();

        $month = $validated['month'] ?? $months->last() ?? now()->format('F');
        $typeCode = $validated['type'] ?? null;
        $valueDate = $validated['value_date'] ?? now()->toDateString();

        // Salary payments come from payslips — sync drafts for the month.
        if (! $typeCode || $typeCode === 'salary') {
            $this->payments->generateSalaryPayments($month, $fiscalYear);
        }

        $query = Payment::with(['payable', 'transactionType', 'companyBankAccount.bank'])
            ->whereIn('status', [Payment::STATUS_DRAFT, Payment::STATUS_APPROVED])
            ->when($typeCode, fn ($q) => $q->whereHas('transactionType', fn ($t) => $t->where('code', $typeCode)))
            ->orderBy('transaction_type_id')
            ->orderBy('id');

        $rows = $query->get();

        if ($validated['download'] ?? false) {
            $csv = $this->export->exportPayments($rows, $valueDate);
            $this->payments->markExported($rows);

            $typeName = $typeCode ? TransactionType::byCode($typeCode)?->name : null;

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$this->export->paymentFileName($typeName).'"',
            ]);
        }

        return view('reports.bank-payment-file', [
            'fiscalYear' => $fiscalYear,
            'months' => $months,
            'month' => $month,
            'types' => TransactionType::where('is_active', true)->orderBy('name')->get(),
            'typeCode' => $typeCode,
            'valueDate' => $valueDate,
            'rows' => $rows,
            'pdf' => false,
        ]);
    }
}
