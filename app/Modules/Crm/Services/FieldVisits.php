<?php

namespace App\Modules\Crm\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Models\FieldVisitPlace;
use App\Modules\Crm\Models\FieldVisitRefusal;
use App\Modules\Crm\Models\FieldVisitStaff;
use App\Modules\Crm\Notifications\VisitConfirmationRequest;
use App\Support\CompanyLetterhead;
use App\Support\WhatsApp\WhatsAppSender;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Throwable;

/**
 * Check-in, check-out, and the witness's confirmation — docs/saas-launch-plan.md §8.5.
 *
 * Check-out sends the contact a temporary signed link, by email when there is
 * an address and by WhatsApp when there is only a number. A send that fails is
 * reported and not thrown: the visit happened whether or not the message got
 * through, and the manager's report shows it as unconfirmed either way.
 */
class FieldVisits
{
    /** Record the coordinates and judge nothing — how this worked before §14.10. */
    public const MODE_OFF = 'off';

    /** Judge it, record the verdict, and let the check-in through. */
    public const MODE_FLAG = 'flag';

    /** Judge it and turn away a check-in that is not at the place. */
    public const MODE_REFUSE = 'refuse';

    public const MODES = [
        self::MODE_OFF => 'Record the position only',
        self::MODE_FLAG => 'Record and flag a visit that is not at the place',
        self::MODE_REFUSE => 'Refuse a check-in that is not at the place',
    ];

    public function __construct(private readonly WhatsAppSender $whatsApp) {}

    /**
     * Check in, and judge where they were — docs/saas-launch-plan.md §14.10.
     *
     * **The verdict is advisory until a company says otherwise.** `off` records the
     * coordinates and judges nothing, which is how this has worked since §8.5; `flag`
     * writes the verdict and lets the check-in through; `refuse` turns it away. The
     * default is `flag`, because a company that has not thought about it wants to see
     * the problem rather than have its reps locked out of their own calls.
     *
     * Every refusal is recorded. A rep standing in the right place whose phone puts
     * them three hundred metres away has a problem somebody should be able to see.
     *
     * @param  array{latitude: float|string, longitude: float|string, accuracy_m?: int|string|null}|null  $position
     */
    public function checkIn(FieldVisit $visit, ?array $position): FieldVisit
    {
        if ($visit->isCheckedIn()) {
            throw new InvalidArgumentException('Already checked in to this visit.');
        }

        if (! FieldVisitStaff::allows($visit->employee_id)) {
            $this->refuse($visit, FieldVisitRefusal::REASON_NOT_ENROLLED, $position);

            throw new InvalidArgumentException('That person is not enrolled for field visits.');
        }

        $judgement = $this->judge($visit, $position);

        if ($this->mode() === self::MODE_REFUSE && in_array($judgement['verdict'], [FieldVisit::VERDICT_AWAY, FieldVisit::VERDICT_UNVERIFIED], true)) {
            $this->refuse($visit, $judgement['verdict'] === FieldVisit::VERDICT_AWAY
                ? FieldVisitRefusal::REASON_AWAY
                : FieldVisitRefusal::REASON_INACCURATE, $position, $judgement['distance']);

            throw new InvalidArgumentException($judgement['verdict'] === FieldVisit::VERDICT_AWAY
                ? 'You are '.number_format((float) $judgement['distance']).'m from the place. Check in when you are there.'
                : 'Your phone cannot say where you are accurately enough. Wait for a better signal and try again.');
        }

        $visit->update([
            'checked_in_at' => now(),
            'in_latitude' => $position ? (float) $position['latitude'] : null,
            'in_longitude' => $position ? (float) $position['longitude'] : null,
            'in_accuracy_m' => isset($position['accuracy_m']) ? (int) $position['accuracy_m'] : null,
            'in_verdict' => $judgement['verdict'],
            'in_distance_m' => $judgement['distance'] === null ? null : (int) round($judgement['distance']),
        ]);

        return $visit->refresh();
    }

    /**
     * Where the check-in stood against the client's pin.
     *
     * Null verdict means nothing judged it: the check is off, or nobody has pinned the
     * place, or no coordinates were sent. That is deliberately not the same as `away` —
     * a company reading this list has to be able to tell "we looked and they were not
     * there" from "we never looked".
     *
     * @param  array<string, mixed>|null  $position
     * @return array{verdict: ?string, distance: ?float}
     */
    public function judge(FieldVisit $visit, ?array $position): array
    {
        if ($this->mode() === self::MODE_OFF || $position === null) {
            return ['verdict' => null, 'distance' => null];
        }

        $place = FieldVisitPlace::forSubject($visit->subject_type, $visit->subject_id);

        if ($place === null) {
            return ['verdict' => null, 'distance' => null];
        }

        $distance = $place->distanceTo((float) $position['latitude'], (float) $position['longitude']);

        /*
         * A reading vaguer than the limit cannot be judged either way.
         *
         * Calling it "away" would punish a rep for being indoors, and calling it "here"
         * would accept a reading that could be a kilometre out. The honest answer is
         * that nobody knows, and the list says so.
         */
        $accuracy = isset($position['accuracy_m']) ? (int) $position['accuracy_m'] : null;

        if ($accuracy !== null && $accuracy > $this->accuracyLimit()) {
            return ['verdict' => FieldVisit::VERDICT_UNVERIFIED, 'distance' => $distance];
        }

        return [
            'verdict' => $place->contains((float) $position['latitude'], (float) $position['longitude'])
                ? FieldVisit::VERDICT_HERE
                : FieldVisit::VERDICT_AWAY,
            'distance' => $distance,
        ];
    }

