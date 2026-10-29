<?php

namespace App\Support;

use Closure;

/**
 * What a company may insist on before somebody is let in — docs/saas-launch-plan.md §14.8.
 *
 * **One rule, and it is the reason this is a registry rather than a list of ifs: the
 * gate never blocks for a check the company cannot perform.** A phone check needs a
 * WhatsApp provider; an address check needs a letter template the company has
 * written. Where the means is missing the check is not offered, is not required, and
 * cannot be the reason anybody is refused — whatever the setting says. A company that
 * switched phone checks on and then lost its WhatsApp credentials must not lock its
 * own staff out of payroll.
 *
 * Checks are registered by the module that owns the thing being checked — Employees
 * for the phone and the address, Lifecycle for the documents — so this knows what
 * exists without naming any of them. Same shape as {@see TenantFileAccess} and
 * {@see DocumentVerification}.
 *
 * The user is typed `object` on purpose. This is shared ground and must not name a
 * module's model; each closure knows what it was given because it registered itself.
 */
final class VerificationChecks
{
    /** @var array<string, array{label: string, help: string, available: Closure, outstanding: Closure, sort: int}> */
    private static array $checks = [];

    /**
     * @param  Closure(): bool  $available  whether the company can perform this check at all
     * @param  Closure(?object): ?string  $outstanding  what is missing for this user, or null when nothing is
     */
    public static function register(string $key, string $label, string $help, Closure $available, Closure $outstanding, int $sort = 100): void
    {
        self::$checks[$key] = compact('label', 'help', 'available', 'outstanding', 'sort');
    }

    /** @return array<string, array{label: string, help: string, available: Closure, outstanding: Closure, sort: int}> */
    public static function all(): array
    {
        $checks = self::$checks;
        uasort($checks, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $checks;
    }

    /**
     * The checks this company could actually perform, as key => label.
     *
     * What the settings screen offers. A check the company cannot perform is absent
     * rather than disabled, because a switch that does nothing is worse than no switch.
     *
     * @return array<string, string>
     */
    public static function offered(): array
    {
        return collect(self::all())
            ->filter(fn (array $check): bool => (bool) ($check['available'])())
            ->map(fn (array $check): string => $check['label'])
            ->all();
    }

    /**
     * The keys a company has switched on and can perform.
     *
     * **Asked on every page of the panel, so it does as little as it can.** A company
     * that requires nothing — the shipped state, and most companies — answers from one
     * cached setting and touches no `available()` closure at all; those cost queries,
     * because "can we send WhatsApp" and "is there a letter template" are questions
     * about the database. A company that requires one check pays for that one.
     *
     * @return array<int, string>
     */
    public static function required(): array
    {
        $wanted = setting('verification.required', []);
        $wanted = is_array($wanted) ? $wanted : [];

        if ($wanted === []) {
            return [];
        }

        return array_keys(array_filter(
            array_intersect_key(self::all(), array_flip($wanted)),
            fn (array $check): bool => (bool) ($check['available'])(),
        ));
    }

    /**
     * What this person still has to do before the gate opens, as key => reason.
     *
     * Empty for everybody when the company has required nothing, which is the shipped
     * state and the state of every company that never opens the screen.
     *
     * @return array<string, string>
     */
    public static function outstandingFor(?object $user): array
    {
        $outstanding = [];

        foreach (self::required() as $key) {
            $reason = (self::$checks[$key]['outstanding'])($user);

            if (is_string($reason) && $reason !== '') {
                $outstanding[$key] = $reason;
            }
        }

        return $outstanding;
    }

    /** @return array<string, string> key => help, for the screen that explains them */
    public static function help(): array
    {
        return collect(self::all())->map(fn (array $check): string => $check['help'])->all();
    }

    public static function flush(): void
    {
        self::$checks = [];
    }
}
