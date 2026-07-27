{{--
    The MPR sheet gets all of its styling from the Tailwind browser CDN, which
    is a <script> tag. Dompdf runs no JavaScript, so under Dompdf every utility
    class is inert and the report renders as unstyled text.

    Rather than rewrite the template, this is a hand-written shim for exactly
    the utilities it uses (see the class list in the markup — keep the two in
    sync when editing the template). Values match Tailwind v4 defaults:
    spacing unit 0.25rem, slate/fuchsia from the default palette.

    Also restates the flex/grid rows as inline-blocks and unpins the footer,
    neither of which Dompdf supports.
--}}
<style>
    /* --- base ------------------------------------------------------------ */
    body { font-size: 14px; }

    /* Dompdf honours `position: fixed` (repeating the box on every page) but
       resolves no `calc()`, so the negative-offset full-bleed width has to be
       restated. The page box already carries the 15mm side margins. */
    .fixed-print-footer {
        left: 0 !important;
        width: 100% !important;
        height: auto !important;
        bottom: 6mm !important;
        padding-left: 0 !important;
        padding-bottom: 0 !important;
    }

    /* The template has no CSS reset, so Dompdf gives each footer line the
       default 1em paragraph margins and the three-line block balloons to ~18mm.
       Collapse them, then the tfoot gap that keeps flowing content off the
       footer can come down from the 32mm Chrome needs. */
    .fixed-print-footer p { margin: 0 !important; }

    .footer-space { height: 20mm !important; }

    /* --- layout: flex / grid --------------------------------------------- */
    .flex, .grid { display: block !important; }

    /* `flex justify-end` — used only for the logo row. */
    .justify-end { text-align: right !important; }

    .grid-cols-2 { font-size: 0 !important; }

    .grid-cols-2 > * {
        display: inline-block !important;
        vertical-align: top !important;
        width: 47% !important;
        font-size: 14px !important;
    }

    /* Stands in for `gap-6` / `gap-8` between the two columns. */
    .grid-cols-2 > * + * { margin-left: 2% !important; }

    /* Dompdf implements no `box-sizing`, so a percentage width is always the
       *content* width: padding and borders are added on top. `.content-box`
       carries 14px of side padding and a 1px border, and at these page widths
       two 47% columns plus that trim exceed 100% and the second one wraps onto
       its own line. Reserve the difference. */
    .grid-cols-2 > .content-box { width: 44% !important; }

    /* --- spacing: space-y-* (Tailwind's `> * + *` margin) ---------------- */
    .space-y-1\.5 > * + * { margin-top: 6px !important; }
    .space-y-4 > * + * { margin-top: 16px !important; }
    .space-y-6 > * + * { margin-top: 24px !important; }

    /* --- spacing: margin / padding --------------------------------------- */
    .mb-0\.5 { margin-bottom: 2px; }
    .mb-1\.5 { margin-bottom: 6px; }
    .mb-2 { margin-bottom: 8px; }
    .mb-6 { margin-bottom: 24px; }
    .mt-0\.5 { margin-top: 2px; }
    .pt-2 { padding-top: 8px; }
    .pt-4 { padding-top: 16px; }
    .px-1 { padding-left: 4px; padding-right: 4px; }
    .px-3 { padding-left: 12px; padding-right: 12px; }
    .py-1\.5 { padding-top: 6px; padding-bottom: 6px; }

    /* --- sizing ---------------------------------------------------------- */
    .w-full { width: 100%; }
    .w-auto { width: auto; }
    .w-1\/3 { width: 33.333%; }
    .w-1\/4 { width: 25%; }
    .h-11 { height: 44px; }

    /* --- type ------------------------------------------------------------ */
    .text-\[9px\] { font-size: 9px; }
    .text-\[10px\] { font-size: 10px; }
    .text-\[11px\] { font-size: 11px; }
    .text-xs { font-size: 12px; }
    .text-sm { font-size: 13px; }
    .text-base { font-size: 14px; }
    .text-xl { font-size: 20px; }

    .font-medium { font-weight: 500; }
    .font-semibold { font-weight: 600; }
    .font-bold { font-weight: 700; }
    .font-black { font-weight: 900; }

    .uppercase { text-transform: uppercase; }
    .leading-relaxed { line-height: 1.625; }
    .whitespace-pre-line { white-space: pre-line; }

    /* Dompdf ignores letter-spacing on a negative value, so tracking-tight is
       left at normal rather than faked. */
    .tracking-wide { letter-spacing: 0.025em; }
    .tracking-wider { letter-spacing: 0.05em; }

    /* --- colour ---------------------------------------------------------- */
    .text-white { color: #fff; }
    .text-slate-400 { color: #94a3b8; }
    .text-slate-500 { color: #64748b; }
    .text-slate-800 { color: #1e293b; }
    .text-slate-900 { color: #0f172a; }
    .text-fuchsia-500 { color: #d946ef; }
    .text-fuchsia-600 { color: #c026d3; }

    .bg-white { background-color: #fff; }
    .bg-slate-700 { background-color: #334155; }
    /* bg-slate-50/50 — Dompdf has no alpha compositing on backgrounds; use the
       flat colour that the 50% overlay on white resolves to. */
    .bg-slate-50\/50 { background-color: #fafbfc; }

    .border { border-width: 1px; border-style: solid; }
    .border-slate-800 { border-color: #1e293b; }
    .rounded { border-radius: 4px; }

    /* --- inline-block badge (a <span> with padding needs this to size) ---- */
    .grid-cols-2 .bg-slate-700 { display: inline-block !important; }
</style>
