{{--
    Dompdf (pure PHP, used when the server has no Node) implements no flexbox,
    so every `display: flex` row in the payslip collapses into one run of text.
    These overrides re-express those rows with sized inline-blocks, which Dompdf
    handles predictably. CSS tables were tried first (they trip its cellmap
    inside the earnings/deductions table) and so were floats (its `:after`
    clearfix support is unreliable, so rows overlapped). Loaded last so it wins,
    and only for Dompdf — Chrome keeps the original flex layout.
--}}
<style>
    {{-- The base sheet's `@page { margin: 0 }` is deliberate: the green footer
         and the dashed header rule bleed to the paper edge. Keep it, and get the
         inner margins from `.container` as the design intends. --}}

    /* The base sheet pins html/body and the wrapper to 210mm × 297mm; with
       Dompdf's A4 content box that overflows the page. Let the boxes size
       themselves and paginate. */
    html, body { width: auto !important; height: auto !important; }

    .payslip-wrapper { width: auto !important; height: auto !important; }

    /* Dompdf implements no `box-sizing`, so the base `width: 100%` plus 50px of
       side padding overflows by exactly that padding. Width `auto` keeps the
       padding inside the page instead — narrower than Chrome's 50px so the
       widest rows still fit. */
    .container { width: auto !important; padding: 34px 34px 24px 34px !important; }

    .header,
    .logo-section,
    .info-grid,
    .info-row,
    .attendance-box,
    .att-row,
    .line-item,
    .totals-row,
    .net-salary-box,
    .signatures,
    .footer { display: block !important; }

    /* --- header: mark + wordmark left, address/title right --- */
    .logo-section { display: inline-block !important; vertical-align: bottom !important; width: 48% !important; }
    .bars { display: inline-block !important; vertical-align: bottom !important; height: 30px !important; margin-right: 8px !important; }
    .bar { display: inline-block !important; vertical-align: bottom !important; margin-right: 2px !important; }
    .company-text { display: inline-block !important; vertical-align: bottom !important; }
    .header-right { display: inline-block !important; vertical-align: bottom !important; width: 50% !important; text-align: right !important; }

    /* --- two-column blocks --- */
    .info-grid,
    .attendance-box,
    .signatures { font-size: 0 !important; }

    .info-col,
    .att-col { display: inline-block !important; vertical-align: top !important; width: 49% !important; font-size: 12px !important; }

    /* --- label / value pairs --- */
    .info-row,
    .att-row { padding-bottom: 7px !important; }

    .info-row .label,
    .att-row .label { display: inline-block !important; width: 52% !important; vertical-align: middle !important; }

    .info-row .value { display: inline-block !important; width: 46% !important; text-align: right !important; vertical-align: middle !important; }

    .att-row .att-box { display: inline-block !important; width: 20% !important; vertical-align: middle !important; }

    /* --- earnings / deductions line items --- */
    .line-item > span:first-child,
    .totals-row > span:first-child,
    .net-salary-box > span:first-child { display: inline-block !important; width: 58% !important; }

    .line-item > span:last-child,
    .totals-row > span:last-child,
    .net-salary-box > span:last-child { display: inline-block !important; width: 40% !important; text-align: right !important; }

    /* --- signatures --- */
    .signature-block { display: inline-block !important; vertical-align: bottom !important; width: 49% !important; text-align: center !important; font-size: 12px !important; min-height: 0 !important; }

    /* Dompdf cannot pin an absolutely positioned footer to the page bottom in
       a way that survives pagination; keep it in flow instead. */
    /* Side padding matches `.container` above so the footer text lines up with
       the body copy while the green band itself still bleeds to the edge. */
    .footer { position: static !important; width: auto !important; padding: 12px 34px !important; margin-top: 20px !important; font-size: 0 !important; }
    .footer > div { display: inline-block !important; vertical-align: top !important; width: 49% !important; font-size: 10px !important; }
    .footer-right { text-align: right !important; }
</style>
