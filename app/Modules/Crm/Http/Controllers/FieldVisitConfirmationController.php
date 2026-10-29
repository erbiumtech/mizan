<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Company;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Services\FieldVisits;
use App\Support\CompanyLetterhead;
use Illuminate\Http\Request;

/** The witness's page — docs/saas-launch-plan.md §8.5. A name and one tap. */
class FieldVisitConfirmationController extends Controller
{
    public function show(Request $request, string $company, int $visit)
    {
        return $this->withTenant($request, $visit, fn (FieldVisit $visit, Company $current) => response()->view('crm.visit-confirm', $this->view($visit, $current)));
    }

    public function store(Request $request, string $company, int $visit, FieldVisits $visits)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        return $this->withTenant($request, $visit, function (FieldVisit $visit, Company $current) use ($visits, $data) {
            $visits->confirm($visit, $data['name']);

            return response()->view('crm.visit-confirm', $this->view($visit->refresh(), $current));
        });
    }

    private function withTenant(Request $request, int $id, callable $then)
    {
        $current = $request->attributes->get('fieldVisitCompany');
        $current->makeCurrent();

        try {
            $visit = FieldVisit::query()->with('visitor')->whereNotNull('checked_out_at')->find($id);

            abort_unless($visit, 404);

            return $then($visit, $current);
        } finally {
            Company::forgetCurrent();
        }
    }

    /** @return array<string, mixed> */
    private function view(FieldVisit $visit, Company $current): array
    {
        return [
            'companyName' => CompanyLetterhead::data()['legal_name'] ?: $current->name,
            'visitorName' => $visit->visitor?->fullName() ?? 'Our representative',
            'visitedOn' => $visit->checked_out_at->format('l d F Y'),
            'confirmed' => $visit->isConfirmed(),
            'confirmedName' => $visit->confirmed_name,
            'action' => request()->fullUrl(),
        ];
    }
}
