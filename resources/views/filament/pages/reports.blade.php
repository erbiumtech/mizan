{{--
    The Reports explorer, 4c: a searchable list of reports on the left, the whole statement on the right.

    Everything here is Livewire state — searching, switching report, changing the date, turning the
    comparison off. Nothing navigates, so the pane redraws and the page does not reload, which is the
    property 4c is named for.

    What the design draws and this does not:

      - **A "RUN 3 days ago" stamp per report.** Nothing in this application records when a report was
        run. The list shows each report's section instead, which is true.
      - **Current/non-current subtotals** inside assets and liabilities. That split is a property of the
        chart of accounts and this one does not carry it; the sections and their totals are real, and an
        invented subtotal in a statement is worse than none.
      - **The trial balance and cash flow in this pane.** Both are statements, neither has this shape —
        see ComparativeStatement. Selecting one offers its own page rather than a wrong rendering.
--}}
<x-filament-panels::page>
    <div class="fi-explorer">
        {{-- ------------------------------------------------------------------ the list --}}
        <aside class="fi-explorer-list">
            <div class="fi-explorer-list-header">
                <div class="fi-explorer-list-title">
                    <span>Reports</span>
                    <span class="fi-explorer-list-count">{{ $this::total() }}</span>
                </div>

                <label class="fi-explorer-search">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-explorer-search-icon" />
                    <input
                        type="search"
                        wire:model.live.debounce.200ms="query"
                        placeholder="Search reports"
                        aria-label="Search reports"
                        class="fi-explorer-search-input"
                    >
                </label>

                {{-- Section chips. Links would reload; these are state. --}}
                <div class="fi-explorer-chips">
                    <button
                        type="button"
                        wire:click="$set('section', null)"
                        @class(['fi-explorer-chip', 'fi-active' => blank($this->currentSection())])
                    >All</button>

                    @foreach (array_keys($this::sectionCounts()) as $section)
                        <button
                            type="button"
                            wire:click="$set('section', @js($section))"
                            @class(['fi-explorer-chip', 'fi-active' => $this->currentSection() === $section])
                        >{{ $section }}</button>
                    @endforeach
                </div>
            </div>

            <div class="fi-explorer-list-body">
                @forelse ($this->visibleReports() as $report)
                    <button
                        type="button"
                        wire:click="select('{{ $report['key'] }}')"
                        wire:key="row-{{ $report['key'] }}"
                        @class(['fi-explorer-row', 'fi-active' => $this->selected === $report['key']])
                    >
                        <span class="fi-explorer-row-icon">
                            <x-filament::icon :icon="$report['icon']" class="fi-explorer-row-icon-svg" />
                        </span>

                        <span class="fi-explorer-row-text">
                            <span class="fi-explorer-row-name">{{ $report['label'] }}</span>
                            <span class="fi-explorer-row-meta">
                                {{ $this->selected === $report['key'] ? 'SHOWING NOW' : $report['section'] }}
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="fi-explorer-empty">Nothing matches “{{ $this->query }}”.</p>
                @endforelse
            </div>
        </aside>

        {{-- ------------------------------------------------------------- the statement --}}
        @php($statement = $this->statement())
        @php($report = $this->selectedReport())

        <section class="fi-explorer-pane">
            @if ($statement)
                <header class="fi-explorer-pane-header">
                    <div class="fi-explorer-pane-heading">
                        <h2 class="fi-explorer-pane-title">{{ $statement['title'] }}</h2>
                        <p class="fi-explorer-pane-subtitle">{{ $statement['subtitle'] }}</p>

                        {{--
                            What the report answers, in the words the hub has always used. 4c's list rows
                            are two lines and have no room for it, and the descriptions are the reason the
                            hub reads as a set of questions rather than a menu (see Reports::SECTIONS) —
                            so the selected one says its piece here instead of being dropped.
                        --}}
                        <p class="fi-explorer-pane-lead">{{ $report['description'] }}</p>
                    </div>

                    <div class="fi-explorer-controls">
                        <label class="fi-explorer-date">
                            <span class="fi-sr-only">As of date</span>
                            <input type="date" wire:model.live="asOf" value="{{ $this->asOf }}" class="fi-explorer-date-input">
                        </label>

                        <button
                            type="button"
                            wire:click="$toggle('comparison')"
                            @class(['fi-explorer-toggle', 'fi-active' => $this->comparison])
                            aria-pressed="{{ $this->comparison ? 'true' : 'false' }}"
                        >vs previous year</button>

                        <a href="{{ $report['url'] }}" wire:navigate class="fi-explorer-open">Open in full page ↗</a>
                    </div>
                </header>

                <div class="fi-explorer-pane-body">
                    <div class="fi-explorer-tiles">
                        @foreach ($statement['tiles'] as $tile)
                            <div @class(['fi-explorer-tile', 'fi-accent' => $tile['accent']])>
                                <span class="fi-explorer-tile-label">{{ $tile['label'] }}</span>
                                <span class="fi-explorer-tile-value">{{ number_format($tile['value'], 0) }}</span>
                            </div>
                        @endforeach

                        <div class="fi-explorer-tiles-note">
                            <span @class(['fi-explorer-note', 'fi-warn' => ! $statement['balanced']])>{{ $statement['note'] }}</span>
                        </div>
                    </div>

                    <div class="fi-explorer-statement">
                        <div class="fi-explorer-statement-head">
                            <span>Account</span>
                            <span class="fi-num">{{ $statement['current_label'] }}</span>
                            <span class="fi-num">{{ $statement['previous_label'] ?? '—' }}</span>
                            <span class="fi-num">Change</span>
                        </div>

                        <div class="fi-explorer-statement-body">
                            @forelse ($statement['sections'] as $section)
                                <div class="fi-explorer-section">{{ $section['label'] }}</div>

                                @foreach ($section['rows'] as $row)
                                    <div class="fi-explorer-line">
                                        {{--
                                            The code as well as the name. Two accounts in the seeded chart
                                            are both called "Accounts Receivable", and a statement listing
                                            the same name twice with different figures is unreadable — the
                                            rows are keyed on the code, so the code is what distinguishes
                                            them on screen too.
                                        --}}
                                        <span class="fi-explorer-line-label">
                                            <span class="fi-explorer-line-code">{{ str_starts_with($row['code'], 'zzz') ? '' : $row['code'] }}</span>
                                            {{ $row['label'] }}
                                        </span>
                                        <span class="fi-num">{{ $row['current'] === null ? '' : number_format($row['current'], 0) }}</span>
                                        <span class="fi-num">{{ $row['previous'] === null ? '' : number_format($row['previous'], 0) }}</span>
                                        <span class="fi-num fi-explorer-change">{{ $row['change'] === null ? '—' : sprintf('%+.1f%%', $row['change']) }}</span>
                                    </div>
                                @endforeach

                                <div class="fi-explorer-total">
                                    <span>{{ $section['total']['label'] }}</span>
                                    <span class="fi-num">{{ number_format($section['total']['current'], 0) }}</span>
                                    <span class="fi-num">{{ $section['total']['previous'] === null ? '' : number_format($section['total']['previous'], 0) }}</span>
                                    <span class="fi-num fi-explorer-change">{{ $section['total']['change'] === null ? '—' : sprintf('%+.1f%%', $section['total']['change']) }}</span>
                                </div>
                            @empty
                                <p class="fi-explorer-empty">
                                    Nothing is posted to this date, so the statement has no lines yet.
                                </p>
                            @endforelse

                            {{-- The closing identity: what the sections above have to add up to. --}}
                            <div class="fi-explorer-total fi-explorer-closing">
                                <span>{{ $statement['closing']['label'] }}</span>
                                <span class="fi-num">{{ number_format($statement['closing']['current'], 0) }}</span>
                                <span class="fi-num">{{ $statement['closing']['previous'] === null ? '' : number_format($statement['closing']['previous'], 0) }}</span>
                                <span class="fi-num"></span>
                            </div>
                        </div>

                        <div class="fi-explorer-statement-foot">
                            Figures in {{ \App\Modules\Core\Models\Company::current()?->currency_code ?? 'PKR' }} ·
                            complete statement
                        </div>
                    </div>
                </div>
            @elseif ($report)
                {{-- Selected, but not a statement this pane can draw. Say which, and hand over. --}}
                <div class="fi-explorer-placeholder">
                    <h2 class="fi-explorer-pane-title">{{ $report['label'] }}</h2>
                    <p class="fi-explorer-placeholder-text">{{ $report['description'] }}</p>
                    <p class="fi-explorer-placeholder-note">
                        This one is not a statement of sections and totals, so it opens on its own screen
                        rather than in this pane.
                    </p>
                    <a href="{{ $report['url'] }}" wire:navigate class="fi-explorer-open fi-explorer-open-lg">
                        Open {{ $report['label'] }} ↗
                    </a>
                </div>
            @else
                <div class="fi-explorer-placeholder">
                    <p class="fi-explorer-placeholder-text">Choose a report on the left to read it here.</p>

                    @if ($default = $this->defaultStatementKey())
                        <button type="button" wire:click="select('{{ $default }}')" class="fi-explorer-open fi-explorer-open-lg">
                            Show {{ $this::catalogue()[$default]['label'] }}
                        </button>
                    @endif
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
