{{--
    The Reports screen, 3a.

    Three things the design puts on this page and this application has no data for, left out rather
    than mocked up: a "last run" stamp per report (nothing records when a report was run), saved
    packs, and scheduled deliveries. The fourth is 3a's PREVIEW pane in the run panel — in an
    accounting application a panel of plausible-looking figures that are not the company's figures is
    worse than no panel, so the panel opens the report instead of pretending to have run it.

    What is here is the design's structure: sections filtered from the column, a search that reads
    descriptions as well as titles, grid and list, and a slide-over for one report at a time.
--}}
<x-filament-panels::page>
    <div class="fi-reports">
        @php($sections = $this->visibleSections())

        @if ($sections === [])
            <div class="fi-reports-empty">
                Nothing matches “{{ $this->query }}”.
                <button type="button" wire:click="$set('query', '')" class="fi-reports-empty-reset">Clear the filter</button>
            </div>
        @elseif ($this->display === 'grid')
            <div class="fi-reports-groups">
                @foreach ($sections as $heading => $links)
                    <section class="fi-reports-group">
                        <div class="fi-reports-group-header">
                            <h3 class="fi-reports-group-title">{{ $heading }}</h3>
                            <span class="fi-reports-group-count">
                                {{ trans_choice(':count report|:count reports', count($links)) }}
                            </span>
                            <span class="fi-reports-group-rule"></span>
                        </div>

                        <div class="fi-reports-grid">
                            @foreach ($links as $link)
                                <button
                                    type="button"
                                    wire:click="select('{{ $link['key'] }}')"
                                    wire:key="card-{{ $link['key'] }}"
                                    class="fi-report-card"
                                >
                                    <span class="fi-report-card-head">
                                        <span class="fi-report-card-icon">
                                            <x-filament::icon :icon="$link['icon']" class="fi-report-card-icon-svg" />
                                        </span>
                                        <span class="fi-report-card-text">
                                            <span class="fi-report-card-name">{{ $link['label'] }}</span>
                                            <span class="fi-report-card-desc">{{ $link['description'] }}</span>
                                        </span>
                                    </span>
                                    <span class="fi-report-card-foot">
                                        <span class="fi-report-card-open">Open &rarr;</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @else
            <div class="fi-reports-list">
                <div class="fi-reports-list-head">
                    <span>Report</span>
                    <span>Category</span>
                    <span></span>
                </div>

                @foreach ($this->visibleReports() as $link)
                    <button
                        type="button"
                        wire:click="select('{{ $link['key'] }}')"
                        wire:key="row-{{ $link['key'] }}"
                        class="fi-reports-list-row"
                    >
                        <span class="fi-reports-list-report">
                            <span class="fi-report-card-icon">
                                <x-filament::icon :icon="$link['icon']" class="fi-report-card-icon-svg" />
                            </span>
                            <span class="fi-reports-list-text">
                                <span class="fi-report-card-name">{{ $link['label'] }}</span>
                                <span class="fi-reports-list-desc">{{ $link['description'] }}</span>
                            </span>
                        </span>
                        <span class="fi-reports-list-section">{{ $link['section'] }}</span>
                        <span class="fi-reports-list-action">Open</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- The run panel. 3a's slide-over, over a scrim, with the report's own page one click away. --}}
    @php($report = $this->selectedReport())

    @if ($report)
        <div
            class="fi-report-panel-scrim"
            x-data="{}"
            x-on:keydown.escape.window="$wire.deselect()"
        >
            <button type="button" class="fi-report-panel-dismiss" wire:click="deselect" aria-label="Close"></button>

            <aside class="fi-report-panel" role="dialog" aria-modal="true" aria-label="{{ $report['label'] }}">
                <div class="fi-report-panel-header">
                    <span class="fi-report-panel-icon">
                        <x-filament::icon :icon="$report['icon']" class="fi-report-panel-icon-svg" />
                    </span>

                    <div class="fi-report-panel-heading">
                        <h2 class="fi-report-panel-title">{{ $report['label'] }}</h2>
                        <p class="fi-report-panel-desc">{{ $report['description'] }}</p>
                    </div>

                    <button type="button" wire:click="deselect" class="fi-report-panel-close" aria-label="Close">
                        <x-filament::icon icon="heroicon-m-x-mark" class="fi-report-panel-close-icon" />
                    </button>
                </div>

                <div class="fi-report-panel-meta">{{ $report['section'] }}</div>

                <div class="fi-report-panel-actions">
                    <a href="{{ $report['url'] }}" wire:navigate class="fi-report-panel-open">Open report</a>
                </div>

                {{--
                    Where 3a shows parameters and a preview. Each report page owns its own
                    parameters — a date, a period, a fiscal year, a bank account — and restating them
                    here would be a second form to keep in step with the first, silently wrong the
                    day one of them gains a field. So the panel says what the report answers and
                    hands over to the page that asks properly.
                --}}
                <p class="fi-report-panel-note">
                    Dates, periods and any other options are set on the report itself.
                </p>
            </aside>
        </div>
    @endif
</x-filament-panels::page>
