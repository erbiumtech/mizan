<?php

namespace App\Support;

/**
 * Reading a Filament KeyValue field's state whichever shape it arrives in.
 *
 * A KeyValue field is validated in its raw editing shape — a list of `['key' => ..., 'value' => ...]` rows —
 * not as the associative map it casts to afterwards. A rule that assumes the map silently sees nothing when
 * handed the rows, which is a validation that passes everything.
 *
 * This was `CompanySettings::keyValueMap()`. It is here because the Company Settings screen now has two
 * owners of KeyValue rules — Core's iPayments defaults and the payroll account codes Accounting contributes
 * — and duplicating the normaliser in both is how the two would drift.
 */
class KeyValueState
{
    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (is_array($entry) && array_key_exists('key', $entry)) {
                if ($entry['key'] !== null && $entry['key'] !== '') {
                    $map[$entry['key']] = $entry['value'] ?? null;
                }

                continue;
            }

            $map[$key] = $entry;
        }

        return $map;
    }
}
