<?php

namespace App\Modules\Crm\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Crm\Models\FieldVisit;
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
    public function __construct(private readonly WhatsAppSender $whatsApp) {}

    /** @param  array{latitude: float|string, longitude: float|string, accuracy_m?: int|string|null}|null  $position */
    public function checkIn(FieldVisit $visit, ?array $position): FieldVisit
    {
        if ($visit->isCheckedIn()) {
            throw new InvalidArgumentException('Already checked in to this visit.');
        }

        $visit->update([
            'checked_in_at' => now(),
            'in_latitude' => $position ? (float) $position['latitude'] : null,
            'in_longitude' => $position ? (float) $position['longitude'] : null,
            'in_accuracy_m' => isset($position['accuracy_m']) ? (int) $position['accuracy_m'] : null,
        ]);

        return $visit->refresh();
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

    public function confirm(FieldVisit $visit, string $name): FieldVisit
    {
        if (! $visit->isConfirmed()) {
            $visit->update(['confirmed_at' => now(), 'confirmed_name' => trim($name)]);
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
