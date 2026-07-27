{{--
    Dompdf overrides for the invoice sheet. Two problems to undo:

      * `.header` is a flex row (invoice meta left, bill-to right); without
        flexbox the two blocks stack.
      * `.totals` is pushed right with `margin-left: auto`, which Dompdf does
        not resolve on a table — it ends up flush left.

    Floats were avoided deliberately: a floated totals table lets the memo
    paragraph wrap alongside it. Fixed offsets are deterministic here.
--}}
<style>
    .header { display: block !important; font-size: 0 !important; }

    .header > div {
        display: inline-block !important;
        vertical-align: top !important;
        width: 49% !important;
        font-size: 12px !important;
    }

    /* justify-content: space-between — second column right-aligned. */
    .header > div + div { text-align: right !important; }

    /* Stands in for `margin-left: auto` on a 40%-wide table. */
    .totals { margin-left: 60% !important; }
</style>
