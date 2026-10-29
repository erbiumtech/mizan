<?php

namespace App\Support\Pdf;

/**
 * Entry point for PDF generation. Mirrors spatie/laravel-pdf's `Pdf::view()`
 * so call sites barely change, but resolves the rendering engine at runtime
 * (see {@see PdfDocument::driver()}).
 */
class Pdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function view(string $view, array $data = []): PdfDocument
    {
        return new PdfDocument($view, $data);
    }

    /**
     * A document of a named kind — docs/saas-launch-plan.md §8.10 — rendered in the
     * language that kind is set to: the kind's setting, the company's document
     * default, then English.
     */
    public static function document(string $kind, string $view, array $data = []): PdfDocument
    {
        return (new PdfDocument($view, $data))->documentKind($kind);
    }
}
