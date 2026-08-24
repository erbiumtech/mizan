{{--
    Any report the pane can draw, printed — docs/reports-expansion-plan.md Phase 4.1.

    One template for all fifty-one, because ReportExport has already flattened the three drawable kinds
    (table, ledger, statement) into one grid. A per-kind template would have been three of these, and
    Phase 8 would have had to choose which one to send.

    Extends reports.layout, which already carries the table styling every accounting report PDF uses and
    already pulls in the Dompdf override partial when that is the engine — so this inherits both rather
    than growing a second stylesheet that drifts from the first.

    Nothing here is interactive: no toolbar, no back link, no date form. An export is a document.
--}}
@extends('reports.layout')

@section('title', $grid['title'])

@section('styles')
    {{-- A grid whose column count is not known until render, so the widths cannot be declared: the label
         column takes what is left and the rest size to their content. `table-layout: auto` is Dompdf's
         only reliable mode for that — `fixed` needs a colgroup, and a colgroup needs the widths. --}}
    table { table-layout: auto; }

    /* Section labels within a ledger or a statement. `ReportExport` emits them as a row with the label in
       the first column and the rest empty, which is the only thing a flat grid can do with a heading. */
    .grid-section td { background: #f7fafc; font-weight: 600; }

    .tiles { width: 100%; margin-bottom: 1.25rem; border-collapse: collapse; }
    .tiles td { padding: .5rem .75rem; border: 1px solid #e2e8f0; }
    .tile-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #4a5568; }
    .tile-value { font-size: 1.1rem; font-weight: 700; }
    .tile-accent .tile-value { color: #4c51bf; }
    .note { margin-top: 1.25rem; font-size: .75rem; letter-spacing: .03em; color: #4a5568; }
@endsection

@section('content')
    <h1>{{ $grid['title'] }}</h1>

    @if($grid['subtitle'] !== '')
        <p class="meta">{{ $grid['subtitle'] }}</p>
    @endif

    {{-- Tiles as a table row rather than as flex boxes: Dompdf does not lay out flex, and a PDF that only
         renders correctly under headless Chrome is a PDF that breaks on the machines without Node. --}}
    @if($grid['tiles'] !== [])
        <table class="tiles">
            <tr>
                @foreach($grid['tiles'] as $tile)
                    <td class="{{ ($tile['accent'] ?? false) ? 'tile-accent' : '' }}">
                        <div class="tile-label">{{ $tile['label'] }}</div>
                        <div class="tile-value">
                            {{ is_numeric($tile['value'] ?? null) ? number_format((float) $tile['value'], 0) : $tile['value'] }}
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    <table>
        <thead>
            <tr>
                @foreach($grid['columns'] as $index => $column)
                    <th class="{{ in_array($index, $grid['numeric'], true) ? 'num' : '' }}">{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($grid['rows'] as $row)
                {{-- Which rows are headings comes from the exporter rather than being inferred from the
                     row's shape. A one-filled-cell row is a plausible guess and a wrong one: a genuinely
                     single-column report would have every row read as a heading. --}}
                <tr class="{{ in_array($loop->index, $grid['sections'], true) ? 'grid-section' : '' }}">
                    @foreach($grid['columns'] as $index => $column)
                        <td class="{{ in_array($index, $grid['numeric'], true) ? 'num' : '' }}">{{ $row[$index] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(1, count($grid['columns'])) }}">Nothing to show.</td>
                </tr>
            @endforelse
        </tbody>

        @if($grid['footer'] !== null)
            <tfoot>
                <tr class="grand">
                    @foreach($grid['columns'] as $index => $column)
                        <td class="{{ in_array($index, $grid['numeric'], true) ? 'num' : '' }}">{{ $grid['footer'][$index] ?? '' }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if($grid['note'] !== '')
        <p class="note">{{ $grid['note'] }}</p>
    @endif
@endsection
