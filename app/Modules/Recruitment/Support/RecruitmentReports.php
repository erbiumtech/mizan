<?php

namespace App\Modules\Recruitment\Support;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The hiring funnel — `docs/reports-expansion-plan.md` Phase 3.2.
 *
 * "Applications by stage per vacancy, offer acceptance rate, days from applied → offer → joining, and
 * open-vacancy ageing." Four questions on one table, because they are asked together: a vacancy with fifty
 * applicants and no offers, and one with two applicants and an offer declined, are different problems and
 * neither is visible in the other's figures.
 *
 * **Nothing here reconciles, and it does not pretend to.** Phase 3 is operational reporting — there is no
 * ledger account behind a hiring funnel, and Phase 2's rule about record rows tying to balances does not
 * apply. What it owes a reader instead is not overstating what it knows, which is the whole of the
 * arithmetic below: a rate with no denominator is a dash, not a nought, and an average over one applicant is
 * still stated but the count is beside it.
 *
 * **Wide, because a funnel is its stages.** Six stage columns plus four measures and the vacancy is more
 * than the pane is wide, and collapsing the stages into a total would remove the only thing that makes it a
 * funnel.
 */
class RecruitmentReports
{
    use ReportShapes;

    /**
     * The stages in the order somebody moves through them.
     *
     * `withdrawn` is deliberately absent from the columns. An applicant who withdrew left of their own
     * accord: counting them beside rejections would read as the company's decision, and counting them in the
     * funnel at all would make the stage counts stop summing to the applications. They are in the total and
     * named in the note.
     *
     * @var array<string, string>
     */
    private const STAGES = [
        Application::STAGE_APPLIED => 'Applied',
        Application::STAGE_SCREENING => 'Screening',
        Application::STAGE_INTERVIEW => 'Interview',
        Application::STAGE_OFFER => 'Offer',
        Application::STAGE_HIRED => 'Hired',
        Application::STAGE_REJECTED => 'Rejected',
    ];

    public function funnel(string $asOf): array
    {
        $date = Carbon::parse($asOf);

        $vacancies = Vacancy::query()
            ->whereDate('opened_on', '<=', $date->toDateString())
            ->orWhereNull('opened_on')
            ->orderByDesc('opened_on')
            ->get();

        if ($vacancies->isEmpty()) {
            return $this->emptyFunnel($asOf);
        }

        // Applications and their offers in two queries, whatever the number of vacancies.
        $applications = Application::query()
            ->whereIn('vacancy_id', $vacancies->modelKeys())
            ->get()
            ->groupBy('vacancy_id');

        $offers = Offer::query()
            // `pluck('id')` rather than `modelKeys()`: `$applications` is grouped, and flattening a
            // collection of Eloquent collections gives a base collection, which has no `modelKeys()`.
            ->whereIn('application_id', $applications->flatten()->pluck('id')->all())
            ->get()
            ->groupBy('application_id');

        $columns = ['Vacancy', ...array_values(self::STAGES), 'Offers taken', 'To offer', 'To join', 'Open for'];

        $rows = [];
        $withdrawn = 0;
        $totalApplications = 0;
        $accepted = 0;
        $answered = 0;

        foreach ($vacancies as $vacancy) {
            /** @var Collection<int, Application> $forVacancy */
            $forVacancy = $applications->get($vacancy->getKey()) ?? collect();

            $cells = [(string) $vacancy->title.($vacancy->code ? ' · '.$vacancy->code : '')];

            foreach (array_keys(self::STAGES) as $stage) {
                $count = $forVacancy->where('stage', $stage)->count();
                // A dash for a stage nobody has reached, so the shape of the funnel is legible at a glance
                // rather than a wall of noughts.
                $cells[] = $count > 0 ? number_format($count) : '—';
            }

            $vacancyOffers = $forVacancy
                ->flatMap(fn (Application $application): Collection => $offers->get($application->getKey()) ?? collect());

            $cells[] = $this->acceptanceRate($vacancyOffers);
            $cells[] = $this->averageDays($this->daysToOffer($forVacancy, $vacancyOffers));
            $cells[] = $this->averageDays($this->daysToJoin($vacancyOffers));
            $cells[] = $this->openFor($vacancy, $date);

            $rows[] = $cells;

            $withdrawn += $forVacancy->where('stage', Application::STAGE_WITHDRAWN)->count();
            $totalApplications += $forVacancy->count();
            $accepted += $vacancyOffers->where('status', Offer::STATUS_ACCEPTED)->count();
            $answered += $vacancyOffers
                ->whereIn('status', [Offer::STATUS_ACCEPTED, Offer::STATUS_DECLINED])
                ->count();
        }

        return $this->table(
            'HiringFunnel',
            'Hiring Funnel',
            $this->subtitle('vacancies opened by '.$asOf),
            $columns,
            'minmax(12rem, 18rem) '.str_repeat('6.5rem ', count(self::STAGES)).'8rem 7rem 7rem 8rem',
            range(1, count($columns) - 1),
            $rows,
            [
                ['label' => 'APPLICATIONS', 'value' => (float) $totalApplications, 'accent' => true],
                ['label' => 'OFFERS ACCEPTED', 'value' => (float) $accepted, 'accent' => false],
            ],
            $this->funnelNote($vacancies, $totalApplications, $accepted, $answered, $withdrawn),
            null,
            'No vacancy has been opened.',
            wide: true,
        );
    }

