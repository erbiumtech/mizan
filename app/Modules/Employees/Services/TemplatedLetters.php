<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\LetterTemplate;
use App\Support\CompanyLetterhead;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A company's saved letters — docs/saas-launch-plan.md §14.7.
 *
 * The difference from {@see CustomLetter} is who writes the words and when.
 * There, an administrator types them on the day; here they were written once, as
 * a template, and the placeholders are filled from the record — which is what
 * lets an increment letter issue itself the moment the increment is approved
 * without anybody typing anything.
 *
 * The paper is the same paper. The letterhead, the reference and the seal come
 * from `CustomLetter::data()` rather than being built again here, so a company
 * that changes its letterhead changes it for both and three HR letters never
 * look like they came from three companies.
 */
class TemplatedLetters
{
    public function __construct(private readonly CustomLetter $letters) {}

    /**
     * What a template may fill in, for this employee.
     *
     * @param  array<string, string|null>  $extra  values only the event knows — the effective date, the old and new figures
     * @return array<string, string>
     */
    public function values(Employee $employee, array $extra = []): array
    {
        $employee->loadMissing('user', 'location');

        return array_merge([
            'employee' => $employee->fullName(),
            'employee_code' => (string) $employee->employee_id,
            'designation' => (string) $employee->designation,
            'department' => (string) $employee->department,
            'location' => (string) ($employee->location?->name ?? ''),
            'date_of_joining' => $employee->date_of_joining?->format('d F Y') ?? '',
            'company' => (string) (CompanyLetterhead::data()['legal_name'] ?? ''),
            'today' => Carbon::today()->format('d F Y'),
        ], array_map(fn ($value): string => (string) $value, $extra));
    }

    /**
     * @param  array<string, string|null>  $extra
     *
     * @throws InvalidArgumentException when the company has not written this letter
     */
    public function renderPdf(Employee $employee, string $kind, array $extra = []): PdfDocument
    {
        $template = LetterTemplate::for($kind);

        if ($template === null) {
            throw new InvalidArgumentException(
                'There is no active '.(LetterTemplate::KINDS[$kind] ?? $kind)
                .'. Write one under Settings → Letter Templates.'
            );
        }

        $values = $this->values($employee, $extra);

        return Pdf::document('letter', 'pdfs.custom-letter', $this->letters->data($employee, [
            'to' => $employee->fullName(),
            'subject' => $template->renderSubject($values),
            'body' => $template->render($values),
        ]))->format('a4')->name($this->filename($employee, $kind));
    }

    public function filename(Employee $employee, string $kind): string
    {
        $name = str($employee->fullName() ?: $employee->employee_id)->slug()->value();

        return str_replace('_', '-', $kind).'-letter-'.($name ?: 'employee').'-'.Carbon::today()->format('Y-m-d').'.pdf';
    }
}
