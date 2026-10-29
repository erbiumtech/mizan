<?php

namespace App\Modules\Employees\Rules;

use App\Modules\Core\Models\WorkLocation;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * The two vocabularies a rule may use — docs/saas-launch-plan.md §8.9.
 *
 * Attributes are who somebody is, read from the employees row; owned here
 * because the row is. Metrics are what happened, registered by the module that
 * owns the table they read, so a company without Attendance has no
 * attendance.* fields rather than fields that fail. The dropdown is the
 * documentation: a key the registry does not know is dropped on write and on
 * read, as ReportDefinition sanitises its state.
 */
class RuleRegistry
{
    public const TYPE_NUMBER = 'number';

    public const TYPE_RATIO = 'ratio';

    public const TYPE_DATE = 'date';

    public const TYPE_SELECT = 'select';

    public const TYPE_TEXT = 'text';

    public const OPERATORS = [
        '=' => 'is',
        '!=' => 'is not',
        '>=' => 'at least',
        '<=' => 'at most',
        'between' => 'between',
        'in' => 'one of',
    ];

    /** @var array<string, Metric> */
    private static array $metrics = [];

    public static function registerMetric(Metric $metric): void
    {
        static::$metrics[$metric->key()] = $metric;
    }

    /** @return array<string, Metric> */
    public static function metrics(): array
    {
        ksort(static::$metrics);

        return static::$metrics;
    }

    public static function metric(string $key): ?Metric
    {
        return static::$metrics[$key] ?? null;
    }

    /**
     * The attribute fields: key => [label, type, options?, read].
     *
     * @return array<string, array{label: string, type: string, options?: callable, read: callable}>
     */
    public static function attributes(): array
    {
        return [
            'department' => ['label' => 'Department', 'type' => self::TYPE_SELECT, 'options' => fn (): array => static::optionList('employees.department'), 'read' => fn (Employee $e) => $e->department],
            'designation' => ['label' => 'Designation', 'type' => self::TYPE_SELECT, 'options' => fn (): array => static::optionList('employees.designation'), 'read' => fn (Employee $e) => $e->designation],
            'location' => ['label' => 'Location', 'type' => self::TYPE_SELECT, 'options' => fn (): array => array_map('strval', WorkLocation::options()), 'read' => fn (Employee $e) => $e->location_id === null ? null : (string) $e->location_id],
            'employment_type' => ['label' => 'Employment type', 'type' => self::TYPE_SELECT, 'options' => fn (): array => static::optionList('employees.employment_type'), 'read' => fn (Employee $e) => $e->employment_type],
            'manager' => ['label' => 'Manager', 'type' => self::TYPE_SELECT, 'options' => fn (): array => Employee::query()->where('is_active', true)->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $m): array => [(string) $m->getKey() => $m->display_label])->all(), 'read' => fn (Employee $e) => $e->manager_id === null ? null : (string) $e->manager_id],
            'years_of_service' => ['label' => 'Years of service', 'type' => self::TYPE_NUMBER, 'read' => fn (Employee $e, Carbon $asOf) => $e->date_of_joining ? round($e->date_of_joining->diffInDays($asOf) / 365.25, 2) : 0],
            'date_of_joining' => ['label' => 'Date of joining', 'type' => self::TYPE_DATE, 'read' => fn (Employee $e) => $e->date_of_joining?->toDateString()],
        ];
    }

    /** Every field the form offers, grouped: key => [label, type, group, options?]. */
    public static function fields(): array
    {
        $fields = [];

        foreach (static::attributes() as $key => $attribute) {
            $fields[$key] = ['label' => $attribute['label'], 'type' => $attribute['type'], 'group' => 'Who they are'] + (isset($attribute['options']) ? ['options' => $attribute['options']] : []);
        }

        foreach (static::metrics() as $key => $metric) {
            $fields[$key] = ['label' => $metric->label(), 'type' => $metric->type(), 'group' => 'What happened'];
        }

        return $fields;
    }

    public static function field(string $key): ?array
    {
        return static::fields()[$key] ?? null;
    }

    public static function isMetric(string $key): bool
    {
        return isset(static::$metrics[$key]);
    }

    /** The operators a type allows — the way DatasetFilter offers a range to a date and a select to a code. */
    public static function operatorsFor(string $type): array
    {
        $keys = match ($type) {
            self::TYPE_SELECT, self::TYPE_TEXT => ['=', '!=', 'in'],
            default => ['=', '!=', '>=', '<=', 'between'],
        };

        return array_intersect_key(self::OPERATORS, array_flip($keys));
    }

    /** @return array<string, string> */
    private static function optionList(string $list): array
    {
        $options = function_exists('options') ? (array) options($list) : [];

        return array_combine(array_map('strval', array_keys($options)), array_map('strval', $options)) ?: [];
    }

    /** For tests and reboots. */
    public static function flush(): void
    {
        static::$metrics = [];
    }
}
