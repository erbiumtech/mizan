<?php

namespace App\Modules\Core\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Core\Models\User;
use App\Support\DashboardApprovals;
use App\Support\Reporting\DashboardWidgets;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use InvalidArgumentException;
use RuntimeException;

/**
 * "Waiting for you" — the approvals card of the Material dashboard.
 *
 * Every pending item the signed-in person may decide, across the modules that register a source with
 * App\Support\DashboardApprovals (leave requests and expense claims today), with Approve and Refuse beside
 * each. The listing and the decision both go through the module's own queue and service — the same ones the
 * `/v1` approvals endpoints use — so this card can offer nothing the panel or the service would refuse, and an
 * Administrator is never shown their own claim.
 *
 * Lazy for the reason DashboardSummaryCards gives: the queues are queries, and the page a person waits for
 * must not pay for them.
 */
class WaitingForYou extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.waiting-for-you';

    protected int|string|array $columnSpan = 1;

    /** Beside the payroll run, under the summary cards. */
    protected static ?int $sort = DashboardWidgets::HEADLINE + 1;

    protected static bool $isLazy = true;

    protected ?string $pollingInterval = null;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    public static function canView(): bool
    {
        return static::moduleIsAvailable() && DashboardApprovals::anyVisible(self::user());
    }

    /** @return array<int, array{key: string, source: string, id: int, initials: string, title: string, meta: string}> */
    public function items(): array
    {
        $user = self::user();

        return $user === null ? [] : DashboardApprovals::pendingFor($user);
    }

    public function viewAllUrl(): ?string
    {
        $user = self::user();

        return $user === null ? null : DashboardApprovals::viewAllUrl($user);
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->button()
            ->size('sm')
            ->action(function (array $arguments): void {
                $this->decide(fn (User $user) => DashboardApprovals::approve($user, (string) ($arguments['key'] ?? '')), __('Approved'));
            });
    }

    public function refuseAction(): Action
    {
        return Action::make('refuse')
            ->label(__('Refuse'))
            ->button()
            ->outlined()
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('Refuse this request?'))
            ->modalDescription(__('The person is told, with your reason.'))
            ->modalSubmitActionLabel(__('Refuse'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->decide(fn (User $user) => DashboardApprovals::refuse($user, (string) ($arguments['key'] ?? ''), (string) $data['reason']), __('Refused'));
            });
    }

    /** Run a decision and say how it went; the card re-renders from the queue afterwards. */
    private function decide(callable $decision, string $done): void
    {
        $user = self::user();

        if ($user === null) {
            return;
        }

        try {
            $decision($user);
        } catch (InvalidArgumentException|RuntimeException $e) {
            Notification::make()->title(__('Not done'))->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
