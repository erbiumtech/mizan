<?php

namespace App\Modules\ConstructionField\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DailyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The jobs a site diary line written offline may name — docs/desktop-mobile-plan.md O2.
 *
 * The panel used to put this list into every page it rendered, which cost every page a query whether or
 * not anyone would ever go offline (PanelPerformanceTest's budgets caught it). Now the panel's outbox asks
 * for it here, after the page has loaded and at most every few minutes, and keeps it on the device for the
 * offline page. Same list as before: live jobs, code and name, the first 200.
 */
class SiteDiaryJobsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('create', DailyLog::class), 403);

        $jobs = Job::query()
            ->live()
            ->orderBy('code')
            ->limit(200)
            ->get(['id', 'code', 'name'])
            ->map(fn (Job $job): array => ['id' => (int) $job->getKey(), 'label' => trim($job->code.' — '.$job->name)])
            ->all();

        return response()->json(['jobs' => $jobs]);
    }
}
