<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Support\CompanyLetterhead;
use App\Support\DocumentVerification;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;

/**
 * "You are appointed as …, from …, at …" — the letter for the day somebody joins
 * — docs/saas-launch-plan.md §7.2.
 *
 * The income certificate's facts for a different reader: the employee, on their first morning, rather
 * than a bank. Same letterhead, same position — nothing stored, every figure from the record, a gap
 * named rather than guessed. The package quoted is the one in force on the joining date, through the same
 * lookup a payslip would use, so the letter and the first payslip cannot disagree.
 */
class AppointmentLetter
{
    public function __construct(private readonly IncomeCertificate $certificates) {}

    /**
     * What is missing before this employee can be given an appointment letter.
     *
     * @return array<int, string>
     */
    public function missingFor(Employee $employee): array
    {
        $missing = [];

        if ($employee->date_of_joining === null) {
            $missing[] = 'the date of joining (Employees → edit this employee)';
        }

        if (blank($employee->designation)) {
            $missing[] = 'the designation (Employees → edit this employee)';
        }

        if ($employee->date_of_joining !== null
            && $this->certificates->monthlyGross($employee, $employee->date_of_joining->copy()) === null) {
            $missing[] = 'a salary package in force on the joining date (Employee → Employee Settings → New)';
        }

        return [...$missing, ...CompanyLetterhead::missing()];
    }

    /**
     * The facts the letter's verification code vouches for — plan §7.5.
     *
     * @return array<string, mixed>
     */
    public function verificationFacts(Employee $employee): array
    {
        $joining = $employee->date_of_joining?->copy() ?? Carbon::today();

        return [
            'employee_code' => (string) $employee->employee_id,
            'employee' => (string) $employee->fullName(),
            'designation' => (string) $employee->designation,
            'date_of_joining' => $joining->toDateString(),
            'gross_monthly_salary' => (float) ($this->certificates->monthlyGross($employee, $joining) ?? 0),
        ];
    }

    public function filename(Employee $employee): string
    {
        $name = str($employee->fullName() ?: $employee->employee_id)->slug()->value();

        return 'appointment-letter-'.($name ?: 'employee').'-'.Carbon::today()->format('Y-m-d').'.pdf';
    }

    /** @param  array<string, mixed>  $input */
    public function renderPdf(Employee $employee, array $input = []): PdfDocument
    {
        return Pdf::view('pdfs.appointment-letter', $this->data($employee, $input))
            ->format('a4')
            ->name($this->filename($employee));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function data(Employee $employee, array $input = []): array
    {
        $employee->loadMissing('user', 'manager.user');
        $issuedOn = Carbon::today();
        $joining = $employee->date_of_joining?->copy() ?? $issuedOn;

        return [
            'employee' => $employee,
            'company' => CompanyLetterhead::data(),
            'signatory' => CompanyLetterhead::signatory($input),
            'issued_on' => $issuedOn,
            'verification_qr' => DocumentVerification::qrFor('appointment-letter', $employee->getKey(), $this->verificationFacts($employee)),
            'reference' => CompanyLetterhead::reference(
                ($employee->employee_id ?: 'EMP-'.$employee->getKey()).'/APT',
                $issuedOn,
            ),
            'joining_on' => $joining,
            'monthly_gross' => $this->certificates->monthlyGross($employee, $joining),
            'reports_to' => $employee->manager?->fullName(),
            'probation_months' => max(0, (int) ($input['probation_months'] ?? 3)),
        ];
    }
}
