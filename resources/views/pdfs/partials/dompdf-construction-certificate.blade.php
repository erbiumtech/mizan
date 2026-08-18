{{--
    Dompdf overrides for the payment certificate. Three problems to undo, and one deliberate non-problem.

      * `.masthead`, `.parties` and `.signatures` are flex rows; without flexbox their children stack, so the
        certificate number ends up above the job instead of beside it.
      * `justify-content: space-between` has to become explicit alignment on the second child.
      * `.signatures` is three columns rather than two, so its children need a third of the width each and
        inline-block spacing has to be killed with `font-size: 0` on the parent — the same technique
        `dompdf-invoice` uses, which is where this came from.

    The **non**-problem is pagination: nothing here touches it. Every page break in this document is a
    `page-break-before` on a div that PHP decided to emit, which Dompdf and Chrome honour identically. That
    is the point of chunking server-side — the fallback is a stylesheet, never a different document.
--}}
<style>
    .masthead, .parties, .signatures { display: block !important; font-size: 0 !important; }

    .masthead > div,
    .parties > div {
        display: inline-block !important;
        vertical-align: top !important;
        width: 49% !important;
        font-size: 10px !important;
    }

    /* justify-content: space-between — the second block right-aligned. */
    .masthead > div + div,
    .parties > div + div { text-align: right !important; }

    .signatures > div {
        display: inline-block !important;
        vertical-align: top !important;
        width: 30% !important;
        font-size: 9px !important;
        /* Dompdf collapses adjacent inline-blocks with no gap, so the space between signature blocks is a
           margin rather than the flex parent's justification. */
        margin-right: 3% !important;
    }

    /* A fixed-layout table needs its widths honoured rather than inferred from content, and Dompdf only
       does that when the table itself is told the layout twice — once in the class, once here. */
    .schedule { table-layout: fixed !important; width: 100% !important; }
</style>
