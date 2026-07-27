{{--
    The report layout is a screen page first; its print styling lives in an
    `@media print` block. Dompdf renders with a media type of `screen`, so that
    block never applies and the PDF would carry the grey page background and
    the date-range toolbar. Restate those rules unconditionally for Dompdf.

    The controllers already pass `$pdf = true`, which drops the "Back" link from
    the markup; the toolbar is emitted by the individual report views, so it has
    to be hidden here.
--}}
<style>
    /* `!important` is load-bearing: Dompdf applies the layout's universal
       reset (`* { margin: 0 }`) to the page box as well, which silently zeroes
       these margins and renders the sheet flush to the paper edge. */
    @page { margin: 12mm !important; }

    body { background: #fff !important; padding: 0 !important; }

    .toolbar, .back { display: none !important; }

    /* box-shadow is ignored by Dompdf; the radius and max-width only shrink
       the usable width once the page box owns the margins. */
    .report {
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border-radius: 0 !important;
    }
</style>
