<?php

namespace App\Modules\Campaigns\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A message to a segment.
 *
 * **WhatsApp requires a pre-approved template**, asserted here rather than only documented.
 * Meta's Cloud API permits only template messages outside a 24-hour customer-service window,
 * so a campaign with free text on that channel does not merely fail — repeated attempts risk
 * the company's number, which is the reputational damage §6 calls this module's distinguishing
 * feature.
 */
class Campaign extends Model
{
    use Auditable;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'name', 'channel', 'subject', 'template_name', 'body',
        'segment_id', 'scheduled_at', 'sent_at', 'status', 'created_by',
    ];

    protected $casts = ['scheduled_at' => 'datetime', 'sent_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'channel' => self::CHANNEL_EMAIL];

    protected static function booted(): void
    {
        static::creating(function (self $campaign): void {
            $campaign->created_by ??= auth()->id();
        });

        static::saving(function (self $campaign): void {
            $campaign->assertChannelRequirements();
        });
    }

    /**
     * A WhatsApp campaign needs a template; an email needs a subject.
     *
     * Enforced on the model rather than in the form, because a campaign can also be created by
     * an import or by tinker, and this is the constraint whose violation costs the company its
     * WhatsApp number rather than merely erroring.
     */
    private function assertChannelRequirements(): void
    {
        if ($this->channel === self::CHANNEL_WHATSAPP && trim((string) $this->template_name) === '') {
            throw new InvalidArgumentException(
                'A WhatsApp campaign needs a pre-approved template name. Free text cannot be sent outside a '
                .'24-hour service window: the API refuses it, and repeated attempts put the number at risk.'
            );
        }

        if ($this->channel === self::CHANNEL_EMAIL && trim((string) $this->subject) === '') {
            throw new InvalidArgumentException('An email campaign needs a subject line.');
        }
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSendable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SCHEDULED]);
    }

    public function isSendable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    public function isWhatsApp(): bool
    {
        return $this->channel === self::CHANNEL_WHATSAPP;
    }

    /**
     * What happened, once it has been sent.
     *
     * `skipped_no_consent` is reported as its own figure rather than folded into failures: a
     * skip is the system working correctly, and burying it in a failure count would make
     * somebody try to "fix" it.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function outcome(): array
    {
        $sends = $this->sends()->get();

        return [
            'sent' => $sends->where('status', CampaignSend::STATUS_SENT)->count(),
            'failed' => $sends->where('status', CampaignSend::STATUS_FAILED)->count(),
            'skipped' => $sends->where('status', CampaignSend::STATUS_SKIPPED_NO_CONSENT)->count(),
        ];
    }
}