    /**
     * Offers accepted as a proportion of offers *answered*.
     *
     * Not of offers issued. An offer somebody has not replied to yet is not a refusal, and counting it as one
     * would make a company that had just issued three offers look as though it had lost them. A dash where
     * nothing has been answered, because a rate with no denominator is not nought per cent.
     *
     * @param  Collection<int, Offer>  $offers
     */
    private function acceptanceRate(Collection $offers): string
    {
        $answered = $offers->whereIn('status', [Offer::STATUS_ACCEPTED, Offer::STATUS_DECLINED]);

        if ($answered->isEmpty()) {
            return '—';
        }

        $accepted = $answered->where('status', Offer::STATUS_ACCEPTED)->count();

        return number_format($accepted / $answered->count() * 100, 0).'%';
    }

    /**
     * Days from applying to being offered, per offer that was issued.
     *
     * Measured to `issued_at` rather than to the offer being *created*: a draft offer nobody has sent is not
     * a milestone the candidate has reached.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, Offer>  $offers
     * @return array<int, int>
     */
    private function daysToOffer(Collection $applications, Collection $offers): array
    {
        $appliedOn = $applications->mapWithKeys(
            fn (Application $application): array => [$application->getKey() => $application->applied_on],
        );

        $days = [];

        foreach ($offers as $offer) {
            $applied = $appliedOn->get($offer->application_id);

            if ($applied === null || $offer->issued_at === null) {
                continue;
            }

            $days[] = (int) Carbon::parse($applied)->startOfDay()->diffInDays($offer->issued_at->startOfDay());
        }

        return $days;
    }

    /**
     * Days from the offer going out to the joining date on it.
     *
     * Only accepted offers. A declined offer has a joining date nobody is going to honour, and averaging it
     * in would describe a notice period that never happened.
     *
     * @param  Collection<int, Offer>  $offers
     * @return array<int, int>
     */
    private function daysToJoin(Collection $offers): array
    {
        $days = [];

        foreach ($offers->where('status', Offer::STATUS_ACCEPTED) as $offer) {
            if ($offer->issued_at === null || $offer->joining_date === null) {
                continue;
            }

            $days[] = (int) $offer->issued_at->startOfDay()->diffInDays($offer->joining_date->startOfDay());
        }

        return $days;
    }

    /**
     * The mean, as a whole number of days, or a dash where there is nothing to average.
     *
     * The mean rather than the median, because it is the figure people quote and the one they can check by
     * adding up a handful of rows. Over a small number of hires either is a rough guide, which is why the
     * application count is on the same row.
     *
     * @param  array<int, int>  $days
     */
    private function averageDays(array $days): string
    {
        return $days === [] ? '—' : number_format(array_sum($days) / count($days), 0);
    }

    /**
     * How long a vacancy has been open, in days — and a dash once it is not.
     *
     * Ageing is only a question about something still open. A filled vacancy's age is a historical fact and
     * putting it in the same column would invite the two to be averaged together.
     */
    private function openFor(Vacancy $vacancy, Carbon $asOf): string
    {
        if (! in_array($vacancy->status, [Vacancy::STATUS_OPEN, Vacancy::STATUS_ON_HOLD], true)) {
            return '—';
        }

        if ($vacancy->opened_on === null) {
            return '—';
        }

        return number_format((int) $vacancy->opened_on->startOfDay()->diffInDays($asOf->startOfDay())).'d';
    }

    /**
     * What the funnel says overall, and the two things the columns cannot show.
     *
     * Withdrawals, because they are absent from the stage columns by design — an applicant who withdrew left
     * of their own accord, and counting them beside rejections would read as the company's decision. And the
     * company-wide acceptance rate, because a rate per vacancy over two offers is noise and the aggregate is
     * the only version of it worth quoting.
     *
     * @param  Collection<int, Vacancy>  $vacancies
     */
    private function funnelNote(Collection $vacancies, int $applications, int $accepted, int $answered, int $withdrawn): string
    {
        if ($applications === 0) {
            return mb_strtoupper($vacancies->count().' vacancies · nobody has applied yet');
        }

        $open = $vacancies
            ->whereIn('status', [Vacancy::STATUS_OPEN, Vacancy::STATUS_ON_HOLD])
            ->count();

        return mb_strtoupper(implode(' · ', array_filter([
            $vacancies->count().' vacancies, '.$open.' still open',
            $answered > 0
                ? 'offers taken '.number_format($accepted / $answered * 100, 0).'% of those answered'
                : 'no offer has been answered yet',
            $withdrawn > 0
                ? $withdrawn.($withdrawn === 1 ? ' applicant' : ' applicants').' withdrew, not counted as rejected'
                : null,
        ])));
    }

    /** @return array<string, mixed> */
    private function emptyFunnel(string $asOf): array
    {
        return $this->table(
            'HiringFunnel',
            'Hiring Funnel',
            $this->subtitle('vacancies opened by '.$asOf),
            ['Vacancy', ...array_values(self::STAGES), 'Offers taken', 'To offer', 'To join', 'Open for'],
            'minmax(12rem, 18rem) '.str_repeat('6.5rem ', count(self::STAGES)).'8rem 7rem 7rem 8rem',
            [],
            [],
            [
                ['label' => 'APPLICATIONS', 'value' => 0.0, 'accent' => true],
                ['label' => 'OFFERS ACCEPTED', 'value' => 0.0, 'accent' => false],
            ],
            'NO VACANCY HAS BEEN OPENED',
            null,
            'No vacancy has been opened.',
            wide: true,
        );
    }
}
