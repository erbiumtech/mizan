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
        {{--
            Keyboard navigation of the list — reports-expansion-plan.md Phase 4.4, which set 35 rows as the
            point at which it earns its keep. There are 51.

            **The rows stay native `<button>`s.** The ARIA listbox pattern would mean `role="option"` and
            `aria-activedescendant`, which replaces the button semantics a screen reader already announces
            correctly with a pattern that has to reimplement them. Arrow keys move real DOM focus between
            real buttons instead, so Enter and Space keep working because they always did, and nothing is
            faked.

            **The search box is the type-ahead.** A second string matcher — keystrokes jumping the selection
            without filtering — would give two behaviours to one set of keys, and the box is the better of
            the two: it filters, and it shows you what you typed so you can correct it. So a printable key
            pressed anywhere in the list goes to the box. Down-arrow out of the box enters the list and
            up-arrow off the first row returns to it, which makes "type to narrow, arrow down, Enter" the
            path through 51 reports.
        --}}
        <aside
            class="fi-explorer-list"
            x-data="{
                rows() {
                    return Array.from($el.querySelectorAll('[data-report-row]'));
                },
                search() {
                    return $el.querySelector('[data-report-search]');
                },
                move(step) {
                    const rows = this.rows();

                    if (! rows.length) {
                        return;
                    }

                    const at = rows.indexOf(document.activeElement);

                    // Not in the list yet — a down-arrow from the search box enters at the top, an up-arrow
                    // from outside enters at the bottom.
                    if (at < 0) {
                        (step > 0 ? rows[0] : rows[rows.length - 1]).focus();

                        return;
                    }

                    // Off the top goes back to the search box rather than sticking, so the way in is also
                    // the way out.
                    if (at === 0 && step < 0) {
                        this.search()?.focus();

                        return;
                    }

                    rows[Math.min(at + step, rows.length - 1)].focus();
                },
                edge(step) {
                    const rows = this.rows();

                    if (rows.length) {
                        (step < 0 ? rows[0] : rows[rows.length - 1]).focus();
                    }
                },
                typeAhead(event) {
                    const box = this.search();

                    // Modified keys belong to the browser and to the command palette, which is Cmd+K.
                    if (! box || event.target === box || event.ctrlKey || event.metaKey || event.altKey) {
                        return;
                    }

                    // One printable character. `event.key` is a word for every key that is not one.
                    if (event.key.length !== 1) {
                        return;
                    }

                    event.preventDefault();
                    box.focus();
                    box.value += event.key;

                    // Livewire is bound on `input`, so the property only follows a value set in script if
                    // the event is raised by hand.
                    box.dispatchEvent(new Event('input'));
                },
            }"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.home.prevent="edge(-1)"
            @keydown.end.prevent="edge(1)"
            @keydown="typeAhead($event)"
        >
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
                        data-report-search
                        {{-- Escape empties the box rather than blurring it, which is what every search
                             field in a list does and what somebody who mistyped expects. --}}
                        @keydown.escape.prevent="$el.value = ''; $el.dispatchEvent(new Event('input'))"
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
                        {{-- Carries the key so the attribute is greppable per row rather than a bare flag
                             indistinguishable from the selector string in the component above. --}}
                        data-report-row="{{ $report['key'] }}"
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

                        {{--
                            Every filter the open report carries, in one bar: an account to register, a
                            budget to compare against, a month to file, something to find. Built from the
                            report's own declaration (ReportPane::ASKS via Reports::filters()) rather than
                            branched on here, so a report that gains a filter gains a control — and so the
                            bar shows *all* of them where a report has more than one.
                        --}}
                        @foreach ($this->filters() as $filter)
                            @if ($filter['control'] === 'select')
                                <select
                                    wire:model.live="{{ $filter['model'] }}"
                                    wire:key="filter-{{ $report['key'] }}-{{ $filter['ask'] }}"
                                    class="fi-explorer-select"
                                    aria-label="{{ $filter['label'] }}"
                                >
                                    {{--
                                        A blank option only where blank means something. On a month it means
                                        the whole year, which is the default and a thing to come back to; on
                                        an account it would mean a register of nothing.
                                    --}}
                                    @if ($filter['ask'] === 'month' || $filter['options'] === [])
                                        <option value="">{{ $filter['options'] === [] ? 'Nothing to choose' : $filter['placeholder'] }}</option>
                                    @endif

                                    @foreach ($filter['options'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                <label class="fi-explorer-date" wire:key="filter-{{ $report['key'] }}-{{ $filter['ask'] }}">
                                    <span class="fi-sr-only">{{ $filter['label'] }}</span>
                                    <input
                                        type="search"
                                        wire:model.live.debounce.300ms="{{ $filter['model'] }}"
                                        placeholder="{{ $filter['placeholder'] }}"
                                        class="fi-explorer-date-input"
                                    >
                                </label>
                            @endif
                        @endforeach

                        {{--
                            Only where a comparison column is drawn. A trial balance proves this period adds
                            up and a bank file is a file — a control that changed nothing on either would be
                            a control that lies about what it does.

                            A picker rather than a toggle since Phase 4.2, because there are now four
                            answers. Worth knowing what it does to a profit and loss: choosing a month or a
                            quarter narrows the *current* period to match, because a comparison shorter than
                            the period compared is not a comparison. See ReportComparison.
                        --}}
                        @if ($statement['kind'] === 'statement')
                            <label class="fi-explorer-date">
                                <span class="fi-sr-only">Compare against</span>
                                <select wire:model.live="compare" class="fi-explorer-date-input">
                                    @foreach ($this->comparisonBases() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif

                        <a href="{{ $report['url'] }}" wire:navigate class="fi-explorer-open">Open in full page ↗</a>
                    </div>
                </header>

                <div class="fi-explorer-pane-body">
                    {{--
                        The tiles, and the table below, are partials because a report's own full page draws
                        the same two things off the same payload — reports-expansion-plan.md Phase 1.2. Two
                        copies of this markup would drift, and the drift would be invisible until somebody
                        compared the page against the pane.
                    --}}
                    @include('filament.partials.report-tiles', ['statement' => $statement])

                    @if ($statement['kind'] === 'statement')
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
                                        {{--
                                            Drillable lines are buttons: a figure on a statement provokes
                                            "of what", and the register answers it. Only where the register
                                            can actually open that account — see ReportPane::drillable().
                                        --}}
                                        @if (in_array($row['code'], $statement['drillable'] ?? [], true))
                                            <button
                                                type="button"
                                                wire:click="drillInto('{{ $row['code'] }}')"
                                                class="fi-explorer-line-label fi-explorer-drill"
                                                title="Show the transactions behind this"
                                            >
                                                <span class="fi-explorer-line-code">{{ $row['code'] }}</span>
                                                {{ $row['label'] }}
                                            </button>
                                        @else
                                            <span class="fi-explorer-line-label">
                                                <span class="fi-explorer-line-code">{{ str_starts_with($row['code'], 'zzz') ? '' : $row['code'] }}</span>
                                                {{ $row['label'] }}
                                            </span>
                                        @endif
                                        <span class="fi-num">{{ \App\Support\Reporting\ReportFigures::money($row['current']) }}</span>
                                        <span class="fi-num">{{ \App\Support\Reporting\ReportFigures::money($row['previous']) }}</span>
                                        <span class="fi-num fi-explorer-change">{{ $row['change'] === null ? '—' : sprintf('%+.1f%%', $row['change']) }}</span>
                                    </div>
                                @endforeach

                                <div class="fi-explorer-total">
                                    <span>{{ $section['total']['label'] }}</span>
                                    <span class="fi-num">{{ \App\Support\Reporting\ReportFigures::money($section['total']['current']) }}</span>
                                    <span class="fi-num">{{ \App\Support\Reporting\ReportFigures::money($section['total']['previous']) }}</span>
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
                                <span class="fi-num">{{ \App\Support\Reporting\ReportFigures::money($statement['closing']['current']) }}</span>
                                <span class="fi-num">{{ \App\Support\Reporting\ReportFigures::money($statement['closing']['previous']) }}</span>
                                <span class="fi-num"></span>
                            </div>
                        </div>

                        <div class="fi-explorer-statement-foot">
                            Figures in {{ \App\Modules\Core\Models\Company::current()?->currency_code ?? 'PKR' }} ·
                            complete statement
                        </div>
                    </div>

                    {{--
                        The trial balance: a debit *and* a credit per account. It gets its own table rather
                        than being folded into the statement above, because squeezing it into one amount
                        column means choosing a side per account and calling that the figure.
                    --}}
                    {{--
                        Sections of rows with a total each: the trial balance, and the general ledger.

                        The two differ only in their columns — three for one, six for the other — so a
                        ledger states its own grid and which columns are figures, exactly as the table kind
                        does, and the rows are cells rather than named debit/credit fields. Writing a
                        second renderer for the general ledger would have meant two places to fix the day a
                        drill-through or a sticky header changed.
                    --}}
                    @elseif ($statement['kind'] === 'ledger')
                    @php($grid = 'grid-template-columns: '.$statement['grid'])
                    <div class="fi-explorer-statement fi-explorer-ledger">
                        <div class="fi-explorer-statement-head" style="{{ $grid }}">
                            @foreach ($statement['columns'] as $i => $column)
                                <span @class(['fi-num' => in_array($i, $statement['numeric'], true)])>{{ $column }}</span>
                            @endforeach
                        </div>

                        <div class="fi-explorer-statement-body">
                            @forelse ($statement['sections'] as $section)
                                <div class="fi-explorer-section">{{ $section['label'] }}</div>

                                @foreach ($section['rows'] as $row)
                                    <div class="fi-explorer-line" style="{{ $grid }}">
                                        @if (in_array($row['code'], $statement['drillable'] ?? [], true))
                                            <button
                                                type="button"
                                                wire:click="drillInto('{{ $row['code'] }}')"
                                                class="fi-explorer-line-label fi-explorer-drill"
                                                title="Show the transactions behind this"
                                            >
                                                <span class="fi-explorer-line-code">{{ $row['code'] }}</span>
                                                {{ $row['cells'][0] }}
                                            </button>
                                        @else
                                            <span class="fi-explorer-line-label">
                                                {{-- Only where the row is an account. A general ledger's
                                                     first cell is a date, and the account it belongs to is
                                                     the heading above it. --}}
                                                @if (filled($row['code']))
                                                    <span class="fi-explorer-line-code">{{ $row['code'] }}</span>
                                                @endif
                                                {{ $row['cells'][0] }}
                                            </span>
                                        @endif

                                        @foreach (array_slice($row['cells'], 1, null, true) as $i => $cell)
                                            @php($isNumeric = in_array($i, $statement['numeric'], true))
                                            <span @class(['fi-num' => $isNumeric])>{{ $isNumeric ? \App\Support\Reporting\ReportFigures::cell($cell) : $cell }}</span>
                                        @endforeach
                                    </div>
                                @endforeach

                                <div class="fi-explorer-total" style="{{ $grid }}">
                                    @foreach ($section['total']['cells'] as $i => $cell)
                                        @php($isNumeric = in_array($i, $statement['numeric'], true))
                                        <span @class(['fi-num' => $isNumeric])>{{ $isNumeric ? \App\Support\Reporting\ReportFigures::cell($cell) : $cell }}</span>
                                    @endforeach
                                </div>
                            @empty
                                <p class="fi-explorer-empty">{{ $statement['empty'] ?? 'Nothing is posted to this date yet.' }}</p>
                            @endforelse
                        </div>

                        <div class="fi-explorer-statement-foot">
                            Figures in {{ \App\Modules\Core\Models\Company::current()?->currency_code ?? 'PKR' }}
                        </div>
                    </div>

                    {{-- Columns and rows that are not accounts: the ageing, by invoice. --}}
                    @elseif ($statement['kind'] === 'table')
                    @include('filament.partials.report-table', ['statement' => $statement])

                    {{--
                        A file report. The pane says what the file would contain; releasing it stays on the
                        report's own screen, where the confirmation and the batch reference live.
                    --}}
                    @elseif ($statement['kind'] === 'file')
                    <div class="fi-explorer-file">
                        <p class="fi-explorer-placeholder-text">
                            @if ($statement['rows_count'] > 0)
                                This file is ready to be produced. It is released from the report's own
                                screen, which records the batch it went out as.
                            @else
                                There is nothing to send for this period, so no file would be produced.
                            @endif
                        </p>

                        <a href="{{ $report['url'] }}" wire:navigate class="fi-explorer-open fi-explorer-open-lg">
                            Open {{ $statement['title'] }} ↗
                        </a>
                    </div>
                    @endif
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