    /** Off, flag or refuse — the same three the attendance geofence offers. */
    public function mode(): string
    {
        $mode = (string) setting('crm.field_visits.geofence_mode', self::MODE_FLAG);

        return in_array($mode, [self::MODE_OFF, self::MODE_FLAG, self::MODE_REFUSE], true) ? $mode : self::MODE_FLAG;
    }

    /** How vague a reading may be and still be judged, in metres. */
    public function accuracyLimit(): int
    {
        $limit = (int) setting('crm.field_visits.accuracy_limit_m', 100);

        return $limit > 0 ? $limit : 100;
    }

    /** @param  array<string, mixed>|null  $position */
    private function refuse(FieldVisit $visit, string $reason, ?array $position, ?float $distance = null): void
    {
        FieldVisitRefusal::record([
            'field_visit_id' => $visit->getKey(),
            'employee_id' => $visit->employee_id,
            'reason' => $reason,
            'latitude' => $position['latitude'] ?? null,
            'longitude' => $position['longitude'] ?? null,
            'accuracy_m' => isset($position['accuracy_m']) ? (int) $position['accuracy_m'] : null,
            'distance_m' => $distance === null ? null : (int) round($distance),
        ]);
    }

    /** @param  array{latitude: float|string, longitude: float|string}|null  $position */
    public function checkOut(FieldVisit $visit, ?array $position, ?string $notes = null, ?string $outcome = null): FieldVisit
    {
        if (! $visit->isCheckedIn()) {
            throw new InvalidArgumentException('Check in before checking out.');
        }

        if ($visit->isCheckedOut()) {
            throw new InvalidArgumentException('Already checked out of this visit.');
        }

        if ($outcome !== null && ! isset(FieldVisit::OUTCOMES[$outcome])) {
            throw new InvalidArgumentException("Unknown outcome \"{$outcome}\".");
        }

        $visit->update([
            'checked_out_at' => now(),
            'out_latitude' => $position ? (float) $position['latitude'] : null,
            'out_longitude' => $position ? (float) $position['longitude'] : null,
            'notes' => $notes ?? $visit->notes,
            'outcome' => $outcome ?? $visit->outcome,
        ]);

        if ($visit->next_action_id && $visit->nextAction && $visit->nextAction->isOpen()) {
            $visit->nextAction->update(['completed_at' => now(), 'completed_by' => auth()->id()]);
        }

        $this->requestConfirmation($visit);

        return $visit->refresh();
    }

    /** Send (or resend) the witness's link. Returns where it went, or null when there was nowhere to send. */
    public function requestConfirmation(FieldVisit $visit): ?string
    {
        if (! $this->enabled() || ! $visit->isCheckedOut() || $visit->isConfirmed()) {
            return null;
        }

        $company = $this->company();

        if ($company === null) {
            return null;
        }

        $message = new VisitConfirmationRequest(
            CompanyLetterhead::data()['legal_name'] ?: $company->name,
            $visit->visitor?->fullName() ?? 'Our representative',
            $visit->checked_out_at->format('d M Y'),
            $this->confirmationUrl($visit, $company),
        );

        $email = $visit->contactEmail();
        $number = $email ? null : $visit->contactWhatsApp();

        try {
            if ($email) {
                Notification::route('mail', $email)->notify($message);
            } elseif ($number) {
                $this->whatsApp->sendText($number, $message->toText());
            } else {
                return null;
            }
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $visit->update(['confirmation_sent_to' => $email ?: 'whatsapp:'.$number]);

        return $email ?: 'whatsapp:'.$number;
    }

    /**
     * The client's confirmation, and what they thought of it — docs/saas-launch-plan.md §14.10.
     *
     * The rating is optional and stays optional. The page's job is to get a confirmation
     * out of somebody who was not expecting to be asked for anything; a required star
     * rating is how a confirmation rate falls.
     */
    public function confirm(FieldVisit $visit, string $name, ?int $rating = null, ?string $comment = null): FieldVisit
    {
        if (! $visit->isConfirmed()) {
            $visit->update([
                'confirmed_at' => now(),
                'confirmed_name' => trim($name),
                'rating' => isset(FieldVisit::RATINGS[(int) $rating]) ? (int) $rating : null,
                'rating_comment' => blank($comment) ? null : \Illuminate\Support\Str::limit(trim((string) $comment), 190, ''),
            ]);
        }

        return $visit->refresh();
    }

    public function confirmationUrl(FieldVisit $visit, Company $company): string
    {
        return URL::temporarySignedRoute(
            'crm.visits.confirm',
            Carbon::now()->addHours((int) config('crm.field_visits.confirmation_link_hours', 24)),
            ['company' => $company->slug, 'visit' => $visit->getKey()],
        );
    }

    /** The spatie tenant on a request or job; the panel's tenant inside Filament, as DocumentVerification reads it. */
    private function company(): ?Company
    {
        $company = Company::current();

        if ($company === null) {
            $tenant = Filament::getTenant();
            $company = $tenant instanceof Company ? $tenant : null;
        }

        return $company;
    }

    public function enabled(): bool
    {
        return (bool) setting('crm.field_visits.enabled', config('crm.field_visits.enabled', false));
    }
}
