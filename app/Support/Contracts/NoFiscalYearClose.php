<?php

namespace App\Support\Contracts;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use RuntimeException;

/**
 * What closing a fiscal year means with no accounting module installed: nothing, and it says so.
 *
 * Bound as the default so Core's fiscal-years table works whether or not Accounting is there. It reports a
 * blocker rather than returning none — an empty blocker list means "go ahead", and a screen that offered
 * to close a year and then silently did nothing would be worse than one that explains itself.
 *
 * The two mutators throw. They are unreachable through the UI, which checks `blockers()` first, so a throw
 * here is a programming error surfacing rather than a user seeing an exception.
 */
class NoFiscalYearClose implements FiscalYearCloseCheck
{
    public function blockers(FiscalYear $year): array
    {
        return ['Closing a year needs the Accounting module, which is not enabled for this company.'];
    }

    public function close(FiscalYear $year, User $by): FiscalYear
    {
        throw new RuntimeException('No implementation of FiscalYearCloseCheck is installed.');
    }

    public function reopen(FiscalYear $year, User $by): FiscalYear
    {
        throw new RuntimeException('No implementation of FiscalYearCloseCheck is installed.');
    }
}
