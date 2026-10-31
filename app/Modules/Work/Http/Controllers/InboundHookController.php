<?php

namespace App\Modules\Work\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Modules\Work\Services\IntegrationEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * `POST /hooks/{provider}/{company}/{connection}` — docs/project-management-plan.md §8.3.
 *
 * Verified synchronously, stored, queued. A bad signature is a 401 that touches no queue; a
 * redelivery is a 200 that writes nothing; anything else that goes wrong is reported and answered
 * 500, because a provider that sees a 500 redelivers and one that sees a 200 does not.
 */
class InboundHookController
{
    public function store(Request $request, IntegrationEvents $events): JsonResponse
    {
        $company = $request->attributes->get('hookCompany');
        $connection = $request->attributes->get('hookConnection');

        // Re-made current: the middleware forgets the tenant in its `finally`.
        $company->makeCurrent();

        try {
            $event = $events->receive($connection, $request);

            return response()->json([
                'accepted' => true,
                'duplicate' => $event === null,
            ], 202);
        } catch (RuntimeException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500;

            return response()->json(['accepted' => false, 'reason' => $e->getMessage()], $status);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['accepted' => false], 500);
        } finally {
            Company::forgetCurrent();
        }
    }
}
