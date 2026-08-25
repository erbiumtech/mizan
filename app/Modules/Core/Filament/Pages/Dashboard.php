<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\DashboardPeriod;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Livewire\Attributes\Url;

/**
 * The dashboard, with a period of its own — `docs/reports-expansion-plan.md` Phase 5.1.
 *
 * "A dashboard page of our own, replacing `Dashboard::class` in `AdminPanelProvider`, carrying a period
 * filter in the URL, so a dashboard someone links to opens on the period they meant. Widgets read the page's
 * filter; none of them keeps its own idea of 'now'."
 *
 * **The period reaches widgets through `getWidgetData()`**, which Filament spreads into every widget's mount
 * properties. So a widget declares `public ?string $period` and is handed the page's, rather than reading
 * `now()` itself — which is the whole point of the item. A widget with its own idea of the period is a widget
 * that disagrees with the one beside it, and on a dashboard the two sit a centimetre apart.
 *
 * **Filament's own filter form was not used, because the plan asks for the URL.** `HasFiltersForm` keeps
 * filter state in a schema, which is fine and is not linkable; "a dashboard someone links to opens on the
 * period they meant" needs `#[Url]`. So the properties are ours and `getWidgetData()` is the bridge.
 *
 * **In Core, not Accounting.** It belongs to no module and every module contributes widgets to it — the same
 * reasoning that puts the Reports hub, `OperationsOverview` and `FiscalYear` there, and Core is the one
 * module always licensed, which is what lets each widget's own `canView()` be the only thing deciding what
 * appears.
 */
class Dashboard extends BaseDashboard
{
    /**
     * Which span the figures cover. In the URL so a dashboard is a link.
     *
     * Nullable rather than defaulted here so `DashboardPeriod::normalise()` is the single place that decides
     * what "unspecified" means — a default written twice is a default that eventually differs.
     */
    #[Url]
    public ?string $period = null;

    /** The ends of a custom range. In the URL for the same reason, and ignored unless both are set. */
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /**
     * What every widget on this page is handed.
     *
     * The resolved dates rather than the period name, so no widget has to know that "this quarter" is fiscal
     * or that every period ends today. One place knows; twenty widgets are told.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        $range = $this->range();

        return [
            'period' => $this->periodKey(),
            'periodFrom' => $range['from'],
            'periodTo' => $range['to'],
        ];
    }

    public function periodKey(): string
    {
        return DashboardPeriod::normalise($this->period);
    }

    /**
     * The span in force, resolved on every read.
     *
     * Not memoised: Livewire writes these properties straight from the wire when the picker changes, and a
     * cached range would be the previous one for the render that matters.
     *
     * @return array{from: string, to: string}
     */
    public function range(): array
    {
        return DashboardPeriod::range($this->periodKey(), $this->from, $this->to);
    }

    /** @return array<string, string> */
    public function periods(): array
    {
        return DashboardPeriod::PERIODS;
    }

    /**
     * The period picker, as one dropdown in the header.
     *
     * A dropdown rather than four chips because the dashboard's header is shared with whatever else a company
     * puts there, and because the label doubles as the statement of which period is in force — a row of chips
     * says the same thing twice.
     *
     * @return array<int, ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ...$this->namedPeriodActions(),
                $this->customRangeAction(),
            ])
                ->label(DashboardPeriod::label($this->periodKey(), $this->from, $this->to))
                ->icon('heroicon-m-calendar-days')
                ->button()
                ->color('gray'),

            HelpAction::make('dashboard', 'Dashboard: Help'),
        ];
    }

    /**
     * One action per named period.
     *
     * Each clears `from` and `to` on the way past. Without that, choosing "this month" after a custom range
     * would leave the custom dates in the URL — harmless while the period is named, and back in force the
     * moment somebody picked custom again, showing a range they had moved on from.
     *
     * @return array<int, Action>
     */
    private function namedPeriodActions(): array
    {
        $named = array_diff_key(DashboardPeriod::PERIODS, [DashboardPeriod::CUSTOM => null]);

        return array_values(array_map(
            fn (string $label, string $key): Action => Action::make('period'.str($key)->studly())
                ->label($label)
                ->action(function () use ($key): void {
                    $this->period = $key;
                    $this->from = null;
                    $this->to = null;
                }),
            $named,
            array_keys($named),
        ));
    }

    /**
     * The custom range, collected in a modal.
     *
     * Defaulted to the span currently in force rather than to empty, so "custom" starts from what somebody is
     * already looking at and is an adjustment rather than a fresh form.
     */
    private function customRangeAction(): Action
    {
        return Action::make('periodCustom')
            ->label(DashboardPeriod::PERIODS[DashboardPeriod::CUSTOM])
            ->modalHeading('Custom range')
            ->modalSubmitActionLabel('Show')
            ->fillForm(fn (): array => $this->range())
            ->schema([
                DatePicker::make('from')->label('From')->native(false)->required(),
                DatePicker::make('to')->label('To')->native(false)->required(),
            ])
            ->action(function (array $data): void {
                $this->period = DashboardPeriod::CUSTOM;
                $this->from = $data['from'];
                $this->to = $data['to'];
            });
    }

    /**
     * The period under the title, so the figures are never unlabelled.
     *
     * A dashboard whose numbers are for a span nobody states is a dashboard somebody reads as "now" and
     * quotes as "this year". A custom range names its own dates, because "Custom range" over a set of
     * figures says nothing about which figures.
     */
    public function getSubheading(): ?string
    {
        return DashboardPeriod::label($this->periodKey(), $this->from, $this->to);
    }
}
