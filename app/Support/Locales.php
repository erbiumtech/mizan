<?php

namespace App\Support;

use App\Modules\Core\Models\User;

/**
 * Which language the interface and the documents are in —
 * docs/saas-launch-plan.md §8.10.
 *
 * The interface locale is a **user** preference with a company default: a
 * Karachi office may run in English while one person reads Urdu. A document's
 * locale is a **company** decision and may differ per kind, because the reader
 * of a payslip is not the reader of a bank letter.
 */
class Locales
{
    /** @var array<string, array{label: string, native: string, rtl: bool}> */
    public const AVAILABLE = [
        'en' => ['label' => 'English', 'native' => 'English', 'rtl' => false],
        'ur' => ['label' => 'Urdu', 'native' => 'اردو', 'rtl' => true],
    ];

    /** The documents that leave the company, each able to carry its own language. */
    public const DOCUMENT_KINDS = [
        'payslip' => 'Payslips',
        'letter' => 'Letters and certificates',
        'invoice' => 'Invoices, quotations and statements',
        'cheque' => 'Cheques',
        'report' => 'Scheduled report deliveries',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::AVAILABLE)->map(fn (array $l): string => $l['native'] === $l['label'] ? $l['label'] : $l['label'].' — '.$l['native'])->all();
    }

    public static function isRtl(?string $locale): bool
    {
        return (bool) (self::AVAILABLE[(string) $locale]['rtl'] ?? false);
    }

    public static function supported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::AVAILABLE);
    }

    /**
     * The interface locale: the person's own, then the company's, then the
     * application's — docs/saas-launch-plan.md §8.10.
     */
    public static function forUser(?User $user = null): string
    {
        $user ??= auth()->user();
        $own = $user instanceof User ? ($user->preferences['locale'] ?? null) : null;

        if (self::supported($own)) {
            return (string) $own;
        }

        return self::company();
    }

    /** The company's interface default, or the application's. */
    public static function company(): string
    {
        $company = function_exists('setting') ? setting('company.locale') : null;

        return self::supported($company) ? (string) $company : (string) config('app.locale', 'en');
    }

    /**
     * A document's language — the kind's setting, the company's document default,
     * then English. English stays the default at every level: the reader of a
     * payslip is often a bank, of a letter a next employer.
     */
    public static function forDocument(?string $kind = null): string
    {
        if ($kind !== null) {
            $own = setting("documents.locale.{$kind}");

            if (self::supported($own)) {
                return (string) $own;
            }
        }

        $default = setting('documents.locale');

        return self::supported($default) ? (string) $default : 'en';
    }

    /** The best match from an Accept-Language header, for the mobile client. */
    public static function fromAcceptLanguage(?string $header): ?string
    {
        foreach (explode(',', (string) $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            $base = explode('-', $tag)[0];

            if (self::supported($base)) {
                return $base;
            }
        }

        return null;
    }
}
