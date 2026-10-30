<?php

namespace App\Support;

/**
 * Whether a push endpoint is somewhere this server may be made to send to — docs/desktop-mobile-plan.md A3.
 *
 * A subscription's endpoint is a URL the browser hands us and we later POST to from the server. Anybody
 * with a token can register one, so without this an endpoint of `https://10.0.0.5/…` turns every
 * notification into a request against the internal network. The rule is the one
 * WorkChannelSubscription applies to Teams webhooks, generalised: https only, a real hostname, and nothing
 * that names this machine or a private network — checked by name at registration and by resolved address
 * at send time, because a public name can be pointed at a private address between the two.
 */
final class PushEndpoints
{
    /** @var array<int, string> */
    private const FORBIDDEN_HOSTS = ['localhost', 'localhost.localdomain', 'broadcasthost', 'metadata.google.internal'];

    /** @var array<int, string> */
    private const FORBIDDEN_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa', '.in-addr.arpa', '.ip6.arpa'];

    /** The shape and name check, for registration. */
    public static function isAcceptable(?string $url): bool
    {
        if (blank($url) || mb_strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (in_array($host, self::FORBIDDEN_HOSTS, true) || ! str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicAddress($host);
        }

        return true;
    }

    /**
     * The address check, for the moment of sending: every address the host resolves to must be public.
     * A name that resolves to nothing is refused too — a push service that has vanished is not somewhere
     * to keep trying, and a failed lookup is how a rebinding attack would first look.
     */
    public static function resolvesPublicly(?string $url): bool
    {
        if (! self::isAcceptable($url)) {
            return false;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicAddress($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = array_values(array_filter(array_map(fn (array $r): ?string => $r['ip'] ?? $r['ipv6'] ?? null, $records)));

        if ($addresses === []) {
            $v4 = gethostbyname($host);
            $addresses = $v4 !== $host ? [$v4] : [];
        }

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    public static function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
