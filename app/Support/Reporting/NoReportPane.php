<?php

namespace App\Support\Reporting;

/**
 * No pane: every report opens on its own page.
 *
 * Bound as the default so the Reports hub works with no accounting module installed. It supports nothing,
 * which the explorer already knows how to render — a selected report the pane cannot draw is offered as a
 * link to its own screen, and that path is covered by ReportsScreenTest rather than being new.
 */
class NoReportPane implements ReportPaneRenderer
{
    public function supportsReport(?string $key): bool
    {
        return false;
    }

    public function asksFor(?string $key): array
    {
        return [];
    }

    public function for(string $key, string $asOf, bool $comparison = true, array $asked = []): ?array
    {
        return null;
    }

    public function options(string $key, ?string $ask = null, ?string $asOf = null): array
    {
        return [];
    }

    public function drillable(): array
    {
        return [];
    }

    public function drillTarget(string $code): int|string|null
    {
        return null;
    }
}
