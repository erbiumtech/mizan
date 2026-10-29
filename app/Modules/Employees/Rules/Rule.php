<?php

namespace App\Modules\Employees\Rules;

/**
 * `{ "any": [ { "all": [ {field, operator, value, window?}, … ] }, … ] }` —
 * groups OR'd, conditions within a group AND'd, no deeper nesting. Sanitised
 * against the registries on the way in and on the way out, so a stored rule can
 * never hold a field the dropdown does not show.
 */
final class Rule
{
    /** @param  array<int, array{all: array<int, array<string, mixed>>}>  $any */
    private function __construct(public readonly array $any) {}

    public static function from(mixed $state): self
    {
        $groups = [];

        foreach ((array) (is_array($state) ? ($state['any'] ?? []) : []) as $group) {
            $conditions = [];

            foreach ((array) (is_array($group) ? ($group['all'] ?? []) : []) as $condition) {
                $clean = self::condition(is_array($condition) ? $condition : []);

                if ($clean !== null) {
                    $conditions[] = $clean;
                }
            }

            if ($conditions !== []) {
                $groups[] = ['all' => $conditions];
            }
        }

        return new self($groups);
    }

    public function isEmpty(): bool
    {
        return $this->any === [];
    }

    public function toArray(): array
    {
        return ['any' => $this->any];
    }

    /** One condition with a known field, an operator its type allows, and a value of that type — or nothing. */
    private static function condition(array $condition): ?array
    {
        $field = is_string($condition['field'] ?? null) ? $condition['field'] : null;
        $definition = $field !== null ? RuleRegistry::field($field) : null;

        if ($definition === null) {
            return null;
        }

        $operator = is_string($condition['operator'] ?? null) ? $condition['operator'] : null;

        if ($operator === null || ! isset(RuleRegistry::operatorsFor($definition['type'])[$operator])) {
            return null;
        }

        $value = self::value($definition['type'], $operator, $condition['value'] ?? null);

        if ($value === null) {
            return null;
        }

        $clean = ['field' => $field, 'operator' => $operator, 'value' => $value];

        if (RuleRegistry::isMetric($field)) {
            $clean['window'] = Window::normalise(is_string($condition['window'] ?? null) ? $condition['window'] : null);
        }

        return $clean;
    }

    private static function value(string $type, string $operator, mixed $value): mixed
    {
        if ($operator === 'in') {
            $values = array_values(array_filter(array_map(fn ($v): string => trim((string) $v), is_array($value) ? $value : explode(',', (string) $value)), fn (string $v): bool => $v !== ''));

            return $values === [] ? null : $values;
        }

        if ($operator === 'between') {
            $pair = is_array($value) ? array_values($value) : explode(',', (string) $value);

            if (count($pair) < 2) {
                return null;
            }

            $low = self::scalar($type, $pair[0]);
            $high = self::scalar($type, $pair[1]);

            return $low === null || $high === null ? null : [$low, $high];
        }

        return self::scalar($type, $value);
    }

    private static function scalar(string $type, mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            RuleRegistry::TYPE_NUMBER, RuleRegistry::TYPE_RATIO => is_numeric($value) ? (float) $value : null,
            RuleRegistry::TYPE_DATE => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) ? substr((string) $value, 0, 10) : null,
            default => (string) $value,
        };
    }
}
