<?php

namespace App\Modules\Employees\Support;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeVerification;
use App\Modules\Employees\Models\LetterTemplate;
use App\Support\EmployeeAccess;
use App\Support\VerificationChecks;
use App\Support\WhatsApp\WhatsAppDelivery;

/**
 * The three checks this module owns — docs/saas-launch-plan.md §14.8.
 *
 * Each says whether the company *could* perform it before it says whether anybody
 * has: a phone check needs a WhatsApp provider, an address check needs a letter the
 * company has written. Where the means is missing the check is not offered at all,
 * which is the rule §14.8 opens with.
 */
final class EmployeeChecks
{
    public static function register(): void
    {
        VerificationChecks::register(
            key: 'employee.phone',
            label: 'Primary phone, by a WhatsApp code',
            help: 'A six-digit code to the number on the record. Needs a WhatsApp provider in the environment; '
                .'without one this is not offered.',
            available: fn (): bool => WhatsAppDelivery::configured(),
            outstanding: fn (?object $user): ?string => self::unproved($user, EmployeeVerification::KIND_PHONE, 'Your phone number has not been confirmed.'),
            sort: 10,
        );

        VerificationChecks::register(
            key: 'employee.emergency_phone',
            label: 'Emergency contact, by a WhatsApp code',
            help: 'The same code, to the emergency contact\'s number — which is the one worth checking, because '
                .'it is the number nobody dials until the day it matters.',
            available: fn (): bool => WhatsAppDelivery::configured(),
            outstanding: fn (?object $user): ?string => self::unproved($user, EmployeeVerification::KIND_EMERGENCY_PHONE, 'Your emergency contact number has not been confirmed.'),
            sort: 20,
        );

        VerificationChecks::register(
            key: 'employee.address',
            label: 'Home address, by a code on a posted letter',
            help: 'A letter with a code goes to the address; typing it back is what proves somebody lives there. '
                .'Needs an active address verification letter template.',
            available: fn (): bool => LetterTemplate::for(LetterTemplate::KIND_ADDRESS_VERIFICATION) !== null,
            outstanding: fn (?object $user): ?string => self::unproved($user, EmployeeVerification::KIND_ADDRESS, 'Your home address has not been confirmed.'),
            sort: 30,
        );
    }

    /**
     * What this person has proved, as kind => true.
     *
     * One query, and only ever about what the record says *now*: a row that proved an
     * old number is not a proof of the new one.
     *
     * @return array<string, bool>
     */
    public static function proved(Employee $employee): array
    {
        return $employee->verifications
            ->mapWithKeys(fn (EmployeeVerification $row): array => [(string) $row->kind => $row->provesCurrent($employee)])
            ->all();
    }

    /**
     * The employee record behind a signed-in user, or nothing.
     *
     * Typed `?object` because the registry hands checks whatever the caller had, and
     * the caller is sometimes a guest. Anything that is not an authenticatable is not
     * an employee, which is the same answer as "no record".
     */
    public static function employeeFor(?object $user): ?Employee
    {
        if (! $user instanceof User) {
            return null;
        }

        // Through the request's memo rather than a query of its own: every per-person
        // screen asks this while the navigation is built, and they were asking it
        // separately. `loadMissing` then fills the relation once for the request.
        $employee = app(EmployeeAccess::class)->employeeFor($user);
        $employee?->loadMissing('verifications');

        return $employee;
    }

    private static function unproved(?object $user, string $kind, string $reason): ?string
    {
        $employee = self::employeeFor($user);

        // Somebody with no employee record is not an employee of this company in the
        // sense these checks mean — an accountant brought in for the books, the owner.
        // There is nothing to confirm, so there is nothing to hold them up.
        if ($employee === null) {
            return null;
        }

        // Nothing on the record to check is not the same as a check somebody failed:
        // a person with no emergency contact has not refused to confirm one.
        if (EmployeeVerification::valueOn($employee, $kind) === null) {
            return null;
        }

        return (self::proved($employee)[$kind] ?? false) ? null : $reason;
    }
}
