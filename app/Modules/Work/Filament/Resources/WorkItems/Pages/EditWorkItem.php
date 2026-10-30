<?php

namespace App\Modules\Work\Filament\Resources\WorkItems\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemBranch;
use App\Modules\Work\Models\WorkRepository;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Services\CodeHosts;
use App\Modules\Work\Services\WorkTime;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditWorkItem extends EditRecord
{
    protected static string $resource = WorkItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->createBranchAction(),
                $this->openPullRequestAction(),
                $this->linkPullRequestAction(),
            ])
                ->label('Development')
                ->icon('heroicon-o-code-bracket')
                ->button()
                ->color('gray')
                ->visible(fn (): bool => app(CodeHosts::class)->repositoriesFor($this->getRecord())->isNotEmpty()
                    && (auth()->user()?->can('update', $this->getRecord()) ?? false)),
            $this->logTimeAction(),
            DeleteAction::make(),
            HelpAction::make('work-items', 'Work Items: Help'),
        ];
    }

    /** `WRK-12-short-title` from the repository's default branch — §8.6. */
    private function createBranchAction(): Action
    {
        return Action::make('createBranch')
            ->label('Create branch')
            ->icon('heroicon-o-arrow-turn-down-right')
            ->schema(fn (): array => [
                Select::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => app(CodeHosts::class)->repositoriesFor($this->getRecord())->pluck('full_name', 'id')->all())
                    ->default(fn (): ?int => app(CodeHosts::class)->repositoriesFor($this->getRecord())->first()?->getKey())
                    ->required()
                    ->helperText(fn (): string => 'Named '.app(CodeHosts::class)->branchNameFor($this->getRecord()).', from the default branch.'),
            ])
            ->action(function (array $data): void {
                $repository = WorkRepository::query()->with('connection')->findOrFail($data['repository_id']);

                try {
                    $branch = app(CodeHosts::class)->createBranch($this->getRecord(), $repository, auth()->user());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('No branch')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($branch->name.' is on '.$repository->full_name.'.')->send();
                $this->refreshFormData([]);
            });
    }

    private function openPullRequestAction(): Action
    {
        return Action::make('openPullRequest')
            ->label('Open pull request')
            ->icon('heroicon-o-arrow-up-on-square')
            ->visible(fn (): bool => WorkItemBranch::query()->where('work_item_id', $this->getRecord()->getKey())->live()->exists())
            ->schema(fn (): array => [
                Select::make('branch_id')
                    ->label('From branch')
                    ->options(fn (): array => WorkItemBranch::query()->where('work_item_id', $this->getRecord()->getKey())->live()->pluck('name', 'id')->all())
                    ->required(),
                TextInput::make('title')->default(fn (): string => $this->getRecord()->display_label)->required()->maxLength(250),
                Textarea::make('body')->default(fn (): string => (string) $this->getRecord()->description)->rows(6)
                    ->helperText('The description, as Markdown. It reads the same on GitHub.'),
            ])
            ->action(function (array $data): void {
                $branch = WorkItemBranch::query()->with('repository.connection')->findOrFail($data['branch_id']);

                try {
                    $pr = app(CodeHosts::class)->openPullRequest($this->getRecord(), $branch, auth()->user(), $data['title'], $data['body'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not opened')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Pull request #'.$pr->number.' is open.')->send();
            });
    }

    private function linkPullRequestAction(): Action
    {
        return Action::make('linkPullRequest')
            ->label('Link existing pull request')
            ->icon('heroicon-o-link')
            ->schema([
                TextInput::make('url')->label('Pull request URL')->url()->required()->placeholder('https://github.com/org/repo/pull/42'),
            ])
            ->action(function (array $data): void {
                try {
                    $pr = app(CodeHosts::class)->linkPullRequest($this->getRecord(), (string) $data['url']);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not linked')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Pull request #'.$pr->number.' is linked.')->send();
            });
    }

    /**
     * Log time — docs/project-management-plan.md §9.
     *
     * Minutes, a date, a note and whether it bills, and the result is a timesheet entry and nothing
     * else: the week's timesheet, the payroll inputs and the invoice lines all see it as time,
     * because it is. Only when there is a timesheet to put it in, which is a licensing fact rather
     * than a setting.
     */
    private function logTimeAction(): Action
    {
        return Action::make('logTime')
            ->label('Log time')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->visible(fn (): bool => app(WorkTime::class)->isAvailable()
                && (auth()->user()?->can('update', $this->getRecord()) ?? false))
            ->modalHeading(fn (): string => 'Log time on '.$this->getRecord()->key)
            ->schema([
                TextInput::make('minutes')
                    ->label('Minutes')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->required()
                    ->helperText('Minutes, not hours — 90 is an hour and a half.'),

                DatePicker::make('date')->native(false)->required()->default(today()),

                Toggle::make('is_billable')
                    ->label('Billable')
                    ->default(true),

                Textarea::make('description')->label('Note')->rows(2),
            ])
            ->action(function (array $data): void {
                /** @var WorkItem $item */
                $item = $this->getRecord();

                try {
                    app(WorkTime::class)->log(
                        $item,
                        auth()->user(),
                        (int) $data['minutes'],
                        (string) $data['date'],
                        $data['description'] ?? null,
                        (bool) ($data['is_billable'] ?? true),
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Nothing was logged')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title(WorkTime::duration((int) $data['minutes']).' logged on '.$item->key)
                    ->body('It is on your timesheet as an ordinary entry.')
                    ->send();
            });
    }

    /**
     * A status changed on this form is still a transition.
     *
     * The form is one of four things that can move an item — the board, the API and a merged pull
     * request are the others — and all four go through `moveTo()`, which is what makes `started_at`
     * and `resolved_at` worth computing cycle time from. Saving the column directly here would be a
     * fifth door into the one thing §4.2 says has only one.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $target = $data['work_status_id'] ?? null;
        unset($data['work_status_id']);

        $record->fill($data);

        if ($target !== null && (int) $target !== (int) $record->work_status_id) {
            $status = WorkStatus::query()->findOrFail($target);

            try {
                $record->save();
                $record->moveTo($status, auth()->user(), $data['resolution'] ?? null);
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('It stayed where it was')->body($e->getMessage())->send();

                $record->refresh();

                return $record;
            }

            return $record;
        }

        $record->save();

        return $record;
    }
}
