<?php

namespace App\Modules\Campaigns\Models;

use App\Models\TenantModel as Model;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One intended recipient, and what became of it.
 *
 * A row per recipient exists so that a skip is visible. `skipped_no_consent` is the outcome
 * that matters most: it is the system declining to contact somebody who has not agreed, and it
 * has to be reported as a distinct figure rather than a silence — otherwise nobody can tell a
 * campaign that reached nobody from one that was never sent.
 *
 * The unique keys on (campaign, contact) and (campaign, lead) are the guard against a retry
 * double-sending. On WhatsApp that is not merely annoying: repeated messages risk the number.
 */
class CampaignSend extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED_NO_CONSENT = 'skipped_no_consent';

    /**
     * The two reasons a send is skipped, as constants rather than as prose written at the call site.
     *
     * They mean different things and the difference matters. The first is a segment built from people who
     * never agreed — a list-building problem. The second is somebody who withdrew in the gap between
     * preparing a campaign and sending it, which `CampaignSender::send()` calls out as "exactly when a
     * complaint comes from"; catching it is the guard working, not a failure.
     *
     * Here because the column belongs to this model, and because the consent register report has to tell the
     * two apart — matching on a sentence typed in a service is not a contract.
     */
    public const REASON_NO_CONSENT = 'No consent on record for this channel. Silence is not consent.';

    public const REASON_CONSENT_WITHDRAWN = 'Consent was withdrawn before this was sent.';

    protected $fillable = [
        'campaign_id', 'contact_id', 'lead_id', 'channel', 'to',
        'status', 'sent_at', 'failed_reason',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** The recipient, whichever kind it is. */
    public function recipient(): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->contact ?? $this->lead;
    }

    public function recipientLabel(): string
    {
        return $this->contact?->name ?? $this->lead?->display_label ?? 'Unknown';
    }
}
