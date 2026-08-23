<?php

namespace App\Modules\Recruitment\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Accepting an offer: **a conversion, not a copy.**
 *
 * Creates the Employee, optionally the User, and the EmployeeSetting from the offer's
 * components — **in one transaction** — and stores `applications.employee_id` so the trail
 * from vacancy to payroll survives. If any part fails, none of it happened: a company
 * left with an employee who has no salary package, or a package with no employee, is
 * worse off than one whose hire button errored.
 *
 * Guarded on `employees`, which is the whole reason `recruitment` requires nothing: a
 * company hiring its first person has no `employees` licence yet, and the pipeline still
 * has to work up to this point.
 */
class HireService
{
    /** Whether hiring can be offered at all. */
    public function isAvailable(): bool
    {
        return modules()->enabled('employees');
    }

    /**
     * Turn an accepted offer into an employee.
     *
     * @param  bool  $withLogin  create a User too. Optional because
     *                           `allow_employees_without_a_login` already exists: a
     *                           factory floor does not get accounts.
     */
    public function hire(Offer $offer, bool $withLogin = false, ?string $employeeCode = null): Employee
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Hiring needs the Employees module: there is nothing for an accepted offer to become.'
            );
        }

        $application = $offer->application;

        if (! $application) {
            throw new InvalidArgumentException('This offer is not attached to an application.');
        }

        if ($application->isHired()) {
            throw new InvalidArgumentException(
                'This application has already been hired. Hiring twice would create a second employee for one person.'
            );
        }

        if (! $offer->isOpen() && ! $offer->isAccepted()) {
            throw new InvalidArgumentException("This offer is {$offer->status} and cannot be accepted.");
        }

        $applicant = $application->applicant;

        return DB::transaction(function () use ($offer, $application, $applicant, $withLogin, $employeeCode): Employee {
            $user = $withLogin ? $this->createUser($applicant) : null;

            $attributes = [
                'user_id' => $user?->getKey(),
                'name' => $applicant->name,
                'personal_email' => $applicant->email,
                'phone' => $applicant->phone,
                'nic' => $applicant->cnic,
                'designation' => $application->vacancy?->designation,
                'department' => $application->vacancy?->department,
                'employment_type' => $application->vacancy?->employment_type,
                'manager_id' => $application->vacancy?->hiring_manager_employee_id,
                'date_of_joining' => $offer->joining_date->toDateString(),
                'is_active' => true,
            ];

            // A code the caller chose is used exactly as given. Only a *generated* code may
            // be regenerated on a collision — retrying a chosen one would quietly substitute
            // a different code for the one they asked for, and they should hear about the
            // clash instead.
            $employee = $employeeCode
                ? Employee::create($attributes + ['employee_id' => $employeeCode])
                : Employee::withGeneratedCode(
                    fn (string $code): Employee => Employee::create($attributes + ['employee_id' => $code])
                );

            $this->createPackage($employee, $offer);

            $offer->update([
                'status' => Offer::STATUS_ACCEPTED,
                'responded_at' => $offer->responded_at ?? now(),
            ]);

            $application->update([
                'stage' => Application::STAGE_HIRED,
                'employee_id' => $employee->getKey(),
            ]);

            // Filled when the last opening is taken, so a vacancy does not sit open
            // collecting applications for a job that no longer exists.
            if ($application->vacancy && $application->vacancy->remainingOpenings() === 0) {
                $application->vacancy->update([
                    'status' => \App\Modules\Recruitment\Models\Vacancy::STATUS_FILLED,
                    'closed_on' => now()->toDateString(),
                ]);
            }

            activity('Application')
                ->performedOn($application)
                ->causedBy(auth()->user())
                ->event('hired')
                ->withProperties(['employee_id' => $employee->getKey()])
                ->log("Offer accepted — {$applicant->name} hired as employee {$employee->employee_id}");

            return $employee->refresh();
        });
    }

    /**
     * The salary package, from the offer.
     *
     * Guarded on `payroll`: a company that does not run payroll here has no fiscal year
     * or components to build, and the employee is created without a package rather than
     * the hire failing. That is the honest degradation — recruitment's job is the person,
     * not the payslip.
     */
    private function createPackage(Employee $employee, Offer $offer): ?EmployeeSetting
    {
        if (! modules()->enabled('payroll')) {
            return null;
        }

        $fiscalYear = $this->fiscalYearFor($offer);

        if (! $fiscalYear) {
            return null;
        }

        $setting = EmployeeSetting::create([
            'employee_id' => $employee->getKey(),
            'fiscal_year_id' => $fiscalYear->getKey(),
            // From the joining date, not the fiscal year's start: somebody joining in
            // September has no agreed package for July and August, and a setting that
            // claimed otherwise would let a payslip be raised for a month before they
            // existed.
            'start_date' => $offer->joining_date->toDateString(),
            'end_date' => $fiscalYear->end_date,
            'basic_wage' => $offer->salary,
        ]);

        foreach ((array) $offer->components as $code => $amount) {
            $component = PayComponent::where('code', $code)->first();

            // Silently skipped rather than failing the hire: an offer naming a component
            // this company has since retired should not stop somebody starting work. The
            // package is correctable; a failed hire on somebody's first morning is not.
            if (! $component || (float) $amount <= 0) {
                continue;
            }

            EmployeeSettingComponent::create([
                'employee_setting_id' => $setting->getKey(),
                'pay_component_id' => $component->getKey(),
                'amount' => $amount,
            ]);
        }

        return $setting;
    }

    private function createUser(\App\Modules\Recruitment\Models\Applicant $applicant): User
    {
        if (! $applicant->email) {
            throw new InvalidArgumentException(
                'A login needs an email address, and this applicant has none. Hire without one, or add it first.'
            );
        }

        if (User::query()->acrossCompanies()->where('email', $applicant->email)->exists()) {
            throw new InvalidArgumentException(
                "There is already an account for {$applicant->email}. Hire without a login and attach the existing account."
            );
        }

        return User::create([
            'name' => $applicant->name,
            'email' => $applicant->email,
            // A random password nobody is told: the person resets it. Issuing a known
            // password in a hire flow means a credential somebody has to transmit.
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'status' => 1,
        ]);
    }

    private function fiscalYearFor(Offer $offer): ?FiscalYear
    {
        $joining = $offer->joining_date->toDateString();

        return FiscalYear::query()
            ->whereDate('start_date', '<=', $joining)
            ->whereDate('end_date', '>=', $joining)
            ->first();
    }
}
