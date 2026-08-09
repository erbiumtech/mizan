<?php

namespace App\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The employee's payslip, by email, with the PDF attached.
 *
 * Carries the payslip's id rather than the rendered file: it is queued, the PDF
 * is rendered from the payslip as it stands when the mail is actually sent (see
 * PayslipService::renderPdf, which never reads a stored file), and a few hundred
 * kilobytes of base64 in the queue payload would be paid for on every retry.
 */
class PayslipIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payslip $payslip) {}

    public function via(object $notifiable): array
    {
        // Recipients with no user account (personal-email-only staff) are
        // routed on demand and can only ever receive mail.
        return $notifiable instanceof User
            ? ['mail', 'database', 'broadcast']
            : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payslips = app(PayslipService::class);
        $period = $this->period();

        $employee = $this->payslip->employee?->user?->name ?? 'there';
        $net = number_format((float) $this->payslip->net_salary, 2);

        // The wording below is what is sent unless the company has written its own —
        // see TemplatedMail. The PDF is attached either way.
        $mail = TemplatedMail::apply(
            new MailMessage,
            'payslip_issued',
            [
                'employee_name' => $employee,
                'period' => $period,
                'net_salary' => $net,
                'company' => \App\Modules\Core\Models\Company::current()?->name,
            ],
            subject: "Your payslip for {$period}",
            greeting: "Hello {$employee},",
            lines: [
                "Your payslip for {$period} is attached.",
                "Net salary: {$net}.",
                'Please open it and confirm the figures are right. If something looks wrong, reject it in the portal with a note and payroll will look again.',
            ],
        );

        return $mail
            ->attachData(
                $payslips->renderPdf($this->payslip)->raw(),
                $payslips->pdfFilename($this->payslip),
                ['mime' => 'application/pdf'],
            );
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Payslip issued')
            ->body("Your payslip for {$this->period()} is ready.")
            ->success()
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function period(): string
    {
        return trim($this->payslip->month.' '.($this->payslip->fiscalYear?->name ?? ''));
    }
}
