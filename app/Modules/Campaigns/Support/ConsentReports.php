<?php

namespace App\Modules\Campaigns\Support;

use App\Modules\Campaigns\Models\Consent;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Models\Contact;
use App\Support\Reporting\ReportShapes;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The consent register — `docs/reports-expansion-plan.md` Phase 3.10.
 *
 * "Consent state per contact per channel with source and date. This is compliance evidence, not marketing
 * statistics, which is why it belongs with the reports."
 *
 * **That last clause governs everything here.** There is no opt-in rate, no channel comparison and no trend.
 * A marketing figure answers "how are we doing"; this report answers "who agreed to this, when, and how" —
 * which `Consent`'s own docblock calls "the only defensible answer when somebody complains".
 *
 * **The state is derived, never counted.** `consents` has no unique key on subject and channel, on purpose:
 * every grant and every revocation is its own row, so somebody opting in, out and in again leaves three. The
 * register is therefore the *latest* row per subject per channel, resolved exactly the way
 * `Consent::permits()` resolves it — most recent `recorded_at`, then highest `id` where two share a timestamp.
 * Matching that tie-break matters: a report that broke the tie the other way would state a permission the
 * sender does not act on, which on a compliance report is the worst kind of wrong.
 *
 * **It is a true as-at.** The latest row *on or before* the date being read, so the question "what did we
 * have permission for on 30 June" has an answer. A pair whose only rows come later is absent rather than
 * shown as revoked: there was nothing on the register then, and `permits()` is explicit that no row means no.
 *
 * **The finding is a grant with no source.** The migration says why in as many words: "'they agreed' is worth
 * nothing without 'and here is how'". A granted consent with no source is a permission the company cannot
 * defend, and it is indistinguishable on every other screen from one it can.
 */
class ConsentReports
{
    use ReportShapes;

    private const NO_SOURCE = 0;

    private const NO_RECORDER = 1;

    private const GRANTED = 2;

    private const REVOKED = 3;

    public function consentRegister(string $asOf): array
    {
        $on = Carbon::parse($asOf);

        /** @var Collection<int, Consent> $history */
        $history = Consent::query()
            ->whereDate('recorded_at', '<=', $on->toDateString())
            // Ascending, so the last row seen per pair is the current one — and the same two keys
            // `Consent::permits()` sorts on, read the other way round.
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->with(['subject', 'recorder'])
            ->get();

        if ($history->isEmpty()) {
            return $this->emptyRegister($asOf);
        }

        $current = [];
        $changes = [];

        foreach ($history as $row) {
            $key = $row->subject_type.'#'.$row->subject_id.'@'.$row->channel;
            $current[$key] = $row;
            $changes[$key] = ($changes[$key] ?? 0) + 1;
        }

        $rows = [];
        $granted = 0;
        $revoked = 0;
        $sourceless = 0;
        $unrecorded = 0;
        $totalChanges = 0;

        foreach ($current as $key => $row) {
            $isGranted = $row->state === Consent::STATE_GRANTED;
            $hasSource = trim((string) $row->source) !== '';
            $hasRecorder = $row->recorded_by !== null;

            $rows[] = [
                'rank' => $this->rank($isGranted, $hasSource, $hasRecorder),
                'recorded' => $row->recorded_at->toDateTimeString(),
                'cells' => [
                    (string) $this->subjectLabel($row),
                    (string) str($row->channel)->replace('_', ' ')->title(),
                    $isGranted ? 'Granted' : 'Revoked',
                    // "Not recorded" rather than a dash: on this report the missing source *is* the finding,
                    // and a dash reads as a column that does not apply.
                    $hasSource ? (string) $row->source : 'Not recorded',
                    (string) $row->recorded_at->toDateString(),
                    (string) ($row->recorder?->name ?? 'Not recorded'),
                    number_format($changes[$key]),
                ],
            ];

            $granted += $isGranted ? 1 : 0;
            $revoked += $isGranted ? 0 : 1;
            // Counted on grants only. A revocation with no source is somebody being removed from a list,
            // which needs no justification — the asymmetry is the point, since only a permission has to be
            // defended.
            $sourceless += $isGranted && ! $hasSource ? 1 : 0;
            $unrecorded += $isGranted && ! $hasRecorder ? 1 : 0;
            $totalChanges += $changes[$key];
        }

        $rows = $this->ordered($rows);

        return $this->table(
            'ConsentRegister',
            'Consent Register',
            $this->subtitle('as recorded on or before '.$asOf),
            ['Subject', 'Channel', 'State', 'Source', 'Recorded', 'Recorded by', 'Changes'],
            'minmax(0, 1fr) 9rem 9rem minmax(9rem, 14rem) 9rem 12rem 8rem',
            [6],
            $rows,
            [
                ['label' => 'GRANTED', 'value' => (float) $granted, 'accent' => true],
                ['label' => 'WITHOUT A SOURCE', 'value' => (float) $sourceless, 'accent' => false],
            ],
            $this->registerNote(count($rows), $granted, $revoked, $sourceless, $unrecorded),
            $rows === [] ? null : [
                'Total — '.count($rows).' subject/channel pairs',
                '',
                $granted.' granted',
                '',
                '',
                '',
                number_format($totalChanges),
            ],
            'Nobody has been asked for consent on any channel.',
        );
    }

