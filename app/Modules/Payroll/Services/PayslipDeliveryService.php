<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayslipMediaLink;
use App\Notifications\PayslipIssued;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppDocument;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Sending a payslip to the person it belongs to, by email and on WhatsApp.
 *
 * Deliberate rather than automatic. A payslip is recalculated on every save and
 * corrected after it is first cut — an allowance fixed, attendance entered, an
 * advance instalment picked up — so sending on creation would have posted an
 * employee three copies of a figure that was wrong twice. Payroll releases the
 * month when it is ready, and `sent_at` records that it went.
 *
 * A send that partly works is reported as such. Email and WhatsApp fail for
 * unrelated reasons — a bounced address, a number never registered on WhatsApp, a
 * template still in review — and rolling them into one "failed" would leave
 * whoever is sending payroll unable to tell what to fix or whether to send again.
 */
class PayslipDeliveryService
{
    public function __construct(
        private PayslipService $payslips,
        private WhatsAppSender $whatsapp,
    ) {}

    /**
     * Send one payslip.
     *
     * @param  bool  $resend  send again to somebody who already has it — used by
     *                        the resend action, refused by default so a second
     *                        release of the month does not repost everything
     * @return array{email: string|null, whatsapp: string|null, errors: array<int, string>, sent: bool}
     */
    public function send(Payslip $payslip, bool $resend = false): array
    {
        $payslip->loadMissing('employee.user', 'fiscalYear');

        if (! $payslip->employee) {
            throw new InvalidArgumentException("Payslip #{$payslip->id} has no employee to send to.");
        }

        if ($payslip->sent_at && ! $resend) {
            throw new InvalidArgumentException(
                "This payslip was already sent on {$payslip->sent_at->format('d M Y H:i')}. Use Resend to send it again."
            );
        }

        $result = ['email' => null, 'whatsapp' => null, 'errors' => [], 'sent' => false];

        if ($this->channelEnabled('email')) {
            $result = $this->email($payslip, $result);
        }

        if ($this->channelEnabled('whatsapp')) {
            $result = $this->whatsapp($payslip, $result);
        }

        // Stamped only when something actually reached somebody. A payslip marked
        // sent that nobody received is worse than one that is plainly unsent: the
        // month looks done and nobody chases it.
        if ($result['email'] || $result['whatsapp']) {
            $payslip->forceFill(['sent_at' => now()])->save();
            $result['sent'] = true;
        }

        activity('Payslip')
            ->performedOn($payslip)
            ->withProperties([
                'email' => $result['email'],
                'whatsapp' => $result['whatsapp'],
                'errors' => $result['errors'],
                'resend' => $resend,
            ])
            ->log($result['sent']
                ? 'Payslip sent to the employee'
                : 'Payslip could not be sent to the employee');

        return $result;
    }

    /**
     * @param  array{email: string|null, whatsapp: string|null, errors: array<int, string>, sent: bool}  $result
     * @return array{email: string|null, whatsapp: string|null, errors: array<int, string>, sent: bool}
     */
    protected function email(Payslip $payslip, array $result): array
    {
        // The company address is the payslip's proper destination — it is the
        // login, and it is what payroll correspondence goes to. The personal one
        // is the fallback for staff who have no company mailbox, which for cooks
        // and drivers is the normal case.
        $user = $payslip->employee->user;
        $address = $user?->email ?: $payslip->employee->personal_email;

        if (blank($address)) {
            $result['errors'][] = 'No email address on the employee record.';

            return $result;
        }

        try {
            if ($user && $user->email === $address) {
                Notification::send($user, new PayslipIssued($payslip));
            } else {
                Notification::route('mail', $address)->notify(new PayslipIssued($payslip));
            }

            $result['email'] = $address;
        } catch (\Throwable $e) {
            $result['errors'][] = "Email to {$address} failed: {$e->getMessage()}";
        }

        return $result;
    }

    /**
     * @param  array{email: string|null, whatsapp: string|null, errors: array<int, string>, sent: bool}  $result
     * @return array{email: string|null, whatsapp: string|null, errors: array<int, string>, sent: bool}
     */
    protected function whatsapp(Payslip $payslip, array $result): array
    {
        $employee = $payslip->employee;
        $number = PhoneNumber::e164($employee->phone) ?? PhoneNumber::e164($employee->secondary_phone);

        if ($number === null) {
            $result['errors'][] = blank($employee->phone) && blank($employee->secondary_phone)
                ? 'No phone number on the employee record.'
                : 'The phone number on the employee record is not a number WhatsApp can reach.';

            return $result;
        }

        $period = trim($payslip->month.' '.($payslip->fiscalYear?->name ?? ''));

        try {
            $result['whatsapp'] = $this->whatsapp->sendDocument(
                to: $number,
                document: $this->document($payslip),
                caption: "Payslip for {$period}",
            );
        } catch (WhatsAppException $e) {
            $result['errors'][] = "WhatsApp to +{$number} failed: {$e->getMessage()}";
        }

        return $result;
    }

    /**
     * The payslip as something a provider can take, whichever way it wants it.
     *
     * Meta uploads the file; Twilio fetches a link. Both are built lazily so the
     * PDF is rendered once, by whichever one is actually in use, and the URL is
     * only signed if somebody is going to fetch it.
     *
     * The signed URL carries the filename as its last segment because Twilio
     * cannot set one on a document — that segment is what the recipient's phone
     * shows the file as.
     */
    protected function document(Payslip $payslip): WhatsAppDocument
    {
        $filename = $this->payslips->pdfFilename($payslip);
        // Filament's tenant first, then spatie's: the same pair every other
        // tenant-aware read in this app uses, and the only one set in a single-
        // database environment (see Company::activate).
        $company = Filament::getTenant() ?? Company::current();

        return new WhatsAppDocument(
            filename: $filename,
            bytes: fn (): string => $this->payslips->renderPdf($payslip)->raw(),
            url: $company instanceof Company ? fn (): string => PayslipMediaLink::for(
                $payslip,
                $company,
                $filename,
                (int) config('whatsapp.media_url_ttl', 10),
            ) : null,
        );
    }

    /**
     * Per company, so one can send payroll by email only and another by both.
     * Both on by default: a company that switched neither on wants what it asked
     * for, which is for its employees to receive their payslips.
     */
    protected function channelEnabled(string $channel): bool
    {
        return (bool) setting("payroll.payslip.send.{$channel}", true);
    }
}
