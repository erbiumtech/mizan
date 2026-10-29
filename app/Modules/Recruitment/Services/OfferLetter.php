<?php

namespace App\Modules\Recruitment\Services;

use App\Modules\Recruitment\Models\Offer;
use App\Support\CompanyLetterhead;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "We would like you to join us, on these terms" — the offer as a letter the candidate can hold
 * — docs/saas-launch-plan.md §7.2.
 *
 * The fourth sibling of the income certificate, the experience letter and the custom letter: the same
 * letterhead, the same position. **Nothing is stored** — rendered from the offer row each time, so a
 * withdrawn or re-negotiated offer never has a stale PDF on disk claiming otherwise. **It refuses rather
 * than guesses**: a candidate reads this as the company's word, so every figure comes from the record and
 * a gap is named, not papered over with "PKR 0".
 */
class OfferLetter
{
    /**
     * What is missing before this offer can be put on paper, in the reader's words.
     *
     * @return array<int, string>
     */
    public function missingFor(Offer $offer): array
    {
        $offer->loadMissing('application.applicant', 'application.vacancy');
        $missing = [];

        if (blank($offer->application?->applicant?->name)) {
            $missing[] = 'the candidate\'s name (Recruitment → Applicants)';
        }

        if (blank($offer->application?->vacancy?->designation) && blank($offer->application?->vacancy?->title)) {
            $missing[] = 'the position — a designation or title on the vacancy (Recruitment → Vacancies)';
        }

        if ((float) $offer->salary <= 0) {
            $missing[] = 'a basic salary on the offer';
        }

        if ($offer->joining_date === null) {
            $missing[] = 'a joining date on the offer';
        }

        return [...$missing, ...CompanyLetterhead::missing()];
    }

    public function filename(Offer $offer): string
    {
        $name = Str::slug((string) ($offer->application?->applicant?->name ?? ''));

        return 'offer-letter-'.($name ?: 'candidate').'-'.Carbon::today()->format('Y-m-d').'.pdf';
    }

    /** @param  array<string, mixed>  $input */
    public function renderPdf(Offer $offer, array $input = []): PdfDocument
    {
        return Pdf::view('pdfs.offer-letter', $this->data($offer, $input))
            ->format('a4')
            ->name($this->filename($offer));
    }

    /**
     * @param  array<string, mixed>  $input  `valid_days` (how long the candidate has to answer),
     *                                       `signatory_name` / `signatory_title` overrides
     * @return array<string, mixed>
     */
    public function data(Offer $offer, array $input = []): array
    {
        $offer->loadMissing('application.applicant', 'application.vacancy');
        $applicant = $offer->application->applicant;
        $vacancy = $offer->application->vacancy;
        $issuedOn = Carbon::today();
        $validDays = max(1, (int) ($input['valid_days'] ?? 7));

        // The offer's components are code => amount; the letter prints them as a person would read
        // them. No lookup into Payroll: a company without it still makes offers, and the code's own
        // words ("medical_allowance") read fine as "Medical allowance".
        $components = collect((array) $offer->components)
            ->filter(fn ($amount): bool => (float) $amount > 0)
            ->map(fn ($amount, string $code): array => ['label' => Str::headline($code), 'amount' => (float) $amount])
            ->values()
            ->all();

        return [
            'applicant' => $applicant,
            'vacancy' => $vacancy,
            'offer' => $offer,
            'company' => CompanyLetterhead::data(),
            'signatory' => CompanyLetterhead::signatory($input),
            'issued_on' => $issuedOn,
            'valid_until' => $issuedOn->copy()->addDays($validDays),
            'reference' => CompanyLetterhead::reference('OFR/'.$offer->getKey(), $issuedOn),
            'position' => (string) ($vacancy?->designation ?: $vacancy?->title),
            'department' => (string) ($vacancy?->department ?? ''),
            'employment_type' => (string) ($vacancy?->employment_type ?? ''),
            'basic' => (float) $offer->salary,
            'components' => $components,
            'gross' => round((float) $offer->salary + array_sum(array_column($components, 'amount')), 2),
        ];
    }
}
