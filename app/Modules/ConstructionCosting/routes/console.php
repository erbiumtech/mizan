<?php

use App\Modules\ConstructionCosting\Console\Commands\Reconcile;
use Illuminate\Support\Facades\Schedule;

/**
 * §4.3's first mechanism — `docs/construction-management-plan.md` §4.3.
 *
 * "A report nobody opens is not a control." This is the schedule entry that means nobody has to open it: every open cost
 * period is proved against the general ledger and whoever closes periods is told when it does not.
 *
 * **Weekly rather than daily**, which is the opposite of every other clock in this suite and for a stated reason. §13's
 * notice, §12's compliance and §17.5's competencies all warn about a *deadline* — the tightest threshold is the day
 * itself, and a weekly run steps over it. A reconciliation has no deadline: the difference does not get worse for going
 * unreported another day, and the work of investigating one takes longer than a day anyway. A nightly mail about the same
 * unchanged 412,900 is a nightly mail somebody filters into a folder, and then the month it changes goes unread.
 *
 * Monday morning, so it lands before the week's work rather than into a weekend. TenantAware, so it runs once per
 * company, and skipped for companies without the module.
 */
Schedule::command(Reconcile::class)
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping();
