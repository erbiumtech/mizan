<?php

namespace App\Modules\Performance\Services;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\Badge;
use App\Modules\Performance\Models\EmployeeBadge;
use App\Modules\Performance\Models\MeritEntry;
use App\Notifications\RecordChanged;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Awarding, and what the portal shows — docs/saas-launch-plan.md §8.4.
 */
class Badges
{
    public function __construct(private readonly MeritLedger $ledger) {}

    /** Once per badge per employee; a reason, always; a merit credit when the badge carries points. */
    public function award(Badge $badge, Employee $employee, string $reason, ?User $by = null): EmployeeBadge
    {
        if (! $badge->is_active) {
            throw new InvalidArgumentException("{$badge->name} is not an active badge.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A badge is awarded for something; say what.');
        }

        if (EmployeeBadge::query()->where('badge_id', $badge->getKey())->where('employee_id', $employee->getKey())->exists()) {
            throw new InvalidArgumentException("{$employee->display_label} already holds {$badge->name}.");
        }

        $entry = $badge->points > 0
            ? $this->ledger->record($employee, $badge->points, "Badge: {$badge->name} — {$reason}", MeritEntry::SOURCE_BADGE, null, $by)
            : null;

        $award = EmployeeBadge::create([
            'badge_id' => $badge->getKey(),
            'employee_id' => $employee->getKey(),
            'awarded_on' => now()->toDateString(),
            'awarded_by' => $by?->getKey(),
            'reason' => $reason,
            'merit_entry_id' => $entry?->getKey(),
        ]);

        $employee->user?->notify(new RecordChanged(
            "You earned the {$badge->name} badge",
            $reason.($badge->points > 0 ? " (+{$badge->points} points)" : ''),
        ));

        return $award;
    }

    /** @return Collection<int, EmployeeBadge> */
    public function earnedBy(Employee $employee): Collection
    {
        return EmployeeBadge::query()->where('employee_id', $employee->getKey())->with('badge')->orderByDesc('awarded_on')->get();
    }

    /** Active badges not yet earned — the half of the feature that makes somebody want the other half. */
    public function lockedFor(Employee $employee): Collection
    {
        return Badge::query()
            ->active()
            ->whereNotIn('id', EmployeeBadge::query()->where('employee_id', $employee->getKey())->select('badge_id'))
            ->orderBy('name')
            ->get();
    }
}
