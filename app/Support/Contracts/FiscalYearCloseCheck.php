<?php

namespace App\Support\Contracts;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;

/**
 * Closing and reopening a fiscal year, for whoever knows what that means.
 *
 * A fiscal year is Core's — leave, attendance and payroll all ask which one a date falls in — but
 * *closing* one is an accounting act: it needs the year's entries posted, its balances rolled forward and
 * a retained-earnings entry written. So Core's fiscal-years table offered the buttons and reached into
 * `Accounting\Services\FiscalYearClosingService` to make them work, which is Core depending on a module.
 * See docs/module-packaging-plan.md §9.
 *
 * The contract is the existing signature, deliberately: `blockers()` already returned `string[]` and the
 * other two already took a year and a user. Nothing had to be redesigned to invert this — only named.
 *
 * `NoFiscalYearClose` is the default, for a company with no accounting module. It reports one blocker and
 * refuses, which is the honest answer: there is nothing here that can close a year.
 */
interface FiscalYearCloseCheck
{
    /**
     * Why this year cannot be closed, or an empty array if it can.
     *
     * @return array<int, string>
     */
    public function blockers(FiscalYear $year): array;

    public function close(FiscalYear $year, User $by): FiscalYear;

    public function reopen(FiscalYear $year, User $by): FiscalYear;
}
