<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\FinancialReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private FinancialReportService $reports) {}

    /**
     * GET /api/reports/trial-balance?as_of=YYYY-MM-DD&fiscal_year_id=
     */
    public function trialBalance(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'as_of' => 'nullable|date',
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
        ]);

        return response()->json([
            'data' => $this->reports->trialBalance(
                $validated['as_of'] ?? null,
                $validated['fiscal_year_id'] ?? null
            ),
        ]);
    }

    /**
     * GET /api/reports/profit-and-loss?from=YYYY-MM-DD&to=YYYY-MM-DD&fiscal_year_id=
     */
    public function profitAndLoss(Request $request)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
        ]);

        return response()->json([
            'data' => $this->reports->profitAndLoss(
                $validated['from'] ?? null,
                $validated['to'] ?? null,
                $validated['fiscal_year_id'] ?? null
            ),
        ]);
    }
}
