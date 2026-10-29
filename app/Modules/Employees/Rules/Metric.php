<?php

namespace App\Modules\Employees\Rules;

use App\Modules\Employees\Models\Employee;

/**
 * What happened, as a number over a window — docs/saas-launch-plan.md §8.9.
 *
 * One method. A metric reads the tables that already exist and judges nothing;
 * the rule's operator and value do the judging. A new metric is a class and a
 * RuleRegistry::registerMetric() line in its module's provider, and it is in
 * the dropdown.
 */
interface Metric
{
    /** `attendance.present_ratio` — module-prefixed, the key the rule JSON stores. */
    public function key(): string;

    public function label(): string;

    /** `number` or `ratio` (0–1, shown as a percentage); decides the operators offered and how the value is typed. */
    public function type(): string;

    public function value(Employee $employee, Window $window): int|float;
}
