<?php

namespace App\Modules\Employees\Rules;

use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The whole runtime — docs/saas-launch-plan.md §8.9. No side effects: it answers
 * whether one person matches a rule on a date, and which people do. badges:award,
 * tasks:roll and an announcement's audience are its callers.
 */
class RuleEvaluator
{
    public function matches(Employee $employee, Rule|array|null $rule, ?Carbon $asOf = null): bool
    {
        $rule = $rule instanceof Rule ? $rule : Rule::from($rule);
        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();

        if ($rule->isEmpty()) {
            return false;
        }

        foreach ($rule->any as $group) {
            $all = true;

            foreach ($group['all'] as $condition) {
                if (! $this->condition($employee, $condition, $asOf)) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    /** The active employees the rule selects today — the preview, and what the callers assign or award to. */
    public function matching(Rule|array|null $rule, ?Carbon $asOf = null): Collection
    {
        $rule = $rule instanceof Rule ? $rule : Rule::from($rule);

        if ($rule->isEmpty()) {
            return new Collection;
        }

        return Employee::query()->where('is_active', true)->orderBy('employee_id')->get()
            ->filter(fn (Employee $employee): bool => $this->matches($employee, $rule, $asOf))
            ->values();
    }

    private function condition(Employee $employee, array $condition, Carbon $asOf): bool
    {
        $field = $condition['field'];
        $actual = RuleRegistry::isMetric($field)
            ? RuleRegistry::metric($field)->value($employee, Window::make($condition['window'] ?? Window::THIS_MONTH, $asOf))
            : (RuleRegistry::attributes()[$field]['read'])($employee, $asOf);

        $expected = $condition['value'];

        return match ($condition['operator']) {
            '=' => $this->same($actual, $expected),
            '!=' => ! $this->same($actual, $expected),
            '>=' => $actual !== null && $this->compare($actual, $expected) >= 0,
            '<=' => $actual !== null && $this->compare($actual, $expected) <= 0,
            'between' => $actual !== null && $this->compare($actual, $expected[0]) >= 0 && $this->compare($actual, $expected[1]) <= 0,
            'in' => $actual !== null && in_array((string) $actual, array_map('strval', (array) $expected), true),
            default => false,
        };
    }

    private function same(mixed $actual, mixed $expected): bool
    {
        if ($actual === null) {
            return false;
        }

        return is_numeric($expected) && is_numeric($actual)
            ? abs((float) $actual - (float) $expected) < 0.000001
            : strcasecmp((string) $actual, (string) $expected) === 0;
    }

    private function compare(mixed $actual, mixed $expected): int
    {
        return is_numeric($expected) && is_numeric($actual)
            ? (float) $actual <=> (float) $expected
            : strcmp((string) $actual, (string) $expected);
    }
}
