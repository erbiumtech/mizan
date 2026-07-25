<?php

namespace App\Filament\CommandPalette\Concerns;

trait ScoresMatches
{
    /**
     * Score a query against ordered fields (earlier = higher priority).
     * Returns null when the query is non-empty and nothing matches; returns 0
     * for an empty query so everything is eligible (callers cap the list).
     *
     * @param  array<int, string|null>  $fields
     */
    protected function score(string $query, array $fields): ?int
    {
        $query = mb_strtolower(trim($query));

        if ($query === '') {
            return 0;
        }

        $best = null;

        foreach (array_values($fields) as $i => $field) {
            if (! $field) {
                continue;
            }

            $field = mb_strtolower($field);

            if ($field === $query) {
                $s = 1000;
            } elseif (str_starts_with($field, $query)) {
                $s = 500;
            } elseif (($pos = mb_strpos($field, $query)) !== false) {
                $s = 200 - $pos;
            } elseif ($this->isSubsequence($query, $field)) {
                $s = 50;
            } else {
                continue;
            }

            $s -= $i * 10; // prefer label (field 0) over subtitle
            $best = max($best ?? PHP_INT_MIN, $s);
        }

        return $best;
    }

    /**
     * Render a Filament navigation icon (name or Heroicon enum) to an inline SVG
     * string for client-side display. Returns null when unavailable.
     */
    protected function iconHtml(mixed $icon): ?string
    {
        $name = match (true) {
            $icon instanceof \BackedEnum => (string) $icon->value,
            is_string($icon) && $icon !== '' => $icon,
            default => null,
        };

        if ($name === null) {
            return null;
        }

        try {
            return svg($name, 'cp-item-icon')->toHtml();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Are all characters of $needle present in order within $haystack? */
    protected function isSubsequence(string $needle, string $haystack): bool
    {
        $i = 0;
        $len = mb_strlen($needle);

        foreach (mb_str_split($haystack) as $char) {
            if ($i < $len && $char === mb_substr($needle, $i, 1)) {
                $i++;
            }
        }

        return $i === $len;
    }
}
