<?php

namespace App\Support;

/**
 * What the product does about each law it touches — docs/saas-launch-plan.md §14.10.
 *
 * **This is a list of mechanisms, not a claim of compliance**, and the wording of
 * every entry has to keep it that way. "Holds the FBR salaried slabs per fiscal year
 * and withholds against them" is a true statement about the software. "Complies with
 * the Income Tax Ordinance" is a statement about a company's affairs that no software
 * can make for it, and printing one would be worse than printing nothing: somebody
 * would rely on it.
 *
 * Each module registers its own, so the page shows what *this* company has licensed
 * rather than a brochure. A law covered by a module nobody bought is listed as not
 * covered here, which is the honest answer.
 */
final class StatutoryCoverage
{
    /** @var array<int, array{act: string, jurisdiction: string, does: string, module: ?string, where: ?string}> */
    private static array $entries = [];

    /**
     * @param  string  $act  the instrument, as it is cited
     * @param  string  $does  what the software does about it, in the indicative
     * @param  string|null  $module  the module key that provides it, or null for always
     * @param  string|null  $where  where in the panel it lives
     */
    public static function register(string $act, string $jurisdiction, string $does, ?string $module = null, ?string $where = null): void
    {
        self::$entries[] = compact('act', 'jurisdiction', 'does', 'module', 'where');
    }

    /**
     * Every entry, with whether this company has the module behind it.
     *
     * @return array<int, array{act: string, jurisdiction: string, does: string, module: ?string, where: ?string, available: bool}>
     */
    public static function all(): array
    {
        $entries = array_map(fn (array $entry): array => $entry + [
            'available' => $entry['module'] === null || modules()->enabled($entry['module']),
        ], self::$entries);

        usort($entries, fn (array $a, array $b): int => [$a['jurisdiction'], $a['act']] <=> [$b['jurisdiction'], $b['act']]);

        return $entries;
    }

    public static function flush(): void
    {
        self::$entries = [];
    }
}
