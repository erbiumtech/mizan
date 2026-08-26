<?php

namespace App\Support\Reporting;

use App\Filament\Concerns\BelongsToModule;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * A report page for a module that renders its own report — `docs/reports-expansion-plan.md` Phase 1.2.
 *
 * The plan costs a report at "a page class, one line in the catalogue, one line in `KINDS`, and one
 * adapter method". Phase 1.2 is the first to add five at once, and the page class was the only part of
 * that four which did not scale: `TaxSummary` and `GeneralLedger` are ~110 lines each, and five copies of
 * them differing in a title and an icon is four copies too many. Everything those two do beyond declaring
 * themselves is here, so a report page is now a title, an icon, a description and a help topic.
 *
 * **What it does not do is render.** The page holds a date, asks the module's own renderer for a payload,
 * and hands it to `filament/pages/module-report.blade.php`, which draws it through the two partials the
 * explorer pane draws — so a report read on its own page and the same report read in the pane are the same
 * arithmetic through the same markup. A page that rendered its own version of the table would be free to
 * disagree with the pane, and nothing in this application would notice: they would each be right about a
 * different total.
 *
 * **`ReportRenderers` rather than the bound `ReportPaneRenderer`.** The renderer is the module's own — it
 * registered the closure — and going through the pane would make the page depend on which pane is bound.
 * `NoReportPane` is the default for a company with no accounting module, and it draws registered reports
 * precisely so the two paths agree; but a page that works only when a *different* module is licensed is a
 * gating bug waiting to be discovered by the one customer it applies to.
 */
abstract class ModuleReportPage extends Page
{
    use BelongsToModule;
    use ExportsTheOpenReport;

    protected string $view = 'filament.pages.module-report';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    /**
     * The date the report is drawn to.
     *
     * In the URL for the same reason the pane's is: a report at a date is the thing people send each other.
     * Defaulted in `mount()` rather than here so it is today at the moment of asking rather than at the
     * moment the class was loaded — a queue worker holding a class for a week would otherwise report last
     * week.
     */
    #[Url]
    public ?string $asOf = null;

    /**
     * The payload, once per request.
     *
     * Not an optimisation to be tidied away later: the heading, the subheading and the view each ask for
     * the report, so an unmemoised page runs its whole service three times for one screen. That is the
     * exact shape of the fault `docs/page-load-performance-plan.md` fought — a figure asked for once per
     * place it is displayed — and this plan's own risks name per-row queries in these reports as the thing
     * to watch. Private, so Livewire re-derives it each request rather than shipping a report to the
     * browser and back.
     *
     * @var array<string, mixed>|null
     */
    private ?array $payload = null;

    public function mount(): void
    {
        $this->asOf ??= now()->toDateString();
    }

    /**
     * The header row, assembled here so no report can be built without its exports.
     *
     * **`final` on purpose.** Every one of these pages used to declare `getHeaderActions()` itself and
     * return its help button, which meant Phase 4.1's two export actions would have had to be added to
     * thirty-three files and remembered on the thirty-fourth. A report author now contributes to the row
     * rather than replacing it, and the exports cannot be dropped by omission.
     *
     * @return array<int, Action>
     */
    final protected function getHeaderActions(): array
    {
        return [...$this->reportActions(), ...$this->exportActions()];
    }

    /**
     * What this report adds to its own header — in practice, its help button.
     *
     * Overridden in the subclass rather than assembled here, and the help call has to stay a **literal** in
     * the subclass's own source: `HelpCoverageTest` reads each page's file for `HelpAction::make('...')`, so
     * a call inherited from this class would read as a page with no help. Which is the right answer, because
     * the slug is per report and not per base class.
     *
     * @return array<int, Action>
     */
    protected function reportActions(): array
    {
        return [];
    }

    /**
     * What the hub, the pane and `ReportRenderers` all key this report on.
     *
     * The class basename, because that is what `Reports::sections()` puts in the URL and what a module
     * passes to `ReportRenderers::register()`. Derived rather than declared so the three cannot fall out of
     * step: a page whose key were a separate constant could be registered under one name and drawn under
     * another, and the symptom would be a report that renders on its page and is missing from the hub.
     */
    public function reportKey(): string
    {
        return class_basename(static::class);
    }

    /**
     * The payload, from the module that owns this report.
     *
     * Throws rather than rendering an empty page when nothing is registered, because there is exactly one
     * way to get here — the module's provider did not register a renderer for this key — and a blank report
     * reads as "no data for this period", which is a different and much worse answer than "this is broken".
     *
     * @return array<string, mixed>
     */
    public function statement(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $payload = ReportRenderers::render(
            $this->reportKey(),
            $this->asOf ?: now()->toDateString(),
            comparison: false,
            asked: [],
        );

        if ($payload === null) {
            throw new RuntimeException(sprintf(
                '%s has no renderer. Register one for "%s" in the module\'s service provider — see %s.',
                static::class,
                $this->reportKey(),
                ReportRenderers::class,
            ));
        }

        return $this->payload = $payload;
    }

    /**
     * The title, taken from the report itself.
     *
     * So the page heading, the hub row and the payload's own title are one string. A page declaring its own
     * `$title` beside a service that states another is how a report comes to be called two things — and the
     * one on the report is the one that is also on a PDF and in an email subject.
     */
    public function getHeading(): string
    {
        return (string) ($this->statement()['title'] ?? static::getNavigationLabel());
    }

    public function getSubheading(): ?string
    {
        return $this->statement()['subtitle'] ?? null;
    }

    /**
     * `ReportView`, plus the module — which already covers every module the report reads.
     *
     * Restated here rather than inherited from `BelongsToModule::canAccess()`, which the trait's own
     * comment warns about: a subclass defining `canAccess()` shadows it silently. Every report page in this
     * application gates on both, and `ModuleGatingTest` asserts the behaviour rather than the trait.
     *
     * **A cross-module report needs nothing extra here, which took a wrong turn to establish.**
     * `docs/reports-expansion-plan.md`'s risk list asks that such a report "gate on *every* module it reads,
     * not just the one it lives in", and Phase 2.3 was built with a `$alsoRequires` list to do it. The list
     * was redundant: `Modules::enabledFor()` walks a module's declared requirements recursively — "a module
     * is only usable when everything it declares as a requirement is usable" — so a report owned by
     * Timesheets is already unavailable when Projects is off, because Timesheets requires Projects.
     *
     * That leaves exactly one case the manifest does not cover: a module a report reads and its owner does
     * *not* require. But such a module is by definition optional to the owner, so the right answer is for
     * the report to **degrade** — Unbilled WIP shows a customer id instead of a name without Invoicing —
     * rather than to disappear. Gating on it would deny the report to a company that could perfectly well
     * read it. So there is no third case, and no machinery for one.
     */
    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
    }
}
