<?php

namespace App\Support;

use App\Support\Contracts\SettingsSection;

/**
 * Sections of the Company Settings screen, contributed by the modules whose settings they are.
 *
 * The last of §9's seven inversions — see `App\Support\Contracts\SettingsSection` and
 * docs/module-packaging-plan.md §9. Core keeps the page; Accounting brings the two sections that needed
 * `Currency`, `JournalEntryLine` and `Account`.
 *
 * **Sorts, not registration order.** The page's reading order is a decision — currency first because it is
 * what every other figure means, the status page last because it is the least consequential — and provider
 * boot order is alphabetical accident. So contributed sections carry a sort and the page interleaves them
 * with its own by number, the same arrangement `App\Support\DashboardStats` uses for the dashboard.
 *
 * Registered from the contributing module's provider, which is where the class may be named freely.
 */
class SettingsSections
{
    /** @var array<string, array{section: class-string<SettingsSection>, sort: int}> */
    private static array $sections = [];

    /** @var array<string, SettingsSection> */
    private static array $resolved = [];

    /**
     * @param  class-string<SettingsSection>  $section
     * @param  int  $sort  lower sorts first, interleaved with the page's own sections
     */
    public static function register(string $key, string $section, int $sort = 100): void
    {
        self::$sections[$key] = ['section' => $section, 'sort' => $sort];
        unset(self::$resolved[$key]);

        uasort(self::$sections, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
    }

    /**
     * Every contributed section with the sort it asked for, in order.
     *
     * @return array<int, array{sort: int, section: SettingsSection}>
     */
    public static function sorted(): array
    {
        $sorted = [];

        foreach (self::$sections as $key => $entry) {
            $sorted[] = [
                'sort' => $entry['sort'],
                'section' => self::$resolved[$key] ??= app($entry['section']),
            ];
        }

        return $sorted;
    }

    /**
     * The state every contributed section needs on mount, merged.
     *
     * @return array<string, mixed>
     */
    public static function fill(): array
    {
        $state = [];

        foreach (self::sorted() as $entry) {
            $state = array_merge($state, $entry['section']->fill());
        }

        return $state;
    }

    /**
     * Let every contributed section write its own settings.
     *
     * @param  array<string, mixed>  $state
     */
    public static function save(array $state): void
    {
        foreach (self::sorted() as $entry) {
            $entry['section']->save($state);
        }
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::$sections);
    }

    public static function flush(): void
    {
        self::$sections = [];
        self::$resolved = [];
    }
}
