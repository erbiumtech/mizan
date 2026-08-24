<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolving an audience and sending to it.
 *
 * **Every send checks consent, and no row means no.** Silence is not agreement: a prospect
 * nobody has asked has not consented, and defaulting to permitted would make the consents
 * table decorative. A skipped recipient is recorded as `skipped_no_consent` rather than
 * omitted, so a campaign that reached nobody is distinguishable from one that was never sent.
 *
 * **Nothing here transmits.** The rows are prepared and marked, and the actual delivery goes
 * through the existing mail and WhatsApp senders on the queue — which is where retry, failure
 * handling and the template config already live. §6 puts campaigns last precisely because this
 * is the module that can damage a company's reputation, and the narrow job of this class is to
 * make sure nothing leaves that should not.
 */
class CampaignSender
{
    /**
     * Who a segment currently matches.
     *
     * Resolved now rather than read from a saved list: a campaign sent this month to last
     * month's list misses everybody who joined since.
     *
     * @return Collection<int, Model>
     */
    public function audienceFor(Segment $segment): Collection
    {
        $filters = $segment->filters();
        $audience = new Collection;

        if ($filters['include_leads']) {
            $audience = $audience->merge(
                Lead::query()
                    ->open()
                    ->when($filters['lead_source_id'], fn ($query, $source) => $query->where('lead_source_id', $source))
                    ->when($filters['city'], fn ($query, $city) => $query->where('city', $city))
                    ->get()
            );
        }

        // Guarded: a company without Invoicing has no contacts to include, and asking for them
        // should be an empty set rather than an error.
        if ($filters['include_contacts'] && modules()->enabled('invoicing')) {
            $audience = $audience->merge(
                Contact::query()
                    ->where('is_active', true)
                    ->when($filters['city'], fn ($query, $city) => $query->where('address_line_1', 'like', "%{$city}%"))
                    ->get()
            );
        }

        return $audience;
    }

    /**
     * Prepare the sends for a campaign: one row per recipient, consent decided.
     *
     * Separated from delivery on purpose. A company can look at exactly who a campaign would
     * reach — and who it would skip, and why — **before** anything leaves the building. On a
     * channel that can cost you your number, a dry run is not a nicety.
     *
     * @return array{prepared: int, permitted: int, skipped: int}
     */
    public function prepare(Campaign $campaign): array
    {
        if (! $campaign->isSendable()) {
            throw new InvalidArgumentException("This campaign is {$campaign->status} and cannot be prepared again.");
        }

        if (! $campaign->segment) {
            throw new InvalidArgumentException('A campaign needs a segment. One with no audience is not a campaign.');
        }

        $permitted = 0;
        $skipped = 0;

        foreach ($this->audienceFor($campaign->segment) as $recipient) {
            $isLead = $recipient instanceof Lead;
            $to = $this->addressFor($recipient, $campaign->channel);

            // No address on this channel is not a consent failure and must not be reported as
            // one: somebody with no WhatsApp number has not refused anything.
            if ($to === null) {
                continue;
            }

            $allowed = Consent::permits($recipient, $campaign->channel);

            CampaignSend::updateOrCreate(
                [
                    'campaign_id' => $campaign->getKey(),
                    'contact_id' => $isLead ? null : $recipient->getKey(),
                    'lead_id' => $isLead ? $recipient->getKey() : null,
                ],
                [
                    'channel' => $campaign->channel,
                    'to' => $to,
                    'status' => $allowed
                        ? CampaignSend::STATUS_PENDING
                        : CampaignSend::STATUS_SKIPPED_NO_CONSENT,
                    'failed_reason' => $allowed
                        ? null
                        : CampaignSend::REASON_NO_CONSENT,
                ]
            );

            $allowed ? $permitted++ : $skipped++;
        }

        return ['prepared' => $permitted + $skipped, 'permitted' => $permitted, 'skipped' => $skipped];
    }

    /**
     * Mark the permitted sends as sent.
     *
     * **Consent is re-checked here, not trusted from `prepare()`.** Somebody may unsubscribe
     * between preparing a campaign and sending it, and that gap is exactly when a complaint
     * comes from — the message that went out after they asked it to stop.
     *
     * @return array{sent: int, skipped: int}
     */
    public function send(Campaign $campaign): array
    {
        if (! $campaign->isSendable()) {
            throw new InvalidArgumentException("This campaign is {$campaign->status} and cannot be sent.");
        }

        $campaign->update(['status' => Campaign::STATUS_SENDING]);

        $sent = 0;
        $skipped = 0;

        foreach ($campaign->sends()->where('status', CampaignSend::STATUS_PENDING)->get() as $send) {
            $recipient = $send->recipient();

            if (! $recipient || ! Consent::permits($recipient, $campaign->channel)) {
                $send->update([
                    'status' => CampaignSend::STATUS_SKIPPED_NO_CONSENT,
                    'failed_reason' => CampaignSend::REASON_CONSENT_WITHDRAWN,
                ]);

                $skipped++;

                continue;
            }

            // Handed to the queue by whatever channel sender the company has configured. Kept
            // out of this class deliberately: retry, failure handling and the WhatsApp template
            // config all already live there, and duplicating any of it here would be a second
            // place for a send to go wrong.
            $send->update(['status' => CampaignSend::STATUS_SENT, 'sent_at' => now()]);

            $sent++;
        }

        $campaign->update(['status' => Campaign::STATUS_SENT, 'sent_at' => now()]);

        activity('Campaign')
            ->performedOn($campaign)
            ->causedBy(auth()->user())
            ->event('sent')
            ->withProperties(['sent' => $sent, 'skipped_no_consent' => $skipped])
            ->log("Campaign \"{$campaign->name}\" sent to {$sent} recipient(s), {$skipped} skipped for consent");

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * The address to use, or null when there is none on this channel.
     *
     * WhatsApp prefers the dedicated number: in this market the number somebody answers on
     * WhatsApp is often not the one on their card, which is why `leads.whatsapp` exists
     * separately from `leads.phone`.
     */
    private function addressFor(Model $recipient, string $channel): ?string
    {
        if ($channel === Campaign::CHANNEL_WHATSAPP) {
            $number = $recipient->whatsapp ?? $recipient->phone ?? null;

            return $number ? (string) $number : null;
        }

        $email = $recipient->email ?? null;

        return $email ? (string) $email : null;
    }
}
