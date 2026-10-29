<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Company;
use App\Support\CompanyLetterhead;
use App\Support\DocumentVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Is this document real?" — docs/saas-launch-plan.md §7.5. Built as plain
 * values with the tenant re-made current for the duration, like the status page.
 */
class VerificationController extends Controller
{
    public function show(Request $request, string $company, string $token, string $kind, int $id, string $hash): Response
    {
        $current = $request->attributes->get('verificationCompany');
        $current->makeCurrent();

        try {
            abort_unless(in_array($kind, DocumentVerification::kinds(), true), 404);

            $result = DocumentVerification::verify($kind, $id, $hash);

            return response()->view('verify.show', [
                'companyName' => CompanyLetterhead::data()['legal_name'] ?: $current->name,
                'kind' => str_replace('-', ' ', $kind),
                'verdict' => $result['verdict'],
                'facts' => $result['facts'],
                'checkedAt' => now()->toDayDateTimeString(),
            ]);
        } finally {
            Company::forgetCurrent();
        }
    }
}
