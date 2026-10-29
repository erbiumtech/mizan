<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use Closure;
use Filament\Facades\Filament;

/**
 * Tamper-evident documents — docs/saas-launch-plan.md §7.5.
 *
 * The letters store nothing, deliberately, so verification cannot look up a
 * stored copy. It recomputes. The facts a document prints are hashed with the
 * app key; the hash travels in the QR as part of a URL; the verify page re-derives
 * the hash from the live record and answers *matches*, *does not match* or *no
 * such document*, showing only the facts that were printed.
 *
 * Each module registers the kinds it prints, as a closure from a record id to the
 * printed facts (or null when there is no such record) — the same registry shape
 * TenantFileAccess uses, so Core knows the mechanism and nothing of the modules.
 *
 * Two gates on the public page, like every public page here: a per-company
 * setting and a token, both on Company Settings. With the setting off, no QR is
 * printed at all, so a document never carries a link that answers 404.
 */
final class DocumentVerification
{
    public const MATCHES = 'matches';

    public const MISMATCH = 'mismatch';

    public const MISSING = 'missing';

    /** @var array<string, Closure(int): ?array<string, mixed>> */
    private static array $kinds = [];

    /** @param  Closure(int): ?array<string, mixed>  $facts */
    public static function register(string $kind, Closure $facts): void
    {
        self::$kinds[$kind] = $facts;
    }

    /** @return array<int, string> */
    public static function kinds(): array
    {
        return array_keys(self::$kinds);
    }

    public static function enabled(): bool
    {
        return (bool) setting('documents.verification.enabled', false)
            && filled(setting('documents.verification.token'));
    }

    /**
     * The hash the QR carries: the printed facts, in a fixed order, under the app key.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function hashFor(string $kind, int $id, array $facts): string
    {
        ksort($facts);

        $payload = json_encode([$kind, $id, self::normalise($facts)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return substr(hash_hmac('sha256', (string) $payload, (string) config('app.key')), 0, 32);
    }

    /**
     * The verify URL for a document, or null when the company has not switched
     * verification on — in which case no QR is printed.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function urlFor(string $kind, int $id, array $facts): ?string
    {
        // Inside the panel the tenant is Filament's; on a public page or a job it is spatie's.
        $company = Company::current();

        if ($company === null) {
            $tenant = Filament::getTenant();
            $company = $tenant instanceof Company ? $tenant : null;
        }

        if ($company === null || ! self::enabled() || ! isset(self::$kinds[$kind])) {
            return null;
        }

        return route('documents.verify', [
            'company' => $company->slug,
            'token' => (string) setting('documents.verification.token'),
            'kind' => $kind,
            'id' => $id,
            'hash' => self::hashFor($kind, $id, $facts),
        ]);
    }

    /**
     * The QR for a document as a data URI, or null when verification is off.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function qrFor(string $kind, int $id, array $facts): ?string
    {
        $url = self::urlFor($kind, $id, $facts);

        return $url === null ? null : QrCode::dataUri($url);
    }

    /**
     * Answer a scanned code against the live record. Run with the company current.
     *
     * @return array{verdict: string, facts: array<string, mixed>}
     */
    public static function verify(string $kind, int $id, string $hash): array
    {
        $facts = isset(self::$kinds[$kind]) ? (self::$kinds[$kind])($id) : null;

        if ($facts === null) {
            return ['verdict' => self::MISSING, 'facts' => []];
        }

        if (! hash_equals(self::hashFor($kind, $id, $facts), $hash)) {
            // The verdict and nothing else: what the record says now is not for
            // whoever holds a document that disagrees with it.
            return ['verdict' => self::MISMATCH, 'facts' => []];
        }

        return ['verdict' => self::MATCHES, 'facts' => $facts];
    }

    public static function flush(): void
    {
        self::$kinds = [];
    }

    /**
     * Facts as stable strings, so a float that prints as 150000 and one stored as
     * 150000.00 hash the same way.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, string>
     */
    private static function normalise(array $facts): array
    {
        return array_map(function (mixed $value): string {
            if (is_float($value) || is_int($value)) {
                return number_format((float) $value, 2, '.', '');
            }

            return trim((string) $value);
        }, $facts);
    }
}
