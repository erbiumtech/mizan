<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Support\Pdf\Pdf;
use Illuminate\Http\Request;

class ReportPageController extends Controller
{
    public function __construct(private FinancialReportService $reports) {}

    public function balanceSheet(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'as_of' => 'nullable|date',
            'format' => 'nullable|in:pdf',
        ]);

        $report = $this->reports->balanceSheet($validated['as_of'] ?? null);

        if (($validated['format'] ?? null) === 'pdf') {
            return Pdf::view('reports.balance-sheet', ['report' => $report, 'pdf' => true])
                ->format('a4')
                ->name('balance-sheet-'.$report['as_of'].'.pdf');
        }

        return view('reports.balance-sheet', ['report' => $report, 'pdf' => false]);
    }

    public function cashFlow(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'format' => 'nullable|in:pdf',
        ]);

        $report = $this->reports->cashFlow($validated['from'] ?? null, $validated['to'] ?? null);

        if (($validated['format'] ?? null) === 'pdf') {
            return Pdf::view('reports.cash-flow', ['report' => $report, 'pdf' => true])
                ->format('a4')
                ->name('cash-flow-'.($report['from'] ?? 'opening').'-to-'.$report['to'].'.pdf');
        }

        return view('reports.cash-flow', ['report' => $report, 'pdf' => false]);
    }

    public function trialBalance(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'as_of' => 'nullable|date',
            'format' => 'nullable|in:pdf',
        ]);

        $report = $this->reports->trialBalance($validated['as_of'] ?? null);

        if (($validated['format'] ?? null) === 'pdf') {
            return Pdf::view('reports.trial-balance', ['report' => $report, 'pdf' => true])
                ->format('a4')
                ->name('trial-balance-'.$report['as_of'].'.pdf');
        }

        return view('reports.trial-balance', ['report' => $report, 'pdf' => false]);
    }

    public function profitAndLoss(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'format' => 'nullable|in:pdf',
        ]);

        $report = $this->reports->profitAndLoss(
            $validated['from'] ?? null,
            $validated['to'] ?? null
        );

        if (($validated['format'] ?? null) === 'pdf') {
            return Pdf::view('reports.profit-and-loss', ['report' => $report, 'pdf' => true])
                ->format('a4')
                ->name('profit-and-loss-'.$report['to'].'.pdf');
        }

        return view('reports.profit-and-loss', ['report' => $report, 'pdf' => false]);
    }
}
