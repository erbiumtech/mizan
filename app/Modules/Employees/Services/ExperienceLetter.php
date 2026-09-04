<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Support\CompanyLetterhead;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;

/**
 * "This person worked here, in these roles, for this long" — the letter a next employer asks for.
 *
 * **Not the income certificate with the salary removed**, though it shares its letterhead. The two answer
 * different questions and so state different facts:
 *
 *  - The income certificate is about *now*: what this company pays, so a bank can lend against it. Its
 *    figure is the package in force today, and the letter is meaningless without one.
 *  - This is about *service*: how long, in what capacity, and how it ended. It needs no salary at all, and
 *    a salary printed on it would follow the employee to their next negotiation — which is their business
 *    to disclose, not ours. **It is left out unless somebody explicitly asks for it**, because "last drawn
 *    salary" is sometimes demanded by a next employer and refusing to print it would send them back to HR.
 *
 * **The roles come from the job history**, not from the current designation alone. Somebody who joined as a
 * developer and left as a lead has an experience letter that says both, which is the thing that makes it
 * worth having — `JobHistory::rolesFor()`. An employee hired before that table existed has none, and the
 * letter falls back to the designation on the record rather than printing nothing.
 *
 * Nothing is stored, for the reason `IncomeCertificate` gives: a copy on disk goes on stating a service
 * period the record has since corrected, and the copy is the one the next employer has.
 */
class ExperienceLetter
{
    public function __construct(private readonly JobHistory $history) {}

    /**
     * What is missing before this employee can be given an experience letter.
     *
     * Deliberately a shorter list than the income certificate's: no CNIC (a next employer identifies the
     * person by name and dates, and a service letter is not an identity document) and no salary package (the
     * letter does not state pay). The joining date is the one fact it cannot do without — a letter certifying
     * service with no start date certifies nothing.
     *
     * @return array<int, string>
     */
    public function missingFor(Employee $employee): array
    {
        $missing = [];

        // Each gap names the screen that fixes it — see the same list in `IncomeCertificate`.
        if ($employee->date_of_joining === null) {
            $missing[] = 'the date of joining (Employees → edit this employee)';
        }

        if (blank($employee->designation) && $this->roles($employee) === []) {
            $missing[] = 'a designation, or any job history to take one from (Employees → edit this employee)';
        }

        return [...$missing, ...CompanyLetterhead::missing()];
    }

    /**
     * Every role held, oldest first, as lines a letter can print.
     *
     * Consecutive rows repeating the same title are collapsed: the history records a change of *any* job
     * fact, so a manager change under an unchanged title would otherwise read as a second stint in the same
     * job. The date each role began is what the reader is checking, so the earliest row for a title wins.
     *
     * @return array<int, array{designation: string, department: string|null, from: Carbon}>
     */
    public function roles(Employee $employee): array
    {
        $roles = [];

        foreach ($this->history->rolesFor($employee) as $row) {
            if (blank($row->designation)) {
                continue;
            }

            $previous = end($roles);

            if ($previous !== false && $previous['designation'] === $row->designation) {
                continue;
            }

            $roles[] = [
                'designation' => (string) $row->designation,
                'department' => $row->department ?: null,
                'from' => Carbon::parse($row->effective_from),
            ];
        }

        return $roles;
    }

    /**
     * How long the service was, in years and months, as a person would say it.
     *
     * Ends at the leaving date when there is one and at today when there is not, which is also what decides
     * the letter's tense. Rounded down to whole months: "2 years 11 months" is a fact, and rounding it up to
     * three years on a letter somebody verifies is the kind of small lie that discredits the whole document.
     */
    public function serviceLength(Employee $employee): string
    {
        $from = $employee->date_of_joining;
        $to = $employee->left_on ?? Carbon::today();

        if ($from === null) {
            return '';
        }

        // `diffInMonths()` returns a float here — 29.97 for 1 March 2024 to 31 August 2026 — so the floor
        // is explicit rather than left to a cast. Twenty-nine whole months is two years and five, and a
        // letter that called it two years and six would be wrong in the direction that flatters.
        $months = (int) floor($from->diffInMonths($to));
        $years = intdiv($months, 12);
        $remainder = $months % 12;

        $parts = array_filter([
            $years > 0 ? $years.' year'.($years === 1 ? '' : 's') : null,
            $remainder > 0 ? $remainder.' month'.($remainder === 1 ? '' : 's') : null,
        ]);

        // Somebody who left inside their first month still served: say so rather than printing nothing.
        return $parts === [] ? 'less than a month' : implode(' ', $parts);
    }

    public function filename(Employee $employee): string
    {
        $name = str($employee->fullName() ?: $employee->employee_id)->slug()->value();

        return 'experience-letter-'.($name ?: 'employee').'-'.Carbon::today()->format('Y-m-d').'.pdf';
    }

    /** @param  array<string, mixed>  $input */
    public function renderPdf(Employee $employee, array $input = []): PdfDocument
    {
        return Pdf::view('pdfs.experience-letter', $this->data($employee, $input))
            ->format('a4')
            ->name($this->filename($employee));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function data(Employee $employee, array $input = []): array
    {
        $employee->loadMissing('user');

        $roles = $this->roles($employee);
        $issuedOn = Carbon::today();
        $hasLeft = $employee->left_on !== null;

        return [
            'employee' => $employee,
            'company' => CompanyLetterhead::data(),
            'signatory' => CompanyLetterhead::signatory($input),
            'issued_on' => $issuedOn,
            'reference' => CompanyLetterhead::reference(
                ($employee->employee_id ?: 'EMP-'.$employee->getKey()).'/EXP',
                $issuedOn,
            ),
            'has_left' => $hasLeft,
            'left_on' => $employee->left_on,
            'service_length' => $this->serviceLength($employee),
            'roles' => $roles,
            // The title to lead with: the last role held, or the record's own when there is no history.
            'final_designation' => $roles === []
                ? (string) $employee->designation
                : end($roles)['designation'],
            'duties' => (string) ($input['duties'] ?? ''),
            'purpose' => (string) ($input['purpose'] ?? ''),
            'conduct' => (string) ($input['conduct'] ?? 'satisfactory'),
            // Off unless asked for. See the class docblock: what somebody earned here is their business to
            // disclose at their next negotiation, not ours to volunteer.
            'monthly_gross' => ($input['include_salary'] ?? false)
                ? app(IncomeCertificate::class)->monthlyGross($employee, $employee->left_on)
                : null,
        ];
    }
}
