<?php

namespace App\Modules\Support;

use App\Modules\Support\Filament\Pages\SlaBreaches;
use App\Modules\Support\Filament\Pages\SlaPerformance;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Modules\Support\Models\TicketReply;
use App\Modules\Support\Policies\TicketCategoryPolicy;
use App\Modules\Support\Policies\TicketPolicy;
use App\Modules\Support\Policies\TicketReplyPolicy;
use App\Modules\Support\Support\SupportReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        TicketCategory::class => TicketCategoryPolicy::class,
        Ticket::class => TicketPolicy::class,
        TicketReply::class => TicketReplyPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerReports();
    }

    /**
     * The SLA report and its exception list — `docs/reports-expansion-plan.md` Phase 1.3.
     *
     * Registered unconditionally, whatever the company has licensed: each page gates itself on
     * `moduleIsAvailable()` and `Reports::sections()` filters through `canAccess()`, so a company without
     * the support module sees no *Operations* heading at all rather than two reports that fail when
     * opened. Same terms as `SupportPlugin`, and for the same reason.
     *
     * Filed under *Operations* rather than a section of its own. A helpdesk SLA is read beside the other
     * things that are running rather than beside the accounts, and Phase 3 fills the same section with
     * attendance, quotations and campaigns.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'Operations',
            SlaPerformance::class,
            'What proportion of this month\'s tickets met their response and resolution commitments.',
        );
        ReportCatalogue::register(
            'Operations',
            SlaBreaches::class,
            'Open tickets that have missed a commitment, or whose time is already up.',
        );

        ReportRenderers::register('SlaPerformance', fn (string $asOf): array => app(SupportReports::class)->slaPerformance($asOf));
        ReportRenderers::register('SlaBreaches', fn (string $asOf): array => app(SupportReports::class)->slaBreaches($asOf));
    }
}
