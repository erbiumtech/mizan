{{--
    Columns and rows that are not accounts — the `table` kind, wherever it is drawn.

    Extracted from `filament/pages/reports.blade.php` so that a report rendered on its own page and the
    same report rendered in the explorer pane cannot disagree (`docs/reports-expansion-plan.md` Phase
    1.2). Before Phase 1.2 every report either lived in the pane or had a bespoke view of its own; the
    CRM five are the first with both, off one payload, and one renderer is what keeps that honest.

    Takes `$statement`: a `table` payload as `App\Support\Reporting\ReportShapes::table()` builds it —
    `columns`, `grid`, `numeric`, `rows`, `note`, `empty`, and optionally `footer`/`footer_span`.
--}}
@php($grid = 'grid-template-columns: '.$statement['grid'])
<div class="fi-explorer-statement fi-explorer-table">
    <div class="fi-explorer-statement-head" style="{{ $grid }}">
        @foreach ($statement['columns'] as $i => $column)
            <span @class(['fi-num' => in_array($i, $statement['numeric'], true)])>{{ $column }}</span>
        @endforeach
    </div>

    <div class="fi-explorer-statement-body">
        @forelse ($statement['rows'] as $row)
            <div class="fi-explorer-line" style="{{ $grid }}">
                @foreach ($row as $i => $cell)
                    <span @class([
                        'fi-num' => in_array($i, $statement['numeric'], true),
                        'fi-explorer-line-label' => $i === 0,
                    ])>{{ $cell }}</span>
                @endforeach
            </div>
        @empty
            <p class="fi-explorer-empty">{{ $statement['empty'] }}</p>
        @endforelse

        {{--
            The record row across the bottom, one cell per column, on the same grid as
            the rows above it — so a figure sits directly under the column it totals.
            It used to be a single label with one value dropped in the last column,
            which put a tax total under "tax withheld" only by luck and a debit total
            nowhere at all.
        --}}
        @if (($statement['footer'] ?? null) && $statement['rows'] !== [])
            <div class="fi-explorer-total fi-explorer-closing" style="{{ $grid }}">
                {{--
                    The label spans the blank cells that follow it, which is why the
                    pane computes a span (see ReportShapes::table). Confined to the first
                    column it would be cut off on any report whose first column is
                    narrow — on a register that is the 7rem date, and "Closing — 40
                    transactions" arrived as "Closing…".
                --}}
                <span class="fi-explorer-line-label" style="grid-column: span {{ $statement['footer_span'] }}">
                    {{ $statement['footer'][0] }}
                </span>

                @foreach (array_slice($statement['footer'], $statement['footer_span'], null, true) as $i => $cell)
                    <span @class(['fi-num' => in_array($i, $statement['numeric'], true)])>{{ $cell }}</span>
                @endforeach
            </div>
        @endif
    </div>

    <div class="fi-explorer-statement-foot">{{ $statement['note'] }}</div>
</div>
