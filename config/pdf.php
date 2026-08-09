<?php

return [

    /*
     * How PDFs are rendered.
     *
     *  browsershot — headless Chrome via spatie/browsershot. Highest fidelity
     *                (real CSS engine, flex/grid) but needs Node + Puppeteer.
     *  dompdf      — pure PHP. No Node, no Chrome; supports a subset of CSS
     *                (no flex/grid, no JavaScript).
     *  auto        — browsershot when a usable Node binary is present,
     *                otherwise dompdf. Lets the same build run on a dev box
     *                with Node and a production server without it.
     */
    'driver' => env('PDF_DRIVER', 'auto'),

    /*
     * How long headless Chrome gets to render one document.
     *
     * Browsershot's own default is 60 seconds, which is generous for a payslip and
     * not generous at all for a loaded machine: the timeout is wall-clock, so a
     * busy CPU is what actually spends it. The test suite raises this (see
     * phpunit.xml) because it is the only place a real browser is launched
     * alongside a thousand other tests, and a rendering timeout there says nothing
     * about the code.
     */
    'timeout' => (int) env('PDF_TIMEOUT', 60),

    /*
     * Paper defaults applied when a caller does not specify them.
     */
    'paper' => [
        'format' => env('PDF_FORMAT', 'a4'),
        'orientation' => env('PDF_ORIENTATION', 'portrait'),
    ],

    'dompdf' => [
        /*
         * Remote assets (images, stylesheets) must be enabled for logos served
         * over http(s) — asset() URLs in the templates. Keep the templates
         * pointing at local files where possible; Dompdf fetches remote ones
         * synchronously and slowly.
         */
        'options' => [
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'dpi' => 96,
            'defaultPaperSize' => env('PDF_FORMAT', 'a4'),

            /*
             * Those remote fetches are synchronous and, by default, unbounded:
             * one unreachable image host stalls a PDF for the whole of PHP's
             * default_socket_timeout (60s). Fail fast and render the sheet with
             * the image missing instead.
             */
            'httpContext' => [
                'http' => [
                    'timeout' => env('PDF_REMOTE_TIMEOUT', 5),
                    'follow_location' => 1,
                ],
            ],
        ],
    ],
];
