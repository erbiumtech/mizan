<?php

namespace App\Modules\Leave\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Leave\Models\LeaveRequest;
use App\Support\Broadcasting;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person who asked what was decided — and, when refused, why.
 *
 * Wording goes through TemplatedMail so a company can say this in its own words,
 * which is the same route ExpenseClaimDecided takes. The template key is
 * `leave_request_decided`.
 */
class LeaveRequestDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LeaveRequest $request) {}

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $refused = $this->request->status === LeaveRequest::STATUS_REFUSED;

        $lines = [
            "Your request for {$this->typeLabel()} from {$this->range()} was "
                .($refused ? 'refused.' : 'approved.'),
            $refused
                ? 'Reason: '.$this->request->refusal_reason
                : "It uses {$this->days()} day(s) of your balance.",
        ];

        return TemplatedMail::apply(
            new MailMessage,
            'leave_request_decided',
            [
                'employee_name' => $notifiable->name,
                'leave_type' => $this->typeLabel(),
                'from_date' => $this->request->from_date->format('d M Y'),
                'to_date' => $this->request->to_date->format('d M Y'),
                'days' => $this->days(),
                'decision' => $refused ? 'refused' : 'approved',
                'reason' => $this->request->refusal_reason,
                'company' => Company::current()?->name,
            ],
            subject: 'Leave '.($refused ? 'refused' : 'approved').': '.$this->range(),
            greeting: "Hello {$notifiable->name},",
            lines: $lines,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $refused = $this->request->status === LeaveRequest::STATUS_REFUSED;

        $notification = FilamentNotification::make()
            ->title('Leave '.($refused ? 'refused' : 'approved'))
            ->body("{$this->typeLabel()}, {$this->range()}"
                .($refused
                    ? ' — refused: '.$this->request->refusal_reason
                    : " — {$this->days()} day(s)"));

        $refused ? $notification->danger() : $notification->success();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function typeLabel(): string
    {
        return $this->request->leaveType?->label ?? 'leave';
    }

    private function range(): string
    {
        $from = $this->request->from_date->format('d M Y');

        if ($this->request->is_half_day) {
            return "{$from} (half day)";
        }

        return $this->request->from_date->isSameDay($this->request->to_date)
            ? $from
            : $from.' to '.$this->request->to_date->format('d M Y');
    }

    private function days(): string
    {
        return rtrim(rtrim(number_format((float) $this->request->days, 1), '0'), '.');
    }
}
