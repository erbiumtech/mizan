<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site photograph — `docs/construction-management-plan.md` §16.1.
 *
 * **In its own table rather than as a CDE container, and §16.1 says why in one sentence:** "a site photo has no
 * revision, no suitability code and no approval, and forcing thousands of them into the ISO 19650 register creates junk
 * containers and buries the drawings the register exists for". Fifty photographs a day for two years is thirty thousand
 * containers in a register whose whole purpose is that somebody can find the current issue of a drawing in it.
 *
 * So the register is not the store — and the handful that become as-built evidence are **promoted** into it, which is
 * the exception §16.1 names. Promotion is deliberately gated on the register's own create permission rather than the
 * diary's: whoever may write a diary is not automatically whoever may put a container in the register, and that
 * distinction is the only thing standing between the register and the junk it was kept out of.
 *
 * **`concealed_work` is the subject that carries money.** Reinforcement before the pour, services before the screed,
 * a membrane before the backfill: the photograph is the only evidence the work was ever there, and six months on it is
 * the difference between an accepted element and one somebody wants opened up.
 */
class DailyLogPhoto extends Model
{
    public const SUBJECT_PROGRESS = 'progress';

    public const SUBJECT_CONCEALED_WORK = 'concealed_work';

    public const SUBJECT_AS_BUILT = 'as_built';

    /** @var array<string, string> */
    public const SUBJECTS = [
        self::SUBJECT_PROGRESS => 'Progress',
        self::SUBJECT_CONCEALED_WORK => 'Concealed work',
        'defect' => 'Defect',
        'safety' => 'Safety',
        'damage' => 'Damage',
        'delivery' => 'Delivery',
        'weather' => 'Weather',
        self::SUBJECT_AS_BUILT => 'As-built',
        'other' => 'Other',
    ];

    /**
     * The subjects worth promoting to the register, and the reason the *promote* action exists.
     *
     * Not a restriction — a progress photograph can be as-built evidence too, and the action does not refuse one. It is
     * what the table highlights, so the twenty photographs that matter are not lost among three thousand that do not.
     */
    public const EVIDENTIAL_SUBJECTS = [self::SUBJECT_CONCEALED_WORK, self::SUBJECT_AS_BUILT];

    protected $table = 'construction_daily_log_photos';

    protected $fillable = [
        'daily_log_id', 'location_id', 'subject', 'caption', 'description',
        'taken_at', 'taken_by', 'file_path', 'file_name', 'file_size', 'file_mime', 'file_hash',
        'delivery_id', 'event_id', 'promoted_document_id', 'promoted_at', 'promoted_by',
    ];

    protected $casts = [
        'taken_at' => 'datetime',
        'promoted_at' => 'datetime',
    ];

    protected $attributes = [
        'subject' => self::SUBJECT_PROGRESS,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $photo): void {
            $photo->taken_by ??= auth()->id();
        });

        /*
         * **A promoted photograph cannot be deleted from the diary.**
         *
         * Promotion is one file and two rows — the register's revision carries the same path, so that an adjudicator is
         * shown the same image and not a re-encoding of it. Deleting this row would leave a register container pointing
         * at a file nobody can produce, which is worse than never having promoted it: the register's whole value is
         * that what it lists exists.
         */
        static::deleting(function (self $photo): void {
            if ($photo->isPromoted()) {
                throw new \InvalidArgumentException(
                    'This photograph is in the document register, so it cannot be deleted from the diary. Archive the '
                    .'register container instead — the register listing a file nobody can produce is worse than a '
                    .'photograph nobody wanted.'
                );
            }
        });
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class, 'daily_log_id');
    }

    /**
     * Where it was taken — §16.5's shared tree, in the spine.
     *
     * A real relation rather than an integer, unlike the trade and the machine on the other children: locations live in
     * `construction`, which this module requires, so naming the class costs the boundary nothing.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(DailyLogDelivery::class, 'delivery_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(DailyLogEvent::class, 'event_id');
    }

    /** The register container this photograph became, where somebody promoted it. */
    public function promotedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'promoted_document_id');
    }

    public function scopePromoted(Builder $query): Builder
    {
        return $query->whereNotNull('promoted_document_id');
    }

    /** Concealed work and as-built: the photographs somebody will go looking for. */
    public function scopeEvidential(Builder $query): Builder
    {
        return $query->whereIn('subject', self::EVIDENTIAL_SUBJECTS);
    }

    public function isPromoted(): bool
    {
        return $this->promoted_document_id !== null;
    }

    public function isEvidential(): bool
    {
        return in_array($this->subject, self::EVIDENTIAL_SUBJECTS, true);
    }

    /**
     * Evidence of covered work that is not in the register — the gap worth a screen.
     *
     * A photograph of rebar sitting only in a diary is findable by whoever remembers the date. In the register it has
     * an identifier, a location and a suitability code, and it is findable by whoever needs it in year four.
     */
    public function needsPromoting(): bool
    {
        return $this->isEvidential() && ! $this->isPromoted();
    }

    public function subjectLabel(): string
    {
        return self::SUBJECTS[$this->subject] ?? $this->subject;
    }

    public function displayName(): string
    {
        return $this->caption;
    }
}
