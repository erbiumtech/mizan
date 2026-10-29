<?php

namespace App\Modules\Employees\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CompanyInvitations;
use App\Modules\Employees\Events\EmployeeActivated;
use App\Modules\Employees\Events\EmployeeJoined;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The dated moves on a record — docs/saas-launch-plan.md §14.2: transfer,
 * promotion, confirmation, rehire, and activation. Each writes the fact where
 * the readers already look — the job history, the status, the dates — rather
 * than a screen of its own.
 */
class EmployeeMoves
{
    public function __construct(private readonly JobHistory $history) {}

    /** @param  array{department?: ?string, location_id?: ?int, manager_id?: ?int}  $to */
    public function transfer(Employee $employee, array $to, string|Carbon $effectiveFrom, ?string $reason = null): EmployeeJobHistory
    {
        $changes = array_filter(array_intersect_key($to, array_flip(['department', 'location_id', 'manager_id'])), fn ($v) => $v !== null && $v !== '');

        if ($changes === []) {
            throw new InvalidArgumentException('A transfer changes the department, the location or the manager; pick at least one.');
        }

        return $this->history->record($employee, $changes, $effectiveFrom, $reason, EmployeeJobHistory::KIND_TRANSFER);
    }

    public function promote(Employee $employee, string $designation, string|Carbon $effectiveFrom, ?string $reason = null): EmployeeJobHistory
    {
        if (trim($designation) === '') {
            throw new InvalidArgumentException('A promotion is to a designation.');
        }

        return $this->history->record($employee, ['designation' => $designation], $effectiveFrom, $reason, EmployeeJobHistory::KIND_PROMOTION);
    }

    public function confirm(Employee $employee, string|Carbon|null $on = null): Employee
    {
        if (! $employee->isOnProbation()) {
            throw new InvalidArgumentException("{$employee->display_label} is not on probation.");
        }

        $employee->forceFill(['confirmed_on' => Carbon::parse($on ?? now())->toDateString()])->save();

        return $employee;
    }

    /** A leaver back on the books. The old spell stays in the history; the new one starts a dated row. */
    public function rehire(Employee $employee, string|Carbon $joinedOn, ?string $reason = null): Employee
    {
        if ($employee->is_active) {
            throw new InvalidArgumentException("{$employee->display_label} is still employed.");
        }

        $joinedOn = Carbon::parse($joinedOn);

        $employee->forceFill([
            'status' => Employee::STATUS_ACTIVE,
            'left_on' => null,
            'leaving_reason' => null,
            'notice_served_until' => null,
            'date_of_joining' => $joinedOn->toDateString(),
            'confirmed_on' => null,
        ])->save();

        $this->history->record($employee, [], $joinedOn, $reason ?? 'Rehired', EmployeeJobHistory::KIND_REHIRE);
        EmployeeJoined::dispatch($employee->fresh());

        return $employee->refresh();
    }

    /**
     * Switch on, and give a login — docs/saas-launch-plan.md §14.2's bulk Activate: the user is
     * created and invited from the personal email, and Leave opens the year on the event.
     *
     * @return array{invited: bool, problem: ?string}
     */
    public function activate(Employee $employee, User $by): array
    {
        $problem = null;
        $invited = false;

        if ($employee->user_id === null) {
            $email = trim((string) $employee->personal_email);
            $company = Company::current() ?? (($t = Filament::getTenant()) instanceof Company ? $t : null);

            if ($email === '' || $company === null) {
                $problem = $email === '' ? 'no email to invite' : 'no company in scope';
            } else {
                try {
                    $result = app(CompanyInvitations::class)->invite($company, $email, (string) ($employee->name ?: $employee->employee_id), ['Employee'], $by);
                    $employee->forceFill(['user_id' => $result['user']->getKey()])->save();
                    $invited = true;
                } catch (InvalidArgumentException $e) {
                    $problem = $e->getMessage();
                }
            }
        }

        if (! $employee->is_active) {
            $employee->forceFill(['status' => Employee::STATUS_ACTIVE])->save();
        }

        EmployeeActivated::dispatch($employee->getKey());

        return ['invited' => $invited, 'problem' => $problem];
    }
}
