<?php

namespace App\Modules\Campaigns\Support;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Campaign performance — `docs/reports-expansion-plan.md` Phase 3.11.
 *
 * "Sends, failures and reasons per campaign."
 *
 * **The skip count is the figure this report exists for**, and `CampaignSend` says why in its own docblock:
 * `skipped_no_consent` "has to be reported as a distinct figure rather than a silence — otherwise nobody can
 * tell a campaign that reached nobody from one that was never sent". Every other view of a campaign shows what
 * went out. This one shows what did not, and why.
 *
 * **The two skip reasons are separated, because they are different problems.**
 *
 *  - `REASON_NO_CONSENT` — the segment contained people who had never agreed. A list-building problem, and it
 *    points straight at the consent register: the segment and the register disagree about who may be reached.
 *  - `REASON_CONSENT_WITHDRAWN` — somebody withdrew in the gap between the campaign being prepared and being
 *    sent. `CampaignSender::send()` calls that gap "exactly when a complaint comes from", so this figure is
 *    the guard *working*: the message that would have caused the complaint did not go.
 *
 * **A campaign marked sent with pending rows never finished.** `send()` walks every pending row and leaves
 * each one either sent or skipped, then marks the campaign sent — so a sent campaign with pending rows means
 * the loop stopped part way. Nothing else in the application notices, because the campaign's own status says
 * it went out.
 *
 * **What this report deliberately does not do is compare the send count against the segment.** A recipient
 * with no address on the channel gets no row at all — `prepare()` skips them with a bare `continue`, on the
 * stated grounds that "somebody with no WhatsApp number has not refused anything" — so the send count is
 * genuinely lower than the audience and nothing records the difference. It is tempting to recover that by
 * re-running the segment, and it would be wrong: `audienceFor()` evaluates the filters *live*, so it returns
 * today's audience and not the one that existed when the campaign went out. Comparing them would manufacture
 * a finding on every campaign whose segment has since gained a member. The gap is real; it is stated in the
 * help rather than guessed at here.
 */
class CampaignReports
{
    use ReportShapes;

