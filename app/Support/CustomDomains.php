<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * A company's own host — docs/saas-launch-plan.md §8.8.
 *
 * What a custom domain changes is the host, the branding and the front door,
 * never the path: `hr.acme.pk/admin/acme/…` is the URL. This class owns the
 * three facts the rest of the application asks for — which hosts to trust,
 * which company a host names, and whether a domain a customer typed actually
 * points at us, because a domain nobody controls must not be claimable.
 */
class CustomDomains
{
    private const CACHE_KEY = 'custom-domains:hosts';

    /** @var array<string, array<int, string>>|null domain => CNAME targets and addresses, for tests */
    private static ?array $fakeDns = null;

    /** @var array<string, Company|null> per-request memo */
    private static array $byHost = [];

    public static function platformHost(): string
    {
        return strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
    }

    /** Where the customer's CNAME should point. */
    public static function cnameTarget(): string
    {
        return static::platformHost();
    }

    /** Lowercase host, scheme and path stripped; null when it is not a usable host of somebody else's. */
    public static function normalise(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));

        if ($domain === '') {
            return null;
        }

        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }

        $domain = trim(explode('/', $domain)[0], ". \t");

        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            return null;
        }

        $platform = static::platformHost();

        if ($domain === $platform || str_ends_with($domain, '.'.$platform)) {
            return null;
        }

        return $domain;
    }

    /** The platform host and every company's domain — what TrustHosts accepts. */
    public static function trustedHosts(): array
    {
        $domains = Cache::rememberForever(self::CACHE_KEY, fn (): array => Company::query()
            ->whereNotNull('domain')
            ->orderBy('domain')
            ->pluck('domain')
            ->map(fn ($d): string => strtolower((string) $d))
            ->all());

        return array_values(array_unique(array_merge([static::platformHost()], $domains)));
    }

    public static function companyForHost(?string $host): ?Company
    {
        $host = strtolower(trim((string) $host));

        if ($host === '' || $host === static::platformHost() || ! in_array($host, static::trustedHosts(), true)) {
            return null;
        }

        return static::$byHost[$host] ??= Company::query()->where('domain', $host)->first();
    }

    /** The company the current request's host names, if any — the login page's answer to "whose front door". */
    public static function hostCompany(): ?Company
    {
        if (! app()->bound('request') || app('request') === null) {
            return null;
        }

        $company = request()->attributes->get('hostCompany');

        return $company instanceof Company ? $company : static::companyForHost(request()->getHost());
    }

    /**
     * Set or clear a company's domain. Refused on a plan without the feature, for a
     * host that is not a host, and for one whose DNS does not point here.
     *
     * @throws InvalidArgumentException
     */
    public static function claim(Company $company, ?string $domain): Company
    {
        if (trim((string) $domain) === '') {
            $company->forceFill(['domain' => null])->save();
            static::flush();

            return $company;
        }

        if (! Plans::allowsCustomDomain($company->plan)) {
            throw new InvalidArgumentException('A custom domain needs the '.Plans::label(
                collect(array_keys(Plans::all()))->first(fn (string $p): bool => Plans::allowsCustomDomain($p)) ?? 'business'
            ).' plan.');
        }

        $host = static::normalise($domain);

        if ($host === null) {
            throw new InvalidArgumentException("\"{$domain}\" is not a domain this can answer on. Use a host like hr.yourcompany.pk.");
        }

        $owner = Company::query()->where('domain', $host)->whereKeyNot($company->getKey())->first();

        if ($owner !== null) {
            throw new InvalidArgumentException("{$host} is already in use by another company.");
        }

        if (! static::resolvesToUs($host)) {
            throw new InvalidArgumentException(
                "{$host} does not point here yet. Create a CNAME record {$host} → ".static::cnameTarget().' and try again once it has propagated.'
            );
        }

        $company->forceFill(['domain' => $host])->save();
        static::flush();

        return $company;
    }

    /** True when the domain's CNAME is the platform host, or its addresses are the platform host's. */
    public static function resolvesToUs(string $domain): bool
    {
        $theirs = static::targetsOf($domain);

        if (in_array(static::platformHost(), $theirs, true)) {
            return true;
        }

        $ours = array_filter(static::targetsOf(static::platformHost()), fn (string $t): bool => filter_var($t, FILTER_VALIDATE_IP) !== false);
        $addresses = array_filter($theirs, fn (string $t): bool => filter_var($t, FILTER_VALIDATE_IP) !== false);

        return $ours !== [] && array_intersect($addresses, $ours) !== [];
    }

    /** CNAME targets and A/AAAA addresses of a host, lowercase, or the faked answer in tests. */
    private static function targetsOf(string $host): array
    {
        if (static::$fakeDns !== null) {
            return array_map('strtolower', static::$fakeDns[strtolower($host)] ?? []);
        }

        $targets = [];

        foreach (@dns_get_record($host, DNS_CNAME | DNS_A | DNS_AAAA) ?: [] as $record) {
            $targets[] = $record['target'] ?? $record['ip'] ?? $record['ipv6'] ?? null;
        }

        $targets = array_values(array_filter(array_map(fn ($t) => $t === null ? null : strtolower(rtrim((string) $t, '.')), $targets)));

        if ($targets === [] && is_array($resolved = @gethostbynamel($host))) {
            $targets = $resolved;
        }

        return $targets;
    }

    /** @param  array<string, array<int, string>>|null  $answers  host => CNAME targets and addresses */
    public static function fakeDns(?array $answers): void
    {
        static::$fakeDns = $answers === null ? null : array_change_key_case($answers, CASE_LOWER);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        static::$byHost = [];
    }
}
