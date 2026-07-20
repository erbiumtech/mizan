<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Payslip;
use App\Services\SalaryBankExportService;
use Illuminate\Http\Request;

class SalaryBankExportController extends Controller
{
    public function __construct(private SalaryBankExportService $export)
    {
    }

    public function __invoke(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'month' => 'nullable|string',
            'value_date' => 'nullable|date',
            'download' => 'nullable|boolean',
        ]);

        $fiscalYear = FiscalYear::where('is_active', true)->firstOrFail();

        $months = Payslip::where('fiscal_year_id', $fiscalYear->id)
            ->distinct()
            ->pluck('month')
            ->sortBy(fn ($m) => \Carbon\Carbon::parse("{$m} 1, 2000")->month)
            ->values();

        $month = $validated['month'] ?? $months->last() ?? now()->format('F');
        $valueDate = $validated['value_date'] ?? now()->toDateString();

        if ($validated['download'] ?? false) {
            $csv = $this->export->export($month, $fiscalYear, $valueDate);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$this->export->fileName($month, $fiscalYear).'"',
            ]);
        }

        return view('reports.salary-bank-file', [
            'fiscalYear' => $fiscalYear,
            'months' => $months,
            'month' => $month,
            'valueDate' => $valueDate,
            'payments' => $this->export->paymentsForMonth($month, $fiscalYear),
            'pdf' => false,
        ]);
    }
}
