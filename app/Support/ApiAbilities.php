<?php

namespace App\Support;

/**
 * What an API key is allowed to do — docs/saas-launch-plan.md §14.9.
 *
 * **There is exactly one ability today, and this exists anyway.** `api` is checked by
 * `App\Http\Middleware\RequireTokenAbility` on every request, so a token without it
 * reaches nothing; the screen that issues keys has to offer it by name, and the day a
 * second ability arrives the screen must offer that too without being rewritten.
 *
 * A list rather than a free-text field, and that is the security property: a scope is
 * something the application understands, and a key carrying `payroll:write` because
 * somebody typed it would be a key that looks restricted and is not.
 */
final class ApiAbilities
{
    /** @var array<string, string> */
    private static array $abilities = [
        'api' => 'Use the API',
    ];

    public static function register(string $key, string $label): void
    {
        self::$abilities[$key] = $label;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$abilities;
    }

    /**
     * The abilities a key may actually be given, out of whatever was asked for.
     *
     * Always includes `api`: a key without it is refused by the middleware on every
     * request, so issuing one would be issuing something that cannot work.
     *
     * @param  array<int, string>  $wanted
     * @return array<int, string>
     */
    public static function sanitise(array $wanted): array
    {
        $kept = array_values(array_intersect(array_keys(self::$abilities), $wanted));

        return array_values(array_unique(['api', ...$kept]));
    }
}
