<?php

namespace App\Support;

/**
 * A company's colours and lettering, as far as a document needs them —
 * docs/saas-launch-plan.md §14.10.
 *
 * **Two colours and a choice of four typefaces, not a design tool.** The things it
 * governs are an ID card, a business card and an email signature; a company that
 * wants more than this wants a designer, and a product that offered more would be
 * offering a worse one than they already have.
 *
 * The typeface is a choice rather than a name, because a PDF has to *have* the font.
 * The four below are what both rendering engines can set without an embedded file,
 * and a free-text font name would produce a card that looks right in the browser and
 * falls back to Times in the PDF.
 */
final class BrandKit
{
    public const DEFAULT_COLOUR = '#0f766e';

    public const DEFAULT_ACCENT = '#111827';

    public const DEFAULT_TYPEFACE = 'sans';

    /** @var array<string, array{label: string, stack: string}> */
    public const TYPEFACES = [
        'sans' => ['label' => 'Sans serif (Helvetica)', 'stack' => "'Helvetica Neue', Helvetica, Arial, sans-serif"],
        'serif' => ['label' => 'Serif (Times)', 'stack' => "'Times New Roman', Times, serif"],
        'slab' => ['label' => 'Slab (Georgia)', 'stack' => "Georgia, 'Times New Roman', serif"],
        'mono' => ['label' => 'Monospace (Courier)', 'stack' => "'Courier New', Courier, monospace"],
    ];

    /** The main colour: the band on a card, the rule under a signature. */
    public static function colour(): string
    {
        return self::hex((string) setting('company.brand.colour'), self::DEFAULT_COLOUR);
    }

    /** The darker one, for text that has to sit on white. */
    public static function accent(): string
    {
        return self::hex((string) setting('company.brand.accent'), self::DEFAULT_ACCENT);
    }

    public static function typeface(): string
    {
        $chosen = (string) setting('company.brand.typeface', self::DEFAULT_TYPEFACE);

        return array_key_exists($chosen, self::TYPEFACES) ? $chosen : self::DEFAULT_TYPEFACE;
    }

    /** The CSS stack for the chosen typeface. */
    public static function fontStack(): string
    {
        return self::TYPEFACES[self::typeface()]['stack'];
    }

    /** @return array<string, string> everything a template needs, in one array */
    public static function forTemplate(): array
    {
        return [
            'colour' => self::colour(),
            'accent' => self::accent(),
            'font' => self::fontStack(),
        ];
    }

    /** @return array<string, string> */
    public static function typefaceOptions(): array
    {
        return array_map(fn (array $face): string => $face['label'], self::TYPEFACES);
    }

    /**
     * A colour, or the shipped one.
     *
     * Validated rather than trusted: this value reaches a `style` attribute in a PDF
     * and in an email signature somebody pastes elsewhere, and "anything a person
     * typed" is not a thing to put there.
     */
    private static function hex(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : $fallback;
    }
}
