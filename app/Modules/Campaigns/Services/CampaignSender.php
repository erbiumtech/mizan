<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Jobs\DeliverCampaign;
use App\Modules\Campaigns\Mail\CampaignMessage;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Core\Models\Company;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Models\Contact;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\SendsTemplates;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Resolving an audience and sending to it.
 *
 * **Every send checks consent, and no row means no.** Silence is not agreement: a prospect
 * nobody has asked has not consented, and defaulting to permitted would make the consents
 * table decorative. A skipped recipient is recorded as `skipped_no_consent` rather than
 * omitted, so a campaign that reached nobody is distinguishable from one that was never sent.
 *
 * **Delivery is on the queue, a batch at a time.** `send()` hands the campaign to DeliverCampaign,
 * which calls `deliverBatch()` at the pace Company Settings → Campaigns sets; each message goes
 * through the mail or WhatsApp sender this application already has, and every recipient's row says
 * what happened to them — sent, failed with the provider's reason, or skipped for consent. §6 puts
 * campaigns last precisely because this is the module that can damage a company's reputation.
 */
class CampaignSender
{
    public function __construct(private readonly WhatsAppSender $whatsApp) {}

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

    /** Messages per batch, and the pause between batches — Company Settings → Campaigns. */
    public const DEFAULT_BATCH_SIZE = 50;

    public const DEFAULT_BATCH_INTERVAL_SECONDS = 60;

    public static function batchSize(): int
    {
        return max(1, (int) setting('campaigns.send.batch_size', self::DEFAULT_BATCH_SIZE));
    }

    public static function batchInterval(): int
    {
        return max(0, (int) setting('campaigns.send.batch_interval_seconds', self::DEFAULT_BATCH_INTERVAL_SECONDS));
    }

    /**
     * Start sending, or carry on from where a stopped send left off.
     *
     * Nothing is delivered here: the campaign is marked `sending` and handed to the queue, where
     * DeliverCampaign works through it a batch at a time at the company's pace. A campaign nobody
     * checked the audience of is prepared first, so pressing Send is never a send to nobody.
     *
     * What it returns is the position once the first batch has been handed over: everything, where
     * the queue runs inline; the skips and the number still queued, where a worker has it.
     *
     * @return array{sent: int, failed: int, skipped: int, queued: int}
     */
    public function send(Campaign $campaign): array
    {
        if (! $campaign->isSendable() && ! $campaign->isResumable()) {
            throw new InvalidArgumentException("This campaign is {$campaign->status} and cannot be sent.");
        }

        if ($campaign->isSendable() && ! $campaign->sends()->exists()) {
            $this->prepare($campaign);
        }

        // A worker that died mid-message left its claim behind; nobody knows whether that one went.
        $this->releaseInterrupted($campaign);

        $campaign->update(['status' => Campaign::STATUS_SENDING]);

        activity('Campaign')
            ->performedOn($campaign)
            ->causedBy(auth()->user())
            ->event('sending')
            ->log("Campaign \"{$campaign->name}\" handed to the queue");

        DeliverCampaign::dispatch($campaign->getKey());

        $outcome = $campaign->refresh()->outcome();

        return ['sent' => $outcome['sent'], 'failed' => $outcome['failed'], 'skipped' => $outcome['skipped'], 'queued' => $outcome['pending']];
    }

    /** Put the recipients that failed back in the queue, and send on. @return int how many */
    public function retryFailed(Campaign $campaign): int
    {
        $count = $campaign->sends()->where('status', CampaignSend::STATUS_FAILED)
            ->update(['status' => CampaignSend::STATUS_PENDING, 'failed_reason' => null]);

        if ($count > 0) {
            $campaign->update(['status' => Campaign::STATUS_PAUSED]);
            $this->send($campaign->refresh());
        }

        return $count;
    }