    /**
     * How urgently a row needs looking at.
     *
     * Grants that cannot be defended first, because a permission without evidence behind it is the only thing
     * on this report that has to be fixed rather than merely known. Revocations last: they are the safe state
     * and nothing about them is at risk.
     */
    private function rank(bool $isGranted, bool $hasSource, bool $hasRecorder): int
    {
        return match (true) {
            ! $isGranted => self::REVOKED,
            ! $hasSource => self::NO_SOURCE,
            ! $hasRecorder => self::NO_RECORDER,
            default => self::GRANTED,
        };
    }

    /**
     * Rows in the order they need acting on, newest first within each group.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function ordered(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => [$a['rank'], $b['recorded']]
            // Recorded-at reversed deliberately — `$b` before `$a` on that element alone — so rank sorts
            // ascending and the date descending inside it.
            <=> [$b['rank'], $a['recorded']]);

        return array_map(fn (array $row): array => $row['cells'], $rows);
    }

    /**
     * Who the consent is about, named the way each kind of subject is named elsewhere.
     *
     * The kind is on the label rather than in a column of its own: a lead and a contact can share a name, and
     * a register that did not distinguish them would be evidence about the wrong person. Falls back to the
     * stored alias and id where the subject has since been deleted — the row is still evidence that consent
     * was recorded, and blanking it would lose that.
     */
    private function subjectLabel(Consent $consent): string
    {
        $subject = $consent->subject;

        return match (true) {
            $subject instanceof Contact => 'Contact · '.$subject->name,
            $subject instanceof Lead => 'Lead · '.$subject->display_label,
            $subject instanceof EloquentModel => class_basename($subject).' #'.$subject->getKey(),
            default => 'Deleted · '.$consent->subject_type.' #'.$consent->subject_id,
        };
    }

    /**
     * What the register amounts to, evidence gaps first.
     *
     * The granted and revoked split leads because it is the register's own summary. The two gaps follow, and
     * both are worded as what they cost rather than as a count: a reader who sees "3 granted with no source"
     * has to work out why that matters, and one who is told it is not evidence of anything does not.
     */
    private function registerNote(int $pairs, int $granted, int $revoked, int $sourceless, int $unrecorded): string
    {
        return mb_strtoupper(implode(' · ', array_filter([
            $pairs.' subject/channel pairs',
            $granted.' granted, '.$revoked.' revoked',
            $sourceless > 0
                ? $sourceless.' granted with no source, which is not evidence of anything'
                : 'every permission has a source recorded against it',
            $unrecorded > 0
                ? $unrecorded.' granted with nobody recorded against '.($unrecorded === 1 ? 'it' : 'them')
                : null,
        ])));
    }

    /** @return array<string, mixed> */
    private function emptyRegister(string $asOf): array
    {
        return $this->table(
            'ConsentRegister',
            'Consent Register',
            $this->subtitle('as recorded on or before '.$asOf),
            ['Subject', 'Channel', 'State', 'Source', 'Recorded', 'Recorded by', 'Changes'],
            'minmax(0, 1fr) 9rem 9rem minmax(9rem, 14rem) 9rem 12rem 8rem',
            [6],
            [],
            [
                ['label' => 'GRANTED', 'value' => 0.0, 'accent' => true],
                ['label' => 'WITHOUT A SOURCE', 'value' => 0.0, 'accent' => false],
            ],
            // Not "nothing to report". An empty register means nobody may be contacted on any channel, which
            // is a fact somebody about to run a campaign needs stated rather than implied.
            'NOBODY HAS BEEN ASKED FOR CONSENT ON ANY CHANNEL, SO NOBODY MAY BE CONTACTED',
            null,
            'Nobody has been asked for consent on any channel.',
        );
    }
}
