<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public, unauthenticated status page for one company.
 *
 * Two rules govern this file:
 *
 * 1. Only whitelisted fields leave the building — project name, environment
 *    kind, status and uptime. Never the URL, username, password or the stored
 *    error text (which carries internal hostnames).
 * 2. The payload is built as plain arrays and rendered with response()->view(),
 *    so the template cannot lazily touch the database after the tenant has been
 *    forgotten by ResolveStatusPageTenant.
 */
class StatusPageController extends Controller
{
    public function show(Request $request, string $company, string $token)
    {
        $tenant = $request->attributes->get('statusPageCompany');
        $days = (int) config('projects.status_page.uptime_days', 30);
        $seconds = (int) config('projects.status_page.cache_seconds', 60);

        // Cached so a public URL can't be turned into a load generator.
        $payload = Cache::remember(
            "status-page:{$tenant->getKey()}",
            $seconds,
            fn (): array => $this->payload($days)
        );

        return response()->view('status.show', [
            'companyName' => $tenant->name ?? $tenant->slug,
            'projects' => $payload,
            'uptimeDays' => $days,
            'generatedAt' => now()->toDayDateTimeString(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function payload(int $days): array
    {
        return Project::query()
            ->whereHas('environments', fn ($query) => $query->where('is_public', true))
            ->with(['environments' => fn ($query) => $query->where('is_public', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => [
                'name' => $project->name,
                'environments' => $project->environments->map(fn (ProjectEnvironment $environment): array => [
                    'label' => $environment->label(),
                    'status' => $environment->health_status ?? ProjectEnvironment::HEALTH_UNKNOWN,
                    'uptime' => $environment->uptimePercent($days),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
