{{--
    Dompdf's corrections to pdfs/mpr-report.

    The template is written for both engines — tables and blocks, no flexbox, grid, calc() or script —
    so this is only what Dompdf still does differently:

    - It does not repeat a table's thead/tfoot on each page, so the spacer rows that keep Chrome's
      content off the fixed footer reserve nothing here, and it cannot break one giant table cell
      across pages: a report longer than a page came out as blank pages before the content. So the
      page scaffold becomes plain blocks, and Dompdf's own idiom takes over — real page margins, with
      the footer placed into the bottom one by a negative offset.
    - Its letter-spacing rounds badly at 11–12px, and the uppercase labels smear together.
--}}
<style>
    @page {
        size: a4 {{ ($mode ?? 'single') === 'comparison' ? 'landscape' : 'portrait' }};
        margin: 12mm 15mm 30mm 15mm !important;
    }

    .page, .page-body, .page-row, .page-cell { display: block !important; width: 100% !important; }
    .page-head, .page-foot { display: none !important; }

    .footer { bottom: -22mm !important; }

    .eyebrow, .badge { letter-spacing: 0 !important; }
</style>
