<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeVerification;
use App\Support\SubjectAccess;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Subject access and erasure — docs/saas-launch-plan.md §14.8.
 *
 * **Erasure never deletes a posted record.** A payslip and the journal entries behind
 * it are kept for the statutory period whatever anybody asks, and the plan is
 * explicit about it. So this empties the *person* out of the employee record — the
 * number, the address, the identity card, the bank account, the scans — and leaves
 * every record that was ever posted exactly where it is, still carrying the employee
 * code it always did.
 *
 * What is left behind is deliberate: a code, a joining date and a leaving date, which
 * is what a payslip from 2024 has to keep pointing at to still make sense. Somebody
 * reading the ledger afterwards sees that an employee existed and was paid, and
 * nothing about who they were.
 *
 * The linked account is unlinked rather than deleted. A user is a landlord record
 * that may belong to several companies, and one company erasing somebody must not
 * reach into another's.
 */
class EmployeeErasure
{
    /** Emptied outright. */
    private const BLANKED = [
        'phone', 'secondary_phone', 'personal_email',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'nic', 'ntn', 'father_name', 'nationality', 'date_of_birth', 'marital_status',
        'current_address', 'permanent_address', 'address_line_1', 'address_line_2',
        'bank_id', 'bank_code', 'bank_short_code', 'bank_account_no', 'iban_no',
        'device_pin', 'leaving_reason',
    ];

    /** Files deleted from disk, not merely unlinked from the row. */
    private const FILES = ['nic_front', 'nic_back', 'photo_path'];

    /**
     * @throws RuntimeException when the person is still employed
     */
    public function erase(Employee $employee): void
    {
        if ($employee->is_active) {
            throw new RuntimeException(
                'This employee is still active. Erasure is for somebody who has left: mark them inactive first, '
                .'so a record nobody meant to empty cannot be emptied by one click.'
            );
        }

        if ($employee->isAnonymised()) {
            throw new RuntimeException('This record has already been erased.');
        }

        foreach (self::FILES as $field) {
            $path = $employee->{$field};

            if (filled($path)) {
                // Best effort. A file already gone is the state we wanted; a disk that
                // refuses must not leave the row half-erased.
                rescue(fn () => Storage::disk('public')->delete((string) $path), report: false);
            }
        }

        $attributes = array_fill_keys(self::BLANKED, null) + array_fill_keys(self::FILES, null);

        // forceFill rather than update(): several of these are guarded, and an erasure
        // that silently skipped the bank account would be the worst possible failure.
        $employee->forceFill($attributes + [
            'name' => 'Erased ('.($employee->employee_id ?: 'employee '.$employee->getKey()).')',
            'user_id' => null,
            'anonymised_at' => now(),
        ])->save();

        // The proofs go with the thing they proved. A verification row holds the number
        // or the address it was about, which is exactly what was asked to be erased.
        EmployeeVerification::where('employee_id', $employee->getKey())->delete();
    }

    /**
     * Everything the company holds about this person, for disclosure.
     *
     * @return array<string, mixed>
     */
    public function disclose(Employee $employee): array
    {
        return [
            'about' => 'Everything '.(\App\Support\CompanyLetterhead::displayName()).' holds about this person, as at '.now()->toDayDateTimeString().'.',
            'note' => 'Figures that appear in the accounts — payslips, ledger entries — are listed because they '
                .'are about you, and are kept for the statutory period even where erasure is asked for.',
            ...SubjectAccess::forEmployee($employee),
        ];
    }

    public function filename(Employee $employee): string
    {
        $name = str($employee->fullName() ?: (string) $employee->employee_id)->slug()->value();

        return 'subject-access-'.($name ?: 'employee-'.$employee->getKey()).'-'.now()->format('Y-m-d').'.json';
    }
}
