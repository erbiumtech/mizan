<?php

namespace App\Support\Reporting;

/**
 * No pane: every report opens on its own page.
 *
 * Bound as the default so the Reports hub works with no accounting module installed. It draws none of
 * Accounting's own reports, which the explorer already knows how to render — a selected report the pane
 * cannot draw is offered as a link to its own screen, and that path is covered by ReportsScreenTest rather
 * than being new.
 *
 * **It does still draw a report whose module registered a renderer**, and the distinction is the whole
 * point of this class now. `ReportRenderers` is host-level: CRM's five, Payroll's three and Invoicing's
 * three do not need Accounting to render, only to be drawn *beside* an Accounting report. Refusing them
 * here meant a company with CRM and no accounting saw five reports in the hub that the pane would never
 * draw — listed, and openable only one page at a time. That is the same coupling
 * `ReportPane::supports()` shed in reports-expansion-plan.md Phase 1.2, one class along.
 *
 * What stays refused is everything else: no `KINDS`, no drill-through, no filter options. Those are
 * Accounting's, and a company without it has no accounts to offer.
 */
class NoReportPane implements ReportPaneRenderer
{
    public function supportsReport(?string $key): bool
    {
        return ReportRenderers::has($key);
    }

    public function asksFor(?string $key): array
    {
        return [];
    }

    public function for(string $key, string $asOf, bool|string $comparison = true, array $asked = []): ?array
    {
        return ReportRenderers::render($key, $asOf, $comparison, $asked);
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
