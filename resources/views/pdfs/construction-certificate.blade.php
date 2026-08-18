{{--
    The printed payment certificate — docs/construction-management-plan.md §10.5.

    ONE LEGAL NOTE, AND IT BELONGS HERE RATHER THAN IN A COMMIT MESSAGE.

    AIA G702 and G703 are copyrighted documents whose reproduction the AIA licenses. What this template
    reproduces is the **content and column structure** — the de facto industry information set — under our
    own layout, titled "Application and Certificate for Payment" and "Continuation Sheet". It is never
    titled "AIA Document G702", and it must not be made to look more like the real thing. The reason is
    written here because this is the first file the next person to try that will open.

    FIDIC prescribes the *content* of clause 14.6 and not a layout, so the same component serves it: a
    portrait summary plus an annexed measurement schedule, with the column set chosen by the contract's
    standard (see CertificateSchedule::columnsFor()).

    Everything about how this page breaks is decided in PHP. The lines arrive already chunked into pages,
    each with its own table, its own header row, its own brought-forward and carried-forward subtotals and
    its own "page n of m" — because `page-break-inside` is unreliable, `<thead>` repetition fails silently
    under Dompdf, and neither engine can print a page number this template could otherwise reach. The
    result is the same document whichever engine renders it, which is what lets a certificate be reissued
    identically.

    DejaVu Sans, no webfont: it ships with Dompdf and is already what every other PDF here uses. Minimal
    shading, because half of these forms still travel by photocopier. The currency is named once in the
    column header rather than in every cell.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->certificate_number }}</title>
    <style>
        @page { margin: 12mm 10mm; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }

        h1 { font-size: 15px; margin: 0 0 2px; }
        h2 { font-size: 11px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: 1px; }
        .muted { color: #666; }
        .small { font-size: 9px; }

        .masthead { display: flex; justify-content: space-between; margin-bottom: 14px; }
        .masthead > div { width: 49%; }
        .parties { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .parties > div { width: 49%; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 4px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; font-weight: bold; }
        .num { text-align: right; }
        .summary td { padding: 4px 6px; }
        .summary .label { width: 62%; }
        .summary .line-no { width: 6%; color: #666; }
        .summary .grand td { font-weight: bold; border-top: 2px solid #222; border-bottom: 2px solid #222; }

        /* Fixed layout with explicit widths: the only way an eleven-column sheet paginates the same way twice. */
        .schedule { table-layout: fixed; }
        .schedule td, .schedule th { word-wrap: break-word; }
        .schedule .forward td { background: #f9f9f9; font-weight: bold; }
        .schedule .continuation td { border-bottom: none; padding-top: 0; color: #444; }

        .page { page-break-after: always; }
        .page.last { page-break-after: auto; }
        .page-foot { margin-top: 6px; }

        .signatures { display: flex; justify-content: space-between; margin-top: 28px; }
        .signatures > div { width: 30%; border-top: 1px solid #222; padding-top: 4px; }
    </style>

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-construction-certificate')
    @endif
</head>
<body>
@php
    $vocabulary = $certificate->contract->vocabulary();
    $contract = $certificate->contract;
    $currency = $contract->currency_code ?: config('app.currency', 'PKR');
    $money = fn (?float $value): string => $value === null ? '—' : number_format($value, 2);
@endphp

{{-- ------------------------------------------------------------------ the summary --}}
<div class="masthead">
    <div>
        {{-- Titled in the contract's own words, and never as the copyrighted form. --}}
        <h1>{{ $vocabulary->certifierDocument() }}</h1>
        <div class="muted">
            No. {{ $certificate->certificate_number }} ·
            valued to {{ $certificate->period_end?->format('d M Y') }}
        </div>
        <div class="muted">
            {{ $certificate->status === 'draft' ? 'DRAFT — not issued' : 'Issued '.$certificate->issued_on?->format('d M Y') }}
            @if ($certificate->due_on) · payable by {{ $certificate->due_on->format('d M Y') }} @endif
        </div>
        @if ($certificate->progressClaim)
            <div class="muted small">
                Against {{ strtolower($vocabulary->word('contractor_document')) }}
                {{ $certificate->progressClaim->claim_number }}
            </div>
        @endif
    </div>
    <div style="text-align: right">
        <strong>{{ $contract->job?->name }}</strong><br>
        <span class="muted">{{ $contract->job?->code }} · {{ $contract->contract_number }}</span><br>
        <span class="muted small">{{ $contract->title }}</span><br>
        <span class="muted small">All figures in {{ $currency }}</span>
    </div>
</div>

<div class="parties">
    <div>
        <strong>{{ $contract->side === 'receivable' ? 'Employer' : 'Subcontractor' }}</strong><br>
        {{ $contract->contact?->name ?? '—' }}
    </div>
    <div style="text-align: right">
        <strong>{{ $vocabulary->certifier() }}</strong><br>
        {{ $contract->job?->certifier?->name ?? '—' }}
    </div>
</div>

<h2>Summary</h2>

{{--
    The nine numbered lines. The numbering is the industry information set, and every figure below reads off
    the certificate's own frozen columns or its deduction rows — nothing here is recomputed at print time,
    because a document that recalculated itself when reprinted would not be the same document.
--}}
<table class="summary">
    <tr>
        <td class="line-no">1</td>
        <td class="label">Original contract sum</td>
        <td class="num">{{ $money((float) $certificate->contract_sum_original) }}</td>
    </tr>
    <tr>
        <td class="line-no">2</td>
        <td class="label">
            Net change by approved {{ strtolower($vocabulary->change()) }}s
            <span class="muted small">— agreed only</span>
        </td>
        <td class="num">{{ $money((float) $certificate->variations_net_to_date) }}</td>
    </tr>
    <tr>
        <td class="line-no">3</td>
        <td class="label">Contract sum to date</td>
        <td class="num">{{ $money($certificate->contractSumToDate()) }}</td>
    </tr>
    <tr>
        <td class="line-no">4</td>
        <td class="label">Total completed and stored to date</td>
        <td class="num">{{ $money((float) $certificate->gross_value_to_date) }}</td>
    </tr>
    <tr>
        <td class="line-no">5a</td>
        <td class="label">Retention on work completed</td>
        <td class="num">{{ $money((float) $certificate->gross_work_to_date === 0.0 ? 0.0 : round((float) $certificate->retention_to_date * ((float) $certificate->gross_work_to_date / max(0.01, (float) $certificate->gross_value_to_date)), 2)) }}</td>
    </tr>
    <tr>
        <td class="line-no">5b</td>
        <td class="label">Retention on stored material</td>
        <td class="num">{{ $money((float) $certificate->gross_materials_to_date === 0.0 ? 0.0 : round((float) $certificate->retention_to_date * ((float) $certificate->gross_materials_to_date / max(0.01, (float) $certificate->gross_value_to_date)), 2)) }}</td>
    </tr>
    <tr>
        <td class="line-no">5</td>
        <td class="label">Total retention held to date</td>
        <td class="num">{{ $money((float) $certificate->retention_to_date) }}</td>
    </tr>
    <tr>
        <td class="line-no">6</td>
        <td class="label">Total earned less retention</td>
        <td class="num">{{ $money(round((float) $certificate->gross_value_to_date - (float) $certificate->retention_to_date, 2)) }}</td>
    </tr>
    <tr>
        <td class="line-no">7</td>
        <td class="label">Less previously certified</td>
        <td class="num">{{ $money((float) $certificate->previously_certified) }}</td>
    </tr>
    <tr class="grand">
        <td class="line-no">8</td>
        <td class="label">Current payment due</td>
        <td class="num">{{ $money($certificate->currentDue()) }}</td>
    </tr>
    <tr>
        <td class="line-no">9</td>
        <td class="label">Balance to finish, including retention</td>
        <td class="num">{{ $money(round($certificate->contractSumToDate() - (float) $certificate->gross_value_to_date, 2)) }}</td>
    </tr>
</table>

{{--
    Every deduction, one row each — §10.3. The only figure FIDIC uses that AIA does not is advance recovery,
    and it is a row rather than a column, so an AIA certificate simply has none. That is the dual-standard
    claim discharged on the page rather than argued about.
--}}
@if ($certificate->deductions->isNotEmpty())
    <h2>Deductions and adjustments</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 22%">Kind</th>
                <th>Description</th>
                <th class="num" style="width: 18%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($certificate->deductions as $deduction)
                <tr>
                    <td>{{ str_replace('_', ' ', ucfirst($deduction->kind)) }}</td>
                    <td>{{ $deduction->description }}</td>
                    {{-- Negative reduces the payment, and the sign is printed as it is stored. --}}
                    <td class="num">{{ $money((float) $deduction->amount) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td colspan="2">Net deductions</td>
                <td class="num">{{ $money($certificate->deductionsTotal()) }}</td>
            </tr>
        </tbody>
    </table>
@endif

<div class="signatures">
    <div class="small">Contractor</div>
    <div class="small">{{ $vocabulary->certifier() }}</div>
    <div class="small">{{ $contract->side === 'receivable' ? 'Employer' : 'Contractor' }}</div>
</div>

{{-- ------------------------------------------------------- the continuation sheet --}}
@if ($pages !== [])
    @foreach ($pages as $page)
        <div class="page {{ $page['is_last'] ? 'last' : '' }}" @if (! $loop->first) style="page-break-before: always" @endif>
            <h2>
                Continuation sheet — page {{ $page['number'] }} of {{ $page['of'] }}
                <span class="muted small">
                    {{ $certificate->certificate_number }} · valued to {{ $certificate->period_end?->format('d M Y') }}
                    · {{ $currency }}
                </span>
            </h2>

            <table class="schedule">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th class="{{ $column['align'] === 'right' ? 'num' : '' }}" style="width: {{ $column['width'] }}%">
                                @if ($column['label'] !== '')
                                    <span class="muted small">{{ $column['label'] }}</span><br>
                                @endif
                                {{ $column['head'] }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    {{-- Nothing has been brought forward onto page one, so there is no row for it there. --}}
                    @if ($page['brought_forward'] !== null)
                        <tr class="forward">
                            @foreach ($columns as $index => $column)
                                @if ($index === 0)
                                    <td colspan="2">Brought forward</td>
                                @elseif ($index === 1)
                                    @continue
                                @else
                                    <td class="num">
                                        {{ array_key_exists($column['key'], $page['brought_forward'])
                                            ? $money($page['brought_forward'][$column['key']])
                                            : '' }}
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    @endif

                    @foreach ($page['rows'] as $row)
                        <tr>
                            @foreach ($columns as $column)
                                @if ($column['key'] === 'item_no')
                                    <td>{{ $row['line']->item_no }}</td>
                                @elseif ($column['key'] === 'description')
                                    <td>{{ $row['description'] }}</td>
                                @elseif ($column['key'] === 'percent_complete')
                                    {{-- Null on a line with no scheduled value: 0% against an omission reads as
                                         work not started, which is a different fact. --}}
                                    <td class="num">
                                        {{ $row['line']->percentComplete() === null
                                            ? '—'
                                            : number_format($row['line']->percentComplete(), 1) }}
                                    </td>
                                @else
                                    <td class="num">
                                        {{ $money(\App\Modules\ConstructionContracts\Support\CertificateSchedule::value($row['line'], $column['key'])) }}
                                    </td>
                                @endif
                            @endforeach
                        </tr>

                        @if ($row['continuation'] !== null)
                            <tr class="continuation">
                                <td></td>
                                <td colspan="{{ count($columns) - 1 }}" class="small">{{ $row['continuation'] }}</td>
                            </tr>
                        @endif
                    @endforeach

                    <tr class="forward">
                        @foreach ($columns as $index => $column)
                            @if ($index === 0)
                                <td colspan="2">{{ $page['is_last'] ? 'Total' : 'Carried forward' }}</td>
                            @elseif ($index === 1)
                                @continue
                            @else
                                <td class="num">
                                    {{ array_key_exists($column['key'], $page['carried_forward'])
                                        ? $money($page['carried_forward'][$column['key']])
                                        : '' }}
                                </td>
                            @endif
                        @endforeach
                    </tr>
                </tbody>
            </table>

            <div class="page-foot muted small">
                {{ $certificate->certificate_number }} · continuation sheet {{ $page['number'] }} of {{ $page['of'] }}
                @if (! $page['is_last']) · carried forward overleaf @endif
            </div>
        </div>
    @endforeach
@endif
</body>
</html>
