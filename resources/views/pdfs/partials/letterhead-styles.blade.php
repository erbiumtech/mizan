{{--
    The payslip's letterhead, as a sheet the letters can share.

    **The look is the payslip's and the markup is not.** The payslip lays its header out with flexbox, which
    is why `partials/dompdf-payslip.blade.php` exists to re-express it for the pure-PHP engine. A letter has
    no reason to inherit that: the same row expressed as a table renders identically in Chrome and in Dompdf,
    so these two partials need no per-engine override at all. Colours, weights and the dashed rule are taken
    from the payslip so the three documents read as one company's paper.

    **The name comes from the letterhead settings, not from the payslip's hardcoded wordmark.** The payslip
    prints "ErbiumTech / SMC-PRIVATE LIMITED" and its address as literal markup; a letter that did the same
    would be wrong for every other company on the installation. Splitting an arbitrary registered name into
    the wordmark's two coloured halves is guesswork, so the accents stay in the bars and the rule.
--}}
<style>
    .lh { width: 100%; border-collapse: collapse; }
    .lh td { padding: 0; vertical-align: bottom; }
    /* Split explicitly, or the address on the right takes the width it wants and wraps the company name
       onto two lines — which is the one thing on a letterhead that must not wrap. */
    .lh td:first-child { width: 56%; }
    .lh-right { width: 44%; }

    .lh-bars { white-space: nowrap; }
    .lh-bar { display: inline-block; width: 9px; background-color: #6cbf4a; margin-right: 4px; }
    .lh-bar-1 { height: 18px; }
    .lh-bar-2 { height: 26px; }
    .lh-bar-3 { height: 36px; background-color: #388e3c; }

    .lh-name { font-size: 15pt; font-weight: bold; color: #111; letter-spacing: 0.2pt; }
    .lh-sub { font-size: 8pt; color: #555; letter-spacing: 0.5pt; font-weight: bold; text-transform: uppercase; }

    .lh-right { text-align: right; line-height: 1.45; }
    .lh-right .line { font-size: 9pt; color: #333; }
    .lh-title { font-size: 13pt; font-weight: bold; color: #388e3c; margin-top: 3pt; }

    .lh-divider { border-top: 1.5pt dashed #9e9e9e; margin: 10pt 0 0; }

    /* The seal: the same employer signature the payslip prints, at the same size. */
    .seal-image { max-width: 100px; max-height: 80px; margin-bottom: 4pt; }

    /*
     * The payslip's green footer, and the reason the sheet has no page margin.
     *
     * The bar bleeds to the paper edge, which it can only do if the page box has no margin of its own —
     * so `@page` is zero and `.sheet` carries the inset instead, exactly as the payslip does. `fixed`
     * rather than the payslip's `absolute`: a letter with an unusually long job history can run to a
     * second page, and a company footer that appeared on page one only would look like a truncated
     * document. Both engines repeat a fixed block per page.
     */
    .footer-bar {
        position: fixed;
        bottom: 0;
        /* Both offsets and no width, deliberately. `width: 210mm` plus 32mm of padding is 242mm on a
           210mm page: the right-hand column ran off the paper and its contact lines were cut in half.
           With `left` and `right` pinned the box is the page and the padding stays inside it. */
        left: 0;
        right: 0;
        background-color: #55a65a;
        color: #fff;
        padding: 9pt 16mm;
        font-size: 7.5pt;
        line-height: 1.45;
    }

    .footer-bar table { width: 100%; border-collapse: collapse; }
    .footer-bar td { padding: 0; vertical-align: top; color: #fff; }
    .footer-bar .right { text-align: right; }
    .footer-bar strong { letter-spacing: 0.3pt; }

    /*
     * The letters' own sheet, shared so the two cannot drift.
     *
     * **Sized to hold one page, on both engines.** Measured rather than guessed. At 10.5pt the income
     * certificate came out 1220px against A4's 1123 at 96dpi; at 10pt Chrome fitted it and Dompdf — whose
     * text metrics run taller — still split it. 9.5pt is what holds both, and it is the size the payslip
     * sets its own tables in. A letter that runs to a second page for one paragraph reads as a document
     * somebody lost the end of.
     *
     * The last stretch of tuning was against Dompdf specifically, and reading its output rather than
     * guessing: its page two carried first the footnote, then the tail of the signature block. Every
     * millimetre below came out of spacing and none out of what the letters say — a certificate that
     * dropped a sentence to fit would be the wrong trade.
     */
    body {
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        font-size: 9.5pt;
        line-height: 1.38;
        color: #111;
        margin: 0;
    }

    /* The inset the page box gave up so the footer could bleed. */
    .sheet { padding: 10mm 16mm 21mm; }

    .meta { width: 100%; margin-top: 7pt; font-size: 9pt; }
    .meta td { padding: 0; vertical-align: top; }
    .meta .right { text-align: right; }

    h1.to-whom {
        font-size: 11.5pt;
        text-align: center;
        text-decoration: underline;
        margin: 7pt 0 6pt;
        letter-spacing: 0.5pt;
    }

    .subject { font-weight: bold; margin-bottom: 6pt; }
    p { margin: 0 0 6pt; text-align: justify; }

    table.details, table.roles { width: 100%; border-collapse: collapse; margin: 3pt 0 8pt; font-size: 9.5pt; }
    table.details th, table.details td,
    table.roles th, table.roles td { border: 0.6pt solid #999; padding: 2pt 7pt; text-align: left; vertical-align: top; }
    table.details th { width: 38%; background: #f2f2f2; font-weight: bold; }
    table.roles thead th { background: #f2f2f2; font-weight: bold; }

    .sign { margin-top: 6pt; }
    .sign .rule { border-top: 0.8pt solid #111; width: 62mm; margin-bottom: 3pt; }
    .sign .who { font-weight: bold; }
    .seal-image { max-width: 66px; max-height: 52px; margin-bottom: 1pt; }

    /* A qualifying line inside a table of facts — the figures' own small print. */
    table.details td.note { font-size: 7.5pt; color: #555; line-height: 1.3; }
</style>
