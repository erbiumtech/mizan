<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Filament\Resources\BillingRuns\BillingRunResource;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Billing\Services\MonthlyBillingService;
use App\Support\Pdf\Pdf;
use Illuminate\Http\Request;

/**
 * The month's bill as a page of its own, and as a PDF of that same page.
 *
 * A statement is something you send, print and sit next to the client's own
 * spreadsheet, none of which a modal inside the panel is any good for. Same
 * shape as the accounting reports: one Blade for both, `?format=pdf` for the
 * printed copy, and `download=1` for whether it lands in Downloads or opens.
 */
class BillingStatementController extends Controller
{
    public function __construct(private MonthlyBillingService $billing) {}

    public function __invoke(Request $request, string $company, int $run)
    {
        $validated = $request->validate([
            'format' => 'nullable|in:pdf',
            'download' => 'nullable|boolean',
        ]);

        // Resolved here rather than by route binding: the company is made current
        // by middleware (see ResolveCompanyFromRoute), and a billing run read
        // before that would be read from the wrong database.
        $billingRun = BillingRun::with(['contact', 'invoice', 'fiscalYear'])->findOrFail($run);

        abort_unless($request->user()->can('view', $billingRun), 403);

        $company = $request->attributes->get('company');

        $data = [
            'run' => $billingRun,
            'statement' => $this->billing->statement($billingRun),
            'company' => $company,
            // The list this was opened from. Built from the company in the URL
            // rather than the panel's current tenant: this page is outside the
            // panel and the tab it opened in has no tenant of its own.
            'backUrl' => BillingRunResource::getUrl('index', tenant: $company),
        ];

        if (($validated['format'] ?? null) !== 'pdf') {
            return view('billing.statement', $data + ['pdf' => false]);
        }

        return Pdf::view('billing.statement', $data + ['pdf' => true])
            ->format('a4')
            // Landscape because the employee table is a column per allowance; in
            // portrait the figures wrap and the sheet stops being readable.
            ->landscape()
            ->name($this->filename($billingRun))
            ->inline(! ($validated['download'] ?? false));
    }

    private function filename(BillingRun $run): string
    {
        $client = str($run->contact?->name ?? 'client')->slug();
        $period = str($run->periodLabel())->slug();

        return "bill-{$client}-{$period}.pdf";
    }
}
