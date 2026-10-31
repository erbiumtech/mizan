<?php

namespace App\Support;

/**
 * The providers this application can talk to, contributed by the modules that know how.
 *
 * A registry in the style of `Webhooks` and `SettingsSections` — docs/project-management-plan.md
 * §8.1. The `work` module registers GitHub, Bitbucket and GitLab as `code` providers and Clockify
 * as a `time` provider; a future module registers whatever it integrates without touching this
 * file. What is registered is three strings and a class name: shared code names no module's
 * class, so the driver is resolved from the container by whoever asked, which is the module that
 * registered it.
 *
 * The **kind** is what the rest of the design branches on: a code provider links repositories and
 * yields commits, branches and pull requests; a time provider links a workspace and yields time
 * entries.
 */
class Integrations
{
    public const CODE = 'code';

    public const TIME = 'time';

    /** @var array<string, array{label: string, kind: string, driver: class-string, sort: int}> */
    private static array $providers = [];

    /**
     * @param  string  $key  `github`, `clockify` — what a connection row stores
     * @param  class-string  $driver  the module's own implementation, resolved from the container
     */
    public static function register(string $key, string $label, string $kind, string $driver, int $sort = 100): void
    {
        self::$providers[$key] = compact('label', 'kind', 'driver', 'sort');
    }

    /** @return array<string, array{label: string, kind: string, driver: class-string, sort: int}> */
    public static function all(): array
    {
        $providers = self::$providers;
        uasort($providers, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $providers;
    }

    /** @return array<string, array{label: string, kind: string, driver: class-string, sort: int}> */
    public static function ofKind(string $kind): array
    {
        return array_filter(self::all(), fn (array $provider): bool => $provider['kind'] === $kind);
    }

    public static function has(string $key): bool
    {
        return isset(self::$providers[$key]);
    }

    public static function label(string $key): string
    {
        return self::$providers[$key]['label'] ?? ucfirst($key);
    }

    public static function kind(string $key): ?string
    {
        return self::$providers[$key]['kind'] ?? null;
    }

    /** The driver, built by the container. Null for a provider nobody registered. */
    public static function driver(string $key): ?object
    {
        $class = self::$providers[$key]['driver'] ?? null;

        return $class === null ? null : app($class);
    }

    /** @return array<string, string> key => label, for a form */
    public static function options(?string $kind = null): array
    {
        return collect($kind === null ? self::all() : self::ofKind($kind))
            ->map(fn (array $provider): string => $provider['label'])
            ->all();
    }

    public static function flush(): void
    {
        self::$providers = [];
    }
}
