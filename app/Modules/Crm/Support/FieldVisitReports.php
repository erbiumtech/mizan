<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\FieldVisit;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;

/** The two visit reports — docs/saas-launch-plan.md §8.5: per rep with the confirmed share, and the unconfirmed list. */
class FieldVisitReports
{
    use ReportShapes;

    public function perRep(string $asOf): array
    {
        $to = Carbon::parse($asOf);
        $from = $to->copy()->startOfMonth();

        $visits = FieldVisit::query()->with('visitor')->completed()->between($from->toDateString(), $to->toDateString())->get();
        $byRep = $visits->groupBy('employee_id');

        $rows = $byRep->map(function ($group) {
            $confirmed = $group->whereNotNull('confirmed_at')->count();

            return [
                $group->first()->visitor?->display_label ?? '—',
                $group->count(),
                $confirmed,
                $group->count() ? round($confirmed / $group->count() * 100).'%' : '—',
                $group->where('outcome', FieldVisit::OUTCOME_POSITIVE)->count(),
                $group->where('outcome', FieldVisit::OUTCOME_NOT_MET)->count(),
            ];
        })->sortByDesc(1)->values()->all();

        $confirmedAll = $visits->whereNotNull('confirmed_at')->count();

        return $this->table(
            'VisitsPerRep',
            'Visits per Rep',
            $this->subtitle($from->format('d M').' – '.$to->format('d M Y')),
            ['Rep', 'Visits', 'Confirmed', 'Confirmed share', 'Positive', 'Not met'],
            'minmax(0, 1fr) 7rem 8rem 9rem 7rem 7rem',
            [1, 2, 4, 5],
            $rows,
            [
                ['label' => 'VISITS', 'value' => $visits->count(), 'accent' => true],
                ['label' => 'CONFIRMED', 'value' => $confirmedAll, 'accent' => false],
            ],
            mb_strtoupper(sprintf('%d reps · %s confirmed by the person visited', $byRep->count(), $visits->count() ? round($confirmedAll / $visits->count() * 100).'%' : '—')),
            null,
            'No visits completed this month.',
        );
    }

    public function unconfirmed(string $asOf): array
    {
        $visits = FieldVisit::query()->with(['visitor', 'subject'])->unconfirmed()
            ->whereDate('checked_out_at', '<=', $asOf)
            ->orderBy('checked_out_at')
            ->get();

        $rows = $visits->map(fn (FieldVisit $v): array => [
            $v->checked_out_at->format('d M Y'),
            $v->visitor?->display_label ?? '—',
            $v->subjectLabel(),
            FieldVisit::OUTCOMES[$v->outcome] ?? '—',
            $v->confirmation_sent_to ?: 'nowhere to send',
            $v->mapLink() ? 'yes' : 'no',
        ])->all();

        return $this->table(
            'UnconfirmedVisits',
            'Unconfirmed Visits',
            $this->subtitle('to '.$asOf),
            ['Visited', 'Rep', 'Visiting', 'Outcome', 'Link sent to', 'Location'],
            '8rem minmax(0, 1fr) minmax(0, 1fr) 8rem 14rem 6rem',
            [],
            $rows,
            [['label' => 'UNCONFIRMED', 'value' => $visits->count(), 'accent' => true]],
            mb_strtoupper($visits->count().' visits the person visited has not confirmed'),
            null,
            'Every completed visit has been confirmed.',
        );
    }
}
