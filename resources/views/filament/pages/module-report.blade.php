{{--
    A report on its own page, drawn from the payload the explorer pane draws.

    One view for every report built on `App\Support\Reporting\ModuleReportPage` — five of them at Phase
    1.2, and each later phase's tables land here rather than adding a view per report. The page holds a
    date and nothing else; the payload holds the columns, the rows, the tiles and the note, so a report
    that changes shape changes in one service method and both screens follow.

    The two partials are the pane's own — `filament/partials/report-tiles` and `report-table` — which is
    the point. A page rendering markup of its own would let the page and the pane state different
    figures from one payload, and nothing would notice.
--}}
<x-filament-panels::page>
    @php($statement = $this->statement())

    <div class="fi-explorer-page">
        <div class="fi-explorer-page-controls">
            <label class="fi-explorer-date">
                <span class="fi-sr-only">As of date</span>
                {{--
                    `.live` rather than a submit button: the whole page is one date, and a date that needs
                    applying is a second thing to learn for no gain. The same control the pane uses.
                --}}
                <input type="date" wire:model.live="asOf" class="fi-explorer-date-input">
            </label>

            <a href="{{ \App\Modules\Core\Filament\Pages\Reports::getUrl(['selected' => $this->reportKey()]) }}"
               wire:navigate
               class="fi-explorer-open">Read in the Reports explorer ↗</a>
        </div>

        <div class="fi-explorer-pane-body">
            @include('filament.partials.report-tiles', ['statement' => $statement])
            @include('filament.partials.report-table', ['statement' => $statement])
        </div>
    </div>
</x-filament-panels::page>
