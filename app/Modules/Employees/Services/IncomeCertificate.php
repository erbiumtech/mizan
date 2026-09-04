<?php

namespace App\Modules\Employees\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Support\CompanyLetterhead;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;
use NumberFormatter;

/**
 * "This person works here and this is what we pay them" — as a letter somebody outside the company can act
 * on: an embassy deciding a visa, a bank opening an account or pricing a loan.
 *
 * **Nothing is stored and nothing is cached.** The letter is rendered from the record as it stands each time
 * it is asked for, which is the same position `PayslipService::renderPdf()` takes and for the same reason: a
 * copy kept on disk goes on stating a salary the company has since changed, and the copy is the one the bank
 * has.
 *
 * **It refuses rather than guesses.** A certificate is read as a statement of fact by somebody who cannot
 * check it, so every figure on it has to come from a record and not from a default. A blank NTN, a missing
 * joining date or an employee with no salary package on file means no letter — with the gaps named, so the
 * person asking knows what to fix. The alternative is a document with "PKR 0" or an empty line where the
 * incorporation number goes, which is worse than nothing: it looks official and it is wrong.
 */
class IncomeCertificate
{
    /**
     * The recurring monthly package, and only the recurring part.
     *
     * Basic plus the three standing allowances. Bonus and extra hours are deliberately excluded: they are
     * what a month happened to hold, and a certificate saying "gross monthly salary" is read as "every
     * month" — a July bonus printed here becomes a promise the company has not made and a loan instalment
     * somebody cannot pay. Deductions are excluded for the opposite reason: what is deducted is not income
     * the employee does not have, and the tax paragraph already says deductions are made at source.
     *
     * @var array<int, string>
     */
    public const RECURRING = ['basic_wage', 'medical_allowance', 'device_allowance', 'petrol_allowance'];

    /**
     * What is missing before this employee can be given a certificate, in the reader's words.
     *
     * @return array<int, string>
     */
    public function missingFor(Employee $employee): array
    {
        $missing = [];

        /*
         * Each gap names the screen that fixes it.
         *
         * "a salary package on file for this employee" was the message before, and the first person to see
         * it asked where that goes — which is a fair question, because a package is not on the employee
         * record: it is a row of its own, under Employee Settings, with a fiscal year and a date range. A
         * refusal that names the missing fact and not the place to put it just moves the problem.
         */
        if (blank($employee->nic)) {
            $missing[] = 'the employee\'s CNIC (Employees → edit this employee)';
        }

        if ($employee->date_of_joining === null) {
            $missing[] = 'the date of joining (Employees → edit this employee)';
        }

        if (blank($employee->designation)) {
            $missing[] = 'the designation (Employees → edit this employee)';
        }

        if ($this->monthlyGross($employee) === null) {
            $missing[] = 'a salary package for this employee (Employee → Employee Settings → New)';
        }

        // The letterhead half is shared with the experience letter — see App\Support\CompanyLetterhead.
        return [...$missing, ...CompanyLetterhead::missing()];
    }

    /**
     * The recurring monthly gross from the package in force today, or null when there is none.
     *
     * Today rather than a chosen month, because a certificate speaks in the present tense: "his gross
     * monthly salary is". The lookup is the payroll one, so the figure on the letter and the figure a
     * payslip would pay come from the same row.
     */
    public function monthlyGross(Employee $employee, ?Carbon $on = null): ?float
    {
        $on ??= Carbon::today();

        $setting = EmployeeSetting::getActiveSettingForDate(
            $employee->getKey(),
            $on->toDateString(),
            FiscalYear::query()->where('start_date', '<=', $on)->where('end_date', '>=', $on)->value('id'),
        );

        if (! $setting) {
            return null;
        }

        $gross = 0.0;

        foreach (self::RECURRING as $component) {
            $gross += (float) ($setting->{$component} ?? 0);
        }

        return $gross > 0 ? round($gross, 2) : null;
    }

    /** This letter's reference — deterministic, so a re-issue carries the one the bank already has. */
    public function reference(Employee $employee, ?Carbon $on = null): string
    {
        return CompanyLetterhead::reference($employee->employee_id ?: 'EMP-'.$employee->getKey(), $on);
    }

    public function filename(Employee $employee): string
    {
        $name = str($employee->user?->name ?: $employee->name ?: $employee->employee_id)->slug()->value();

        return 'income-certificate-'.($name ?: 'employee').'-'.Carbon::today()->format('Y-m-d').'.pdf';
    }

    /**
     * @param  array<string, mixed>  $input  what only a person can supply: the purpose, the father's name,
     *                                       and anything they want to override on the day
     */
    public function renderPdf(Employee $employee, array $input = []): PdfDocument
    {
        return Pdf::view('pdfs.income-certificate', $this->data($employee, $input))
            ->format('a4')
            ->name($this->filename($employee));
    }

    /**
     * Everything the letter prints, resolved here rather than in the template.
     *
     * The template is a letter; working out which allowances count as recurring is not a thing a Blade file
     * should be doing, and the same figures are what a test can assert without rendering a PDF.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function data(Employee $employee, array $input = []): array
    {
        $employee->loadMissing('user', 'bank');

        $monthly = $this->monthlyGross($employee) ?? 0.0;
        $annual = round($monthly * 12, 2);
        $issuedOn = Carbon::today();

        return [
            'employee' => $employee,
            'company' => CompanyLetterhead::data(),
            'signatory' => CompanyLetterhead::signatory($input),
            'issued_on' => $issuedOn,
            'reference' => $this->reference($employee, $issuedOn),
            'father_name' => (string) ($input['father_name'] ?? ''),
            'residence' => (string) ($input['residence'] ?? trim(implode(', ', array_filter([
                $employee->address_line_1,
                $employee->address_line_2,
            ])))),
            'purpose' => (string) ($input['purpose'] ?? ''),
            'duties' => (string) ($input['duties'] ?? ''),
            'employment_status' => str((string) ($employee->employment_type ?: 'permanent'))->headline()->value(),
            'monthly_gross' => $monthly,
            'monthly_gross_words' => $this->words($monthly),
            'annual_gross' => $annual,
            'annual_gross_words' => $this->words($annual),
        ];
    }

    /**
     * "PKR 250,000 (two hundred fifty thousand only)" — the words half.
     *
     * `NumberFormatter::SPELLOUT` from intl rather than a converter of our own: a hand-written one is fifty
     * lines that have to be right about eleven, nineteen and a hundred and one, and the extension is already
     * installed. Paisa are dropped rather than spelled — a salary certificate states rupees, and "and
     * thirty-seven hundredths" on a letter to an embassy reads as a mistake.
     */
    public function words(float $amount): string
    {
        $rupees = (int) round($amount);

        $formatter = new NumberFormatter('en', NumberFormatter::SPELLOUT);

        return str((string) $formatter->format($rupees))->replace('-', ' ')->squish()->title()->value();
    }
}
