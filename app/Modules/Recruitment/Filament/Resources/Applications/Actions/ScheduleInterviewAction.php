<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Services\Interviews;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Schedule an interview — docs/saas-launch-plan.md §14.6. Scheduling one reaches the
 * interview stage; the panel's feedback and the outcome live on the interview.
 */
class ScheduleInterviewAction
{
    public static function make(): Action
    {
        return Action::make('scheduleInterview')
            ->label(__('Schedule interview'))
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (Application $record): bool => in_array($record->stage, Application::OPEN_STAGES, true)
                && (auth()->user()?->can('ApplicantUpdate') ?? false))
            ->schema([
                DateTimePicker::make('scheduled_at')->label(__('When'))->native(false)->seconds(false)->required()->default(now()->addDay()->setTime(11, 0)),
                Select::make('interviewers')
                    ->label(__('Interviewers'))
                    ->multiple()
                    ->options(fn (): array => User::query()->where('status', 1)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('mode')->options(['in_person' => 'In person', 'phone' => 'Phone', 'video' => 'Video'])->default('in_person')->required(),
                TextInput::make('location')->maxLength(190)->placeholder('Head office, meeting room 2; or a link'),
            ])
            ->action(function (Application $record, array $data): void {
                try {
                    $interview = app(Interviews::class)->schedule($record, $data['scheduled_at'], (array) $data['interviewers'], $data['mode'], $data['location'] ?? null);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title('Round '.$interview->round.' scheduled.')
                    ->body('The panel has been told, and each has a feedback form on the interview.')
                    ->send();
            });
    }
}
