<?php

namespace App\Modules\Work\Support;

/**
 * Where a card sits, as a string you can always find a gap in.
 *
 * **The alternative is an integer position, and it is why boards feel slow.** With a position
 * column, dragging one card to the top of a backlog of eight hundred rewrites eight hundred rows —
 * inside a transaction, while two other people are dragging. With a string ordered
 * lexicographically, a card moves by being given a value *between* its two new neighbours, which is
 * one row and no contention.
 *
 * Base 36 and midpoints, which is the plain version of what Jira calls LexoRank. Between "a" and
 * "c" is "b"; between "a" and "b" there is no single character, so the string lengthens to "an".
 * Strings grow by a character roughly every log36 moves into the same gap, and a backlog that has
 * been reordered into the same slot a thousand times carries ranks of about four characters.
 */
final class Rank
{
    /** The alphabet, in the order the database sorts it. */
    private const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

    /** What a first card gets: the middle of the space, so there is room on both sides. */
    public const FIRST = 'h';

    /**
     * A rank between two neighbours, either of which may be absent.
     *
     * Absent `$after` means "put it at the top"; absent `$before` means "at the bottom"; both absent
     * is the only card on the board.
     */
    public static function between(?string $after, ?string $before): string
    {
        $after = $after === null || $after === '' ? '' : $after;
        $before = $before === null || $before === '' ? '' : $before;

        if ($after !== '' && $before !== '' && $after >= $before) {
            // Neighbours out of order, which means the caller's idea of the board is stale. Landing
            // after the first of them is wrong but harmless; refusing would lose the drag.
            $before = '';
        }

        if ($after === '' && $before === '') {
            return self::FIRST;
        }

        if ($after === '') {
            return self::before($before);
        }

        if ($before === '') {
            return self::after($after);
        }

        return self::midpoint($after, $before);
    }

    /** A rank that sorts after everything given, or the first one when there is nothing. */
    public static function last(?string $highest): string
    {
        return $highest === null || $highest === '' ? self::FIRST : self::after($highest);
    }

    private static function after(string $rank): string
    {
        // Halfway between the rank and the end of the space.
        return self::midpoint($rank, 'zzzzzz');
    }

    private static function before(string $rank): string
    {
        return self::midpoint('', $rank);
    }

    /**
     * The string halfway between two, lengthening only when it has to.
     *
     * Walks both strings a character at a time. While the digits agree, the answer keeps them. At
     * the first disagreement, if there is room between the two digits the answer takes the middle
     * one and stops; if there is not, it keeps the lower digit and goes round again on the next
     * position — which is the case that makes the string one character longer.
     */
    private static function midpoint(string $low, string $high): string
    {
        $out = '';

        for ($i = 0; ; $i++) {
            $a = self::digitAt($low, $i, 0);
            $b = self::digitAt($high, $i, strlen(self::DIGITS));

            if ($a === $b) {
                $out .= self::DIGITS[$a];

                continue;
            }

            if ($b - $a > 1) {
                return $out.self::DIGITS[intdiv($a + $b, 2)];
            }

            // Adjacent digits: keep the lower one and find room in the next position, where the
            // upper bound is now the end of the space rather than $high.
            $out .= self::DIGITS[$a];
            $high = '';
        }
    }

    private static function digitAt(string $rank, int $index, int $default): int
    {
        if ($index >= strlen($rank)) {
            return $default;
        }

        $position = strpos(self::DIGITS, $rank[$index]);

        return $position === false ? $default : $position;
    }
}
