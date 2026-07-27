{{--
    Dompdf implements no flexbox, so the two NIC scans in `.nic-imgs` stack and
    each stretches to the full page width. Re-express the row as sized
    inline-blocks, which Dompdf lays out predictably. Only loaded for Dompdf —
    Chrome keeps the original flex layout.
--}}
<style>
    /* `gap` has no inline-block equivalent; the widths below leave the gutter. */
    .nic-imgs { font-size: 0 !important; }

    .nic-imgs > div {
        display: inline-block !important;
        vertical-align: top !important;
        width: 48% !important;
        font-size: 12px !important;
    }

    .nic-imgs > div + div { margin-left: 3% !important; }

    /* border-radius on a replaced element is ignored by Dompdf anyway. */
    .nic-imgs img { width: 100% !important; }
</style>
