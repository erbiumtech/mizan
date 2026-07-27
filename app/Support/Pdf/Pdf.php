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
}