    public function campaignPerformance(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $campaigns = Campaign::query()
            // Anything that has been sent, is going out, or was stopped. A draft has no performance to report
            // and a scheduled one has not started.
            ->whereIn('status', [Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED])
            ->where(function ($query) use ($period): void {
                $query
                    ->whereBetween('sent_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59'])
                    // A campaign still in flight, or one cancelled before it went, has no `sent_at` — so it
                    // is placed in the period by when it was created. Without this the unfinished runs the
                    // report exists to surface would be the ones it could not see.
                    ->orWhere(fn ($inner) => $inner
                        ->whereNull('sent_at')
                        ->whereBetween('created_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']));
            })
            ->get();

        if ($campaigns->isEmpty()) {
            return $this->emptyPerformance($period);
        }

        $counts = $this->countsPerCampaign($campaigns);
        $reasons = $this->failureReasons($campaigns);

        $rows = [];
        $totals = ['recipients' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0];
        $unfinished = 0;

        foreach ($campaigns->sortByDesc(fn (Campaign $campaign): string => $this->effectiveDate($campaign)) as $campaign) {
            $count = $counts[$campaign->getKey()] ?? [];

            $sent = (int) ($count[CampaignSend::STATUS_SENT] ?? 0);
            $failed = (int) ($count[CampaignSend::STATUS_FAILED] ?? 0);
            $skipped = (int) ($count[CampaignSend::STATUS_SKIPPED_NO_CONSENT] ?? 0);
            $pending = (int) ($count[CampaignSend::STATUS_PENDING] ?? 0);
            $recipients = $sent + $failed + $skipped + $pending;

            $topFailure = $reasons[$campaign->getKey()] ?? null;

            $rows[] = [
                (string) $campaign->name,
                (string) str($campaign->channel)->replace('_', ' ')->title(),
                (string) $this->effectiveDate($campaign),
                number_format($recipients),
                $this->count($sent),
                $this->count($failed),
                $this->count($skipped),
                $this->standing($campaign, $sent, $recipients, $pending),
                (string) ($topFailure ?? '—'),
            ];

            $totals['recipients'] += $recipients;
            $totals['sent'] += $sent;
            $totals['failed'] += $failed;
            $totals['skipped'] += $skipped;
            $totals['pending'] += $pending;

            if ($pending > 0 && $campaign->status === Campaign::STATUS_SENT) {
                $unfinished++;
            }
        }

        $split = $this->skipSplit($campaigns);
        $neverConsented = $split[CampaignSend::REASON_NO_CONSENT] ?? 0;
        $withdrew = $split[CampaignSend::REASON_CONSENT_WITHDRAWN] ?? 0;

        return $this->table(
            'CampaignPerformance',
            'Campaign Performance',
            $this->subtitle('campaigns between '.$period['from'].' and '.$period['to']),
            ['Campaign', 'Channel', 'Date', 'Recipients', 'Sent', 'Failed', 'Skipped', 'Standing', 'Top failure'],
            'minmax(0, 14rem) 9rem 9rem 9rem 8rem 8rem 8rem 14rem minmax(12rem, 20rem)',
            [3, 4, 5, 6],
            $rows,
            [
                ['label' => 'SENT', 'value' => (float) $totals['sent'], 'accent' => true],
                // The figure `CampaignSend` insists must not be a silence.
                ['label' => 'SKIPPED, NO CONSENT', 'value' => (float) $totals['skipped'], 'accent' => false],
            ],
            $this->performanceNote(
                $campaigns->count(),
                $totals,
                $neverConsented,
                $withdrew,
                $unfinished,
                $this->dominantReason($reasons),
            ),
            $rows === [] ? null : [
                'Total — '.count($rows).' campaigns',
                '',
                '',
                number_format($totals['recipients']),
                number_format($totals['sent']),
                number_format($totals['failed']),
                number_format($totals['skipped']),
                $totals['pending'] > 0 ? number_format($totals['pending']).' pending' : '',
                '',
            ],
            'No campaign went out in this period.',
            // Nine columns, one of them free-text failure reasons. Wider than the pane, so it scrolls rather
            // than being silently clipped — Phase 0.2.
            wide: true,
        );
    }

    /**
     * Where the campaign stands, with the findings as suffixes.
     *
     * The status alone is not enough: a campaign marked *sent* that reached nobody, and one marked sent whose
     * run stopped part way, both read as a clean success on every other screen in the application.
     */
    private function standing(Campaign $campaign, int $sent, int $recipients, int $pending): string
    {
        $label = match ($campaign->status) {
            Campaign::STATUS_SENDING => 'In flight',
            Campaign::STATUS_CANCELLED => 'Cancelled',
            default => 'Sent',
        };

        return implode(' · ', array_filter([
            $label,
            // Only where the campaign believes it finished. A cancelled or in-flight campaign having pending
            // rows is expected, not a fault.
            $pending > 0 && $campaign->status === Campaign::STATUS_SENT
                ? $pending.' never processed'
                : null,
            $sent === 0 && $recipients > 0 && $campaign->status === Campaign::STATUS_SENT
                ? 'reached nobody'
                : null,
        ]));
    }

    /**
     * Send counts per campaign per status, in one grouped query.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<int, array<string, int>>
     */
    private function countsPerCampaign(Collection $campaigns): array
    {
        return CampaignSend::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->groupBy('campaign_id', 'status')
            ->selectRaw('campaign_id, status, COUNT(*) as total')
            ->get()
            ->groupBy('campaign_id')
            ->map(fn (Collection $rows): array => $rows->pluck('total', 'status')->all())
            ->all();
    }

    /**
     * The most common failure reason per campaign.
     *
     * Failures come from the channel sender, not from this module — `CampaignSender` hands delivery to
     * "whatever channel sender the company has configured" — so the reasons are whatever that layer wrote.
     * Free text, therefore grouped rather than classified: naming the most common one is honest about what is
     * known, where a tidy category would be an invention.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<int, string>
     */
    private function failureReasons(Collection $campaigns): array
    {
        return CampaignSend::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->where('status', CampaignSend::STATUS_FAILED)
            ->whereNotNull('failed_reason')
            ->groupBy('campaign_id', 'failed_reason')
            ->selectRaw('campaign_id, failed_reason, COUNT(*) as total')
            ->orderByDesc('total')
            ->get()
            ->groupBy('campaign_id')
            // First per campaign, and the query is ordered by count descending, so the first is the most
            // common. `keyBy`-style overwriting would have kept the least common instead.
            ->map(fn (Collection $rows): string => (string) $rows->first()->failed_reason)
            ->all();
    }

    /**
     * How many skips were each of the two kinds.
     *
     * Matched on the constants rather than on the sentences, which is why the constants exist.
     *
     * The `whereIn` on the two reasons is a **narrowing, not a guard** — the lookup below reads the split by
     * key, so an unrecognised reason would simply sit in the array unread. It is here to avoid dragging every
     * skip reason out of a table that grows by one row per recipient per campaign. Removing it changes no
     * output, and no test here pretends otherwise. The `where` on the status *is* load-bearing: `failed_reason`
     * carries skip reasons and delivery reasons alike, and without it a failure that happened to mention
     * consent would be counted as a skip.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<string, int>
     */
    private function skipSplit(Collection $campaigns): array
    {
        return CampaignSend::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->where('status', CampaignSend::STATUS_SKIPPED_NO_CONSENT)
            ->whereIn('failed_reason', [CampaignSend::REASON_NO_CONSENT, CampaignSend::REASON_CONSENT_WITHDRAWN])
            ->groupBy('failed_reason')
            ->selectRaw('failed_reason, COUNT(*) as total')
            ->pluck('total', 'failed_reason')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * The most common failure reason across every campaign in the period.
     *
     * Counted by how many campaigns hit it rather than by how many sends failed: one campaign to a bad list
     * can produce thousands of identical failures, and the reason worth naming is the one that keeps
     * happening to different campaigns.
     *
     * @param  array<int, string>  $reasons
     */
    private function dominantReason(array $reasons): ?string
    {
        if ($reasons === []) {
            return null;
        }

        $tally = array_count_values($reasons);
        arsort($tally);

        return (string) array_key_first($tally);
    }

    /**
     * When the campaign happened, for placing and ordering it.
     *
     * `sent_at` where there is one, otherwise when it was created — a campaign still in flight or cancelled
     * before it went has no send date, and leaving those undated would sort them arbitrarily.
     */
    private function effectiveDate(Campaign $campaign): string
    {
        return Carbon::parse($campaign->sent_at ?? $campaign->created_at)->toDateString();
    }

    /** A zero count is a dash: four count columns of noughts is unreadable, and the footer has the totals. */
    private function count(int $value): string
    {
        return $value === 0 ? '—' : number_format($value);
    }

    /**
     * What the campaigns amounted to, and the three things worth knowing beyond the counts.
     *
     * The skip split leads the findings because it is the report's reason for existing, and the two halves are
     * worded as what they mean: a list problem, and a complaint that did not happen.
     *
     * @param  array<string, int>  $totals
     */
    private function performanceNote(
        int $campaigns,
        array $totals,
        int $neverConsented,
        int $withdrew,
        int $unfinished,
        ?string $dominantReason,
    ): string {
        return mb_strtoupper(implode(' · ', array_filter([
            $campaigns.' campaigns, '.number_format($totals['recipients']).' recipients',
            number_format($totals['sent']).' sent, '.number_format($totals['failed']).' failed, '
                .number_format($totals['skipped']).' skipped',
            $neverConsented > 0
                ? $neverConsented.' had never agreed, so the segment and the consent register disagree'
                : null,
            $withdrew > 0
                ? $withdrew.' withdrew before the send and '.($withdrew === 1 ? 'was' : 'were')
                    .' caught, which is the guard working'
                : null,
            $unfinished > 0
                ? $unfinished.' campaign'.($unfinished === 1 ? '' : 's').' marked sent never finished'
                : null,
            $dominantReason !== null ? 'most common failure: '.$dominantReason : null,
        ])));
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptyPerformance(array $period): array
    {
        return $this->table(
            'CampaignPerformance',
            'Campaign Performance',
            $this->subtitle('campaigns between '.$period['from'].' and '.$period['to']),
            ['Campaign', 'Channel', 'Date', 'Recipients', 'Sent', 'Failed', 'Skipped', 'Standing', 'Top failure'],
            'minmax(0, 14rem) 9rem 9rem 9rem 8rem 8rem 8rem 14rem minmax(12rem, 20rem)',
            [3, 4, 5, 6],
            [],
            [
                ['label' => 'SENT', 'value' => 0.0, 'accent' => true],
                ['label' => 'SKIPPED, NO CONSENT', 'value' => 0.0, 'accent' => false],
            ],
            'NO CAMPAIGN WENT OUT IN THIS PERIOD',
            null,
            'No campaign went out in this period.',
            wide: true,
        );
    }
}
