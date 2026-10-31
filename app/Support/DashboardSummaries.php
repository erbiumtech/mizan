<?php

namespace App\Support;

use Closure;

/**
 * The dashboard's summary cards — the row of large figures at the top of the Material dashboard.
 *
 * Each module that has a figure worth leading with registers it from its own service provider, the way it
 * registers a headline stat with `DashboardStats`. Core's `DashboardSummaryCards` widget only draws what is
 * here, so it names no module and the module boundary holds.
 *
 * A card has two closures because the widget must decide whether to exist without touching the database:
 * `visible` is a licence and permission check, run while the dashboard decides which widgets to mount,
 * and `value` is the query, run only when the lazy widget loads. A visible card whose value comes back
 * null is skipped.
 */
class DashboardSummaries
{
    /** The tonal container each card is drawn on, in the order the design used them. */
    public const TONES = ['primary', 'secondary', 'error', 'tertiary'];

    /** @var array<int, array{key: string, sort: int, visible: Closure, value: Closure}> */
    private static array $cards = [];

    /**
     * @param  Closure(): bool  $visible
     * @param  Closure(): (array{label: string, value: string, link?: ?string, url?: ?string, tone?: string}|null)  $value
     */
    public static function register(string $key, Closure $visible, Closure $value, int $sort = 100): void
    {
        self::$cards = array_values(array_filter(self::$cards, fn (array $card): bool => $card['key'] !== $key));
        self::$cards[] = ['key' => $key, 'sort' => $sort, 'visible' => $visible, 'value' => $value];
    }

    /** Whether any card would be shown — no queries, so the widget can answer `canView()` with it. */
    public static function anyVisible(): bool
    {
        foreach (self::$cards as $card) {
            if (($card['visible'])()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cards to draw, in order, each with a tone.
     *
     * @return array<int, array{key: string, label: string, value: string, link: ?string, url: ?string, tone: string}>
     */
    public static function resolve(): array
    {
        $cards = self::$cards;
        usort($cards, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $resolved = [];

        foreach ($cards as $card) {
            if (! ($card['visible'])()) {
                continue;
            }

            $value = ($card['value'])();

            if ($value === null) {
                continue;
            }

            $tone = $value['tone'] ?? self::TONES[count($resolved) % count(self::TONES)];

            $resolved[] = [
                'key' => $card['key'],
                'label' => $value['label'],
                'value' => $value['value'],
                'link' => $value['link'] ?? null,
                'url' => $value['url'] ?? null,
                'tone' => in_array($tone, self::TONES, true) ? $tone : 'primary',
            ];
        }

        return $resolved;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_column(self::$cards, 'key');
    }

    public static function flush(): void
    {
        self::$cards = [];
    }
}
