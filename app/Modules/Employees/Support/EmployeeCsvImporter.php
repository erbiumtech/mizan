<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\Employee;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Employees from a spreadsheet — docs/saas-launch-plan.md §14.2. Previewed first
 * like every import here; rows arrive without a login, and the bulk Activate on
 * the directory is what creates and invites the users.
 */
class EmployeeCsvImporter implements CsvImporter
{
    public const COLUMNS = [
        'employee_id', 'name', 'email', 'gender', 'department', 'designation', 'date_of_joining',
        'phone', 'nic', 'bank_account_no', 'iban_no', 'employment_type',
    ];

    public function key(): string
    {
        return 'employees';
    }

    public function label(): string
    {
        return 'Employees';
    }

    public function columns(): array
    {
        return self::COLUMNS;
    }

    public function example(): array
    {
        return ['EMP-101', 'Ayesha Khan', 'ayesha@example.com', 'Female', 'Sales', 'Executive', '2026-01-15', '0300-1234567', '35202-1234567-1', '0011223344', '', 'permanent'];
    }

    public function dateField(): ?array
    {
        return null;
    }

    public function problemWith(array $row): ?string
    {
        if (trim((string) ($row['employee_id'] ?? '')) === '') {
            return 'no employee code';
        }

        if (trim((string) ($row['name'] ?? '')) === '') {
            return 'no name';
        }

        if (Employee::query()->where('employee_id', trim((string) $row['employee_id']))->exists()) {
            return "{$row['employee_id']} already exists";
        }

        if (($row['email'] ?? '') !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            return "{$row['email']} is not an email address";
        }

        if (($row['date_of_joining'] ?? '') !== '' && strtotime((string) $row['date_of_joining']) === false) {
            return "{$row['date_of_joining']} is not a date";
        }

        return null;
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $written = 0;

        foreach ($rows as $row) {
            Employee::withoutApprovalRouting(fn () => Employee::create([
                'employee_id' => trim((string) $row['employee_id']),
                'name' => trim((string) $row['name']),
                'personal_email' => ($row['email'] ?? '') !== '' ? trim((string) $row['email']) : null,
                'gender' => ($row['gender'] ?? '') !== '' ? ucfirst(strtolower(trim((string) $row['gender']))) : null,
                'department' => ($row['department'] ?? '') !== '' ? trim((string) $row['department']) : null,
                'designation' => ($row['designation'] ?? '') !== '' ? trim((string) $row['designation']) : null,
                'date_of_joining' => ($row['date_of_joining'] ?? '') !== '' ? Carbon::parse((string) $row['date_of_joining'])->toDateString() : null,
                'phone' => ($row['phone'] ?? '') !== '' ? trim((string) $row['phone']) : null,
                'nic' => ($row['nic'] ?? '') !== '' ? trim((string) $row['nic']) : null,
                'bank_account_no' => ($row['bank_account_no'] ?? '') !== '' ? trim((string) $row['bank_account_no']) : null,
                'iban_no' => ($row['iban_no'] ?? '') !== '' ? trim((string) $row['iban_no']) : null,
                'employment_type' => ($row['employment_type'] ?? '') !== '' ? trim((string) $row['employment_type']) : null,
                'status' => Employee::STATUS_ACTIVE,
                'is_active' => true,
            ]));
            $written++;
        }

        return $written;
    }
}
