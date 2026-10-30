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
 * **And a tab, since 2026-10-30.** Twenty-eight sections in one column is a page nobody reads to the end
 * of, so they are dealt into five tabs and the sort orders them within one. A module says which tab its
 * settings belong under for the same reason it says where in the order: it is the only thing that knows
 * what they are about.
 *
 * Registered from the contributing module's provider, which is where the class may be named freely.
 */
class SettingsSections
{
    /** @var array<string, array{section: class-string<SettingsSection>, sort: int, tab: string}> */
    private static array $sections = [];

    /** @var array<string, SettingsSection> */
    private static array $resolved = [];

    /**
     * @param  class-string<SettingsSection>  $section
     * @param  int  $sort  lower sorts first, interleaved with the page's own sections
     * @param  string  $tab  which tab of Company Settings this belongs under — see
     *                       `CompanySettings::TABS` for the five and what each is for. Declared
     *                       here beside the sort for the same reason the sort is: the module
     *                       knows what its settings are about, and the page cannot be asked to
     *                       keep a list of every module's keys. An unknown name falls back to
     *                       the first tab rather than hiding the section.
     */
    public static function register(string $key, string $section, int $sort = 100, string $tab = 'company'): void
    {
        self::$sections[$key] = ['section' => $section, 'sort' => $sort, 'tab' => $tab];
        unset(self::$resolved[$key]);

        uasort(self::$sections, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
    }

    /**
     * Every contributed section with the sort and tab it asked for, in order.
     *
     * @return array<int, array{sort: int, tab: string, section: SettingsSection}>
     */
    public static function sorted(): array
    {
        $sorted = [];

        foreach (self::$sections as $key => $entry) {
            $sorted[] = [
                'sort' => $entry['sort'],
                'tab' => $entry['tab'],
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

    /**
     * Which tab each contributed section asked for, keyed by section.
     *
     * For the guard test: a module landing under the wrong heading is the kind of change that is
     * invisible until an administrator cannot find a setting, so the whole map is asserted.
     *
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return array_map(fn (array $entry): string => $entry['tab'], self::$sections);
    }

    public static function flush(): void
    {
        self::$sections = [];
        self::$resolved = [];
    }
}
