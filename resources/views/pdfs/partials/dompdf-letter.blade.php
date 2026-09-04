{{--
    Dompdf's copy of the letters' sheet.

    Included only for the pure-PHP engine, which is what production runs where there is no Node. Its text
    runs measurably taller than Chrome's — the same difference `dompdf-payslip` exists for — and the letters
    are sized to hold exactly one page, so the two cannot share one set of numbers. Chrome gets the airier
    rhythm; this trims it back to what Dompdf can fit, and both come out as one page.

    Everything here is spacing. Not one figure, sentence or field is dropped for the sake of the engine: a
    certificate that said less on a server without Node would be the wrong trade.

    Loaded last so it wins.
--}}
<style>
    body { font-size: 9pt; line-height: 1.38; }

    .sheet { padding: 10mm 16mm 44mm; }

    .meta { margin-top: 8pt; }

    h1.to-whom { margin: 8pt 0 6pt; }

    .subject { margin-bottom: 7pt; }

    p { margin: 0 0 7pt; }

    table.details, table.roles { margin: 3pt 0 8pt; font-size: 9pt; }
    table.details th, table.details td,
    table.roles th, table.roles td { padding: 2.5pt 6pt; }

    /* The signature sits a touch higher: Dompdf's `fixed` blocks measure from the paper edge and its line
       boxes are taller, so the same 22mm left the seal touching the bar. */
    .sign { bottom: 24mm; }

    .seal-image { max-width: 64px; max-height: 50px; }

    .footer-bar { padding: 8pt 16mm; font-size: 7pt; }
</style>
