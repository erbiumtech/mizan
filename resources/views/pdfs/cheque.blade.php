{{--
    A cheque leaf — docs/saas-launch-plan.md §8.6. Every field is absolutely positioned in millimetres from
    the layout, which is why there is no flow and no shared sheet: the paper is the bank's, and we only
    write on it. Absolute positioning in mm renders the same on Chrome and Dompdf, so there is no
    per-engine partial to keep in step.

    @param \App\Modules\Accounting\Models\ChequeLayout $layout
    @param array<string, string> $values
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Cheque</title>
    <style>
        @page { margin: 0; size: {{ $layout->page_width_mm }}mm {{ $layout->page_height_mm }}mm; }
        html, body { margin: 0; padding: 0; }
        body { width: {{ $layout->page_width_mm }}mm; height: {{ $layout->page_height_mm }}mm; position: relative; font-family: "DejaVu Sans", Arial, sans-serif; color: #000; }
        .f { position: absolute; white-space: nowrap; overflow: hidden; line-height: 1.2; }
        .crossing { transform: rotate(-20deg); font-weight: bold; letter-spacing: .05em; }
    </style>
</head>
<body>
@foreach (\App\Modules\Accounting\Models\ChequeLayout::FIELDS as $name)
    @php($f = $layout->field($name))
    @if (($values[$name] ?? '') !== '')
        <div class="f {{ $name === 'crossing' ? 'crossing' : '' }}" style="left: {{ $f['x'] }}mm; top: {{ $f['y'] }}mm; width: {{ $f['w'] }}mm; font-size: {{ $f['font'] }}pt;">{{ $values[$name] }}</div>
    @endif
@endforeach
</body>
</html>