    /**
     * Deliver the next batch. Called by DeliverCampaign; returns how many are still pending.
     *
     * **Consent is re-checked for every message, not trusted from `prepare()`.** Somebody may
     * unsubscribe between preparing a campaign and sending it, and that gap is exactly when a
     * complaint comes from — the message that went out after they asked it to stop.
     *
     * One recipient's refusal is recorded against that recipient and the batch goes on. Anything
     * else — the database, the lock, a bug — pauses the campaign before it is rethrown, so it is never
     * left saying `sending` with nothing sending it, and Send resumes it.
     *
     * **Nobody is sent the same message twice.** Each row is claimed (`sending`) before its message is
     * handed over and marked after; a row whose batch dies between the two is not resent on resume —
     * whether it went is unknown — but failed with that reason, for somebody to retry deliberately.
     */
    public function deliverBatch(Campaign $campaign): int
    {
        if ($campaign->status !== Campaign::STATUS_SENDING) {
            return 0;
        }

        // One batch at a time per campaign: a resume pressed while a batch is running must not
        // send the same pending rows twice.
        $lock = Cache::lock('campaign-delivery:'.(Company::current()?->getKey() ?? 0).':'.$campaign->getKey(), 600);

        if (! $lock->get()) {
            return -1;
        }

        try {
            $batch = $campaign->sends()
                ->with(['contact', 'lead'])
                ->where('status', CampaignSend::STATUS_PENDING)
                ->orderBy('id')
                ->limit(self::batchSize())
                ->get();

            foreach ($batch as $send) {
                $this->deliver($campaign, $send);
            }

            $remaining = $campaign->sends()->where('status', CampaignSend::STATUS_PENDING)->count();

            if ($remaining === 0) {
                $outcome = $campaign->outcome();
                $campaign->update(['status' => Campaign::STATUS_SENT, 'sent_at' => now()]);

                activity('Campaign')
                    ->performedOn($campaign)
                    ->event('sent')
                    ->withProperties(['sent' => $outcome['sent'], 'failed' => $outcome['failed'], 'skipped_no_consent' => $outcome['skipped']])
                    ->log("Campaign \"{$campaign->name}\" sent to {$outcome['sent']} recipient(s), {$outcome['failed']} failed, {$outcome['skipped']} skipped for consent");
            }

            return $remaining;
        } catch (Throwable $e) {
            $this->releaseInterrupted($campaign);
            $campaign->forceFill(['status' => Campaign::STATUS_PAUSED])->save();

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function releaseInterrupted(Campaign $campaign): void
    {
        $campaign->sends()->where('status', CampaignSend::STATUS_SENDING)
            ->update(['status' => CampaignSend::STATUS_FAILED, 'failed_reason' => CampaignSend::REASON_INTERRUPTED]);
    }

    private function deliver(Campaign $campaign, CampaignSend $send): void
    {
        $recipient = $send->recipient();

        if (! $recipient || ! Consent::permits($recipient, $campaign->channel)) {
            $send->update(['status' => CampaignSend::STATUS_SKIPPED_NO_CONSENT, 'failed_reason' => CampaignSend::REASON_CONSENT_WITHDRAWN]);

            return;
        }

        $send->update(['status' => CampaignSend::STATUS_SENDING]);

        $name = trim((string) ($recipient->person_name ?? $recipient->name ?? ''));
        $fill = fn (?string $text): string => str_replace('{name}', $name, (string) $text);

        try {
            if ($campaign->isWhatsApp()) {
                if (! $this->whatsApp instanceof SendsTemplates) {
                    throw new WhatsAppException(CampaignSend::REASON_NO_TEMPLATES);
                }

                $this->whatsApp->sendTemplate(
                    PhoneNumber::e164((string) $send->to) ?? throw new WhatsAppException("{$send->to} is not a number WhatsApp can reach."),
                    (string) $campaign->template_name,
                    // The body holds the template's variables, one per line, {{1}} first.
                    array_values(array_filter(array_map('trim', preg_split('/\R/', $fill($campaign->body)) ?: []), fn (string $line): bool => $line !== '')),
                );
            } else {
                Mail::to((string) $send->to)->send(new CampaignMessage($fill($campaign->subject), $fill($campaign->body)));
            }
        } catch (Throwable $e) {
            // The provider's own words, on the recipient's row — a bounced address is expected, not
            // an incident, so it is recorded rather than reported.
            $send->update(['status' => CampaignSend::STATUS_FAILED, 'failed_reason' => Str::limit($e->getMessage(), 250, '')]);

            return;
        }

        $send->update(['status' => CampaignSend::STATUS_SENT, 'sent_at' => now(), 'failed_reason' => null]);
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
