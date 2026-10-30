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
        // The rating is optional — docs/saas-launch-plan.md §14.10. A required one is how
        // a confirmation rate falls: the page is asking a favour of somebody who was not
        // expecting to be asked for anything.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_comment' => ['nullable', 'string', 'max:190'],
        ]);

        return $this->withTenant($request, $visit, function (FieldVisit $visit, Company $current) use ($visits, $data) {
            $visits->confirm($visit, $data['name'], $data['rating'] ?? null, $data['rating_comment'] ?? null);

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
            // docs/saas-launch-plan.md §14.10.
            'ratings' => FieldVisit::RATINGS,
            'rating' => $visit->ratingLabel(),
            'action' => request()->fullUrl(),
        ];
    }
}
