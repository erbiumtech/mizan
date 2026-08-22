<?php

namespace App\Support\Contracts;

use Carbon\CarbonInterface;

/**
 * Whether a given person works on a given date.
 *
 * Leave needs this to know which days a request actually consumes. Attendance
 * knows it properly, from the employee's work pattern; without Attendance the
 * answer is the company's configured weekend, which `config/leave.php` has always
 * described as a stopgap.
 *
 * Expressed as a contract so Leave asks the *question* rather than naming the
 * answerer. That deletes the `leave -> attendance` import, and with it one of the
 * cycles that stops either module becoming a package — see
 * docs/module-packaging-plan.md §3 and §6.
 *
 * The employee is an `int` id rather than an Employee model, deliberately: a
 * contract in shared code that type-hints a module's model has not removed the
 * dependency, only moved it.
 */
interface WorkingDayCalendar
{
    public function isWorkingDay(?int $employeeId, CarbonInterface $date): bool;
}
