<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Services\WithholdingTaxSummary;
use App\Support\Pdf\Pdf;
use Illuminate\Http\Request;

/**
 * The printable tax summary. Outside the panel, so the permission is checked here:
 * a direct URL never consults canAccess().
 */
class TaxSummaryReportController extends Controller
{
    public function __invoke(Request $request, WithholdingTaxSummary $summaries)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'fiscal_year_id' => 'nullable|integer',
            'month' => 'nullable|string',
            'format' => 'nullable|in:pdf',
        ]);

        $report = $summaries->summary(
            $validated['fiscal_year_id'] ?? null,
            $validated['month'] ?? null,
        );

        if (($validated['format'] ?? null) === 'pdf') {
            return Pdf::view('reports.tax-summary', ['report' => $report, 'pdf' => true])
                ->format('a4')
                ->name('tax-summary-'.($report['month'] ?? 'year').'-'.($report['fiscal_year'] ?? '').'.pdf');
        }

        return view('reports.tax-summary', ['report' => $report, 'pdf' => false]);
    }
}
